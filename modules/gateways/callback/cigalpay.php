<?php
/**
 * CigalPay's payment notification (POST /modules/gateways/callback/cigalpay.php).
 *
 * An invoice is marked paid only when the signature checks out, the payment is
 * for a checkout this WHMCS created for that invoice, at that checkout's price,
 * and its status is CONFIRMED or FINISHED. Each payment is applied once.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../cigalpay/lib/Api.php';
require_once __DIR__ . '/../cigalpay/lib/Checkouts.php';

use CigalPay\Whmcs\Api;
use CigalPay\Whmcs\Checkouts;

$gatewayModuleName = 'cigalpay';
$gateway = getGatewayVariables($gatewayModuleName);
if (empty($gateway['type'])) {
    http_response_code(503);
    die('Module not activated');
}

$raw = (string) file_get_contents('php://input');
$signature = isset($_SERVER['HTTP_X_CIGALPAY_SIG']) ? (string) $_SERVER['HTTP_X_CIGALPAY_SIG'] : '';

if (!Api::verifyNotification($raw, $signature, (string) $gateway['ipnSecret'])) {
    logTransaction($gateway['name'], ['signature' => $signature !== '' ? 'present' : 'missing'], 'Rejected: bad or missing signature. Check the notification signing secret.');
    http_response_code(401);
    die('Bad signature');
}

$p = json_decode($raw, true);
if (!is_array($p)) {
    http_response_code(400);
    die('Bad body');
}

$paymentId = isset($p['id']) ? (string) $p['id'] : '';
$status = isset($p['status']) ? (string) $p['status'] : '';
$checkoutId = isset($p['checkoutId']) ? (string) $p['checkoutId'] : '';
$invoiceRef = isset($p['extraId']) ? (string) $p['extraId'] : '';

// Not one of this WHMCS's invoices (or a test notification): acknowledge and stop.
if (!ctype_digit($invoiceRef) || $paymentId === '') {
    logTransaction($gateway['name'], $p, 'Ignored: no invoice reference');
    die('OK');
}
$invoiceId = checkCbInvoiceID((int) $invoiceRef, $gateway['name']);

$checkout = $checkoutId !== '' ? Checkouts::find($invoiceId, $checkoutId) : null;
if (!$checkout) {
    // Anyone can create a checkout with this account and an invoice number; only the ones this WHMCS made count.
    logTransaction($gateway['name'], $p, 'Ignored: not a checkout this WHMCS created for the invoice');
    die('OK');
}

$coin = (isset($p['amount']) ? $p['amount'] : '') . ' ' . (isset($p['currency']) ? $p['currency'] : '');

if (!Api::isPaid($status)) {
    $labels = [
        Api::STATUS_WAITING => 'Customer chose to pay ' . $coin,
        Api::STATUS_CONFIRMING => 'Payment of ' . $coin . ' detected, waiting for network confirmations',
        Api::STATUS_PARTIALLY_PAID => 'Partly paid: ' . (isset($p['amountPaid']) ? $p['amountPaid'] : '?') . ' of ' . $coin . '. The customer can send the rest.',
        Api::STATUS_EXPIRED => 'Expired without being paid',
        Api::STATUS_FAILED => 'Failed',
    ];
    logTransaction($gateway['name'], $p, isset($labels[$status]) ? $labels[$status] : ('Status ' . $status));
    die('OK');
}

// The price paid must be the checkout's price, in its currency.
$paidAmount = isset($p['checkoutAmount']) ? (float) $p['checkoutAmount'] : -1;
$paidCurrency = isset($p['checkoutCurrency']) ? strtoupper((string) $p['checkoutCurrency']) : '';
if ($paidCurrency !== $checkout->currency || abs($paidAmount - (float) $checkout->amount) > 0.01) {
    logTransaction($gateway['name'], $p, sprintf('Not applied: paid %s %s, but the checkout was for %s %s. Check it manually.', $paidAmount, $paidCurrency, $checkout->amount, $checkout->currency));
    die('OK');
}

// CONFIRMED then FINISHED both mean paid: the payment is applied once (checkCbTransID stops a repeat).
checkCbTransID($paymentId);

$invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
if (isset($invoice['status']) && $invoice['status'] === 'Paid') {
    logTransaction($gateway['name'], $p, 'Second payment for an already paid invoice (payment ' . $paymentId . '). Refund the client if it was a mistake.');
    die('OK');
}

$note = 'Paid ' . $coin;
if (!empty($p['swapFromCurrency'])) {
    $note .= ' (customer paid ' . (isset($p['swapFromAmount']) ? $p['swapFromAmount'] : '') . ' ' . $p['swapFromCurrency'] . ', converted automatically)';
}
addInvoicePayment($invoiceId, $paymentId, $paidAmount, 0, $gatewayModuleName);
logTransaction($gateway['name'], $p, 'Success: ' . $note);
die('OK');
