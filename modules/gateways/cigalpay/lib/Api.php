<?php
/**
 * The CigalPay calls the WHMCS module needs, over cURL. Same requests and
 * signature check as the CigalPay PHP SDK (cigalpay/cigalpay-php-sdk) and the
 * WooCommerce plugin.
 */

namespace CigalPay\Whmcs;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/Webhook.php';

final class Api
{
    const VERSION = '1.0.0';

    /** Payment statuses sent in notifications. CONFIRMED and FINISHED mean paid. */
    const STATUS_WAITING = 'WAITING';
    const STATUS_CONFIRMING = 'CONFIRMING';
    const STATUS_CONFIRMED = 'CONFIRMED';
    const STATUS_FINISHED = 'FINISHED';
    const STATUS_PARTIALLY_PAID = 'PARTIALLY_PAID';
    const STATUS_EXPIRED = 'EXPIRED';
    const STATUS_FAILED = 'FAILED';

    /** Currencies a CigalPay checkout can be priced in, and its largest payment. */
    const CURRENCIES = ['USD', 'EUR'];
    const MAX_AMOUNT = 5000;

    /** For tests: define these constants in WHMCS's configuration.php to use other servers. */
    private static function checkoutUrl()
    {
        return rtrim(defined('CIGALPAY_CHECKOUT_URL') ? CIGALPAY_CHECKOUT_URL : 'https://coins-api.cigalpay.com', '/');
    }

    private static function merchantApiUrl()
    {
        return rtrim(defined('CIGALPAY_MERCHANT_API_URL') ? CIGALPAY_MERCHANT_API_URL : 'https://merchant-api.cigalpay.com', '/');
    }

    /**
     * Creates a hosted checkout.
     *
     * @param array $checkout accountId, amount, currency, extraId, description, lang, callback, ipn
     * @return array the reply (id, chargeLink, ...)
     * @throws \RuntimeException with CigalPay's reason
     */
    public static function createCheckout(array $checkout)
    {
        $data = self::post(self::checkoutUrl() . '/api/pre-invoice', $checkout, [], 15);
        if (empty($data['id']) || empty($data['chargeLink'])) {
            throw new \RuntimeException('unexpected reply from CigalPay');
        }
        return $data;
    }

    /**
     * Refunds a paid payment from the CigalPay balance: the customer gets a link
     * to enter their address, and CigalPay sends the coin once it's reviewed.
     *
     * @param array $refund paymentId, checkoutAmount (invoice currency), customerEmail, reason
     * @return array the reply (refund, link)
     * @throws \RuntimeException with CigalPay's reason
     */
    public static function createRefund($apiKey, array $refund)
    {
        $data = self::post(self::merchantApiUrl() . '/api/refunds', $refund, ['cigalpay-api-key: ' . $apiKey], 20);
        if (empty($data['refund']['id'])) {
            throw new \RuntimeException('unexpected reply from CigalPay');
        }
        return $data;
    }

    /** Checks a notification's x-cigalpay-sig header against the raw body, as received. */
    public static function verifyNotification($rawBody, $signature, $secret)
    {
        if (!is_string($rawBody) || !is_string($signature) || $signature === '' || !is_string($secret) || $secret === '') {
            return false;
        }
        $signed = \Cigalpay\Webhook::canonical($rawBody);
        if ($signed === null) {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $signed, $secret), $signature);
    }

    public static function isPaid($status)
    {
        return $status === self::STATUS_CONFIRMED || $status === self::STATUS_FINISHED;
    }

    private static function post($url, array $body, array $headers, $timeout)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: cigalpay-whmcs/' . self::VERSION],
                $headers
            ),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('CigalPay unreachable: ' . $error);
        }
        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            $message = is_array($data) && isset($data['error']) && is_string($data['error'])
                ? $data['error']
                : (is_array($data) && isset($data['message']) && is_string($data['message']) ? $data['message'] : 'unexpected reply');
            throw new \RuntimeException(sprintf('HTTP %d: %s', $status, $message));
        }
        return $data;
    }
}
