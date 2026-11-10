<?php
/**
 * CigalPay for WHMCS: customers pay invoices in crypto (Bitcoin, USDT,
 * Ethereum, Solana and more) on the CigalPay checkout. The invoice is marked
 * paid from CigalPay's signed notification (callback/cigalpay.php), and
 * refunds go out in the coin the customer paid.
 *
 * @see https://developers.whmcs.com/payment-gateways/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/cigalpay/lib/Api.php';
require_once __DIR__ . '/cigalpay/lib/Checkouts.php';

use CigalPay\Whmcs\Api;
use CigalPay\Whmcs\Checkouts;

function cigalpay_MetaData()
{
    return [
        'DisplayName' => 'CigalPay (crypto)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function cigalpay_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Crypto (Bitcoin, USDT, Ethereum…)',
        ],
        'accountId' => [
            'FriendlyName' => 'Account ID',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'CigalPay dashboard → Settings → API credentials.',
        ],
        'ipnSecret' => [
            'FriendlyName' => 'Notification signing secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Same page. Used to check that payment notifications come from CigalPay.',
        ],
        'apiKey' => [
            'FriendlyName' => 'API key (for refunds)',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Optional. Lets you refund in crypto from WHMCS. Create it in Settings → API credentials.',
        ],
    ];
}

/** CigalPay's checkout languages, from the client's WHMCS language. */
function cigalpay_lang(array $params)
{
    $language = strtolower((string) (isset($params['clientdetails']['language']) ? $params['clientdetails']['language'] : ''));
    $map = ['french' => 'fr', 'german' => 'de', 'spanish' => 'es'];
    return isset($map[$language]) ? $map[$language] : 'en';
}

function cigalpay_message($text)
{
    return '<p class="cigalpay-note">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
}

/**
 * The "Pay" button on the invoice. Creates a CigalPay checkout for the invoice
 * (or reuses one made in the last 25 minutes at the same price) and links to it.
 */
function cigalpay_link($params)
{
    $invoiceId = (int) $params['invoiceid'];
    $amount = round((float) $params['amount'], 2);
    $currency = strtoupper((string) $params['currency']);
    $accountId = trim((string) $params['accountId']);

    if ($accountId === '' || trim((string) $params['ipnSecret']) === '') {
        return cigalpay_message('Crypto payment is not set up yet.');
    }
    if (!in_array($currency, Api::CURRENCIES, true)) {
        return cigalpay_message('Crypto payment is available for invoices in USD or EUR.');
    }
    if ($amount <= 0 || $amount > Api::MAX_AMOUNT) {
        return cigalpay_message('Crypto payment is available for invoices up to 5,000 ' . $currency . '.');
    }

    try {
        $existing = Checkouts::reusable($invoiceId, $amount, $currency);
        if ($existing) {
            $link = $existing->charge_link;
        } else {
            $checkout = Api::createCheckout([
                'accountId' => $accountId,
                'amount' => $amount,
                'currency' => $currency,
                'extraId' => (string) $invoiceId,
                'description' => trim('Invoice #' . $invoiceId . ' - ' . (string) $params['companyname'], ' -'),
                'lang' => cigalpay_lang($params),
                'callback' => (string) $params['returnurl'],
                'ipn' => rtrim((string) $params['systemurl'], '/') . '/modules/gateways/callback/cigalpay.php',
            ]);
            Checkouts::add($invoiceId, $checkout['id'], $checkout['chargeLink'], $amount, $currency);
            $link = $checkout['chargeLink'];
        }
    } catch (\Throwable $e) {
        logModuleCall('cigalpay', 'createCheckout', ['invoice' => $invoiceId, 'amount' => $amount, 'currency' => $currency], $e->getMessage());
        return cigalpay_message('Crypto payment is unavailable right now. Please try again in a moment or choose another payment method.');
    }

    $label = isset($params['langpaynow']) && $params['langpaynow'] !== '' ? $params['langpaynow'] : 'Pay with crypto';
    return '<a class="btn btn-success" href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

/**
 * Refunds in the coin the customer paid, from the CigalPay balance. The client
 * gets an email with a link to enter their address; CigalPay sends the coin
 * once the refund is reviewed.
 */
function cigalpay_refund($params)
{
    $apiKey = trim((string) $params['apiKey']);
    if ($apiKey === '') {
        return ['status' => 'declined', 'rawdata' => 'Add your CigalPay API key in the gateway settings to refund in crypto.'];
    }

    $body = [
        'paymentId' => (string) $params['transid'],
        'checkoutAmount' => round((float) $params['amount'], 2),
        'reason' => 'WHMCS refund for invoice #' . (int) $params['invoiceid'],
    ];
    $email = isset($params['clientdetails']['email']) ? (string) $params['clientdetails']['email'] : '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $body['customerEmail'] = $email;
    }

    try {
        $res = Api::createRefund($apiKey, $body);
    } catch (\Throwable $e) {
        return ['status' => 'declined', 'rawdata' => 'CigalPay: ' . $e->getMessage()];
    }

    return [
        'status' => 'success',
        'transid' => (string) $res['refund']['id'],
        'fees' => 0,
        'rawdata' => 'Refund started. The client got a link to enter their address: '
            . (isset($res['link']) ? $res['link'] : '') . '. CigalPay sends it once reviewed.',
    ];
}
