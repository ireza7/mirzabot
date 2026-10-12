<?php

ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../panels/Api/Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../keyboard.php';
require_once __DIR__ . '/../jdf.php';
require __DIR__ . '/../vendor/autoload.php';

$textbotlang = languagechange();

$order_id = trim((string) ($_GET['order'] ?? $_GET['order_id'] ?? ''));

function rentopay_finish(bool $ok, string $title, string $detail): never
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:48px 20px">'
        . '<div style="font-size:44px">' . ($ok ? '&#10003;' : '&#8986;') . '</div>'
        . '<h2 style="color:' . ($ok ? '#2ecc71' : '#3498db') . '">'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p style="color:#666">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</body></html>';
    exit;
}

$failedTitle = $textbotlang['paymentGateway']['statusFailed'] ?? 'پرداخت ناموفق';
$successTitle = $textbotlang['paymentGateway']['statusSuccess'] ?? 'پرداخت موفق';
$waitingTitle = 'در انتظار تأیید';

if ($order_id === '') {
    rentopay_finish(false, $failedTitle, 'شناسه سفارش ارسال نشد.');
}

$payment = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$payment) {
    rentopay_finish(false, $failedTitle, 'این سفارش پیدا نشد.');
}

if ($payment['payment_Status'] === 'paid') {
    rentopay_finish(true, $successTitle, 'پرداخت شما با موفقیت تایید شد.');
}

if ($payment['Payment_Method'] !== 'rentopay') {
    rentopay_finish(false, $failedTitle, 'این سفارش مربوط به رنتوپی نیست.');
}

// Try active inquiry/verify if token is available
$token = $payment['dec_not_confirmed'] ?? '';
if (!empty($token) && function_exists('verifyPayRentopay')) {
    $verifyRes = verifyPayRentopay($token);
    if (isset($verifyRes['verified']) && $verifyRes['verified'] === true && ($verifyRes['status'] ?? '') === 'PAID') {
        $amountRials = (int) ($verifyRes['amount_rials'] ?? 0);
        $billedTomans = (int) $payment['price'];
        $billedRials = $billedTomans * 10;
        if ($amountRials >= $billedRials && claimPaymentPaid($order_id)) {
            try {
                DirectPayment($order_id, __DIR__ . "/../images.jpg");
            } catch (Throwable $e) {
                error_log("rentopay callback: DirectPayment failed for {$order_id}: " . $e->getMessage());
            }

            // Cashback
            $cashback = intval(getPaySettingValue('chashbackrentopay', '0'));
            if ($cashback > 0) {
                $buyer = select("user", "*", "id", $payment['id_user'], "select");
                if ($buyer) {
                    $reward = intval($billedTomans * $cashback / 100);
                    if ($reward > 0) {
                        update("user", "Balance", intval($buyer['Balance']) + $reward, "id", $payment['id_user']);
                        $text_gift = sprintf($textbotlang['paymentGateway']['giftReport'], $reward);
                        sendmessage($payment['id_user'], $text_gift, null, 'HTML');
                    }
                }
            }

            // Report
            $setting = select("setting", "*");
            $paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'] ?? null;
            if (!empty($setting['Channel_Report']) && $paymentreports) {
                $buyer = select("user", "*", "id", $payment['id_user'], "select");
                $text_report = sprintf(
                    $textbotlang['paymentGateway']['reportRentopay'],
                    $buyer['username'] ?? '-',
                    $payment['id_user'],
                    number_format($billedTomans),
                    $order_id,
                    $token
                );
                telegram('sendmessage', [
                    'chat_id' => $setting['Channel_Report'],
                    'message_thread_id' => $paymentreports,
                    'text' => $text_report,
                    'parse_mode' => "HTML"
                ]);
            }

            rentopay_finish(true, $successTitle, 'پرداخت شما با موفقیت تایید شد.');
        }
    }
}

rentopay_finish(false, $waitingTitle, 'واریز شما ثبت شد و در انتظار تأیید درگاه رنتوپی است. پس از تایید، سرویس به صورت خودکار فعال خواهد شد.');
