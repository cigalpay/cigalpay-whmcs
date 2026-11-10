<?php
/**
 * The checkouts this WHMCS created, per invoice (table mod_cigalpay_checkouts).
 * A notification is trusted only for one of them: anyone can create a CigalPay
 * checkout with this account ID and an invoice number, so the number alone
 * proves nothing.
 */

namespace CigalPay\Whmcs;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class Checkouts
{
    const TABLE = 'mod_cigalpay_checkouts';

    /** A checkout's price is locked for 30 minutes; reuse it for 25 so the customer still has time to pay. */
    const REUSE_SECONDS = 1500;

    public static function ensureTable()
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->integer('invoice_id')->index();
            $table->string('checkout_id', 191)->unique();
            $table->text('charge_link');
            $table->decimal('amount', 16, 2);
            $table->string('currency', 3);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /** A recent checkout for this invoice at this price, to send the customer back to. */
    public static function reusable($invoiceId, $amount, $currency)
    {
        self::ensureTable();
        return Capsule::table(self::TABLE)
            ->where('invoice_id', (int) $invoiceId)
            ->where('amount', round((float) $amount, 2))
            ->where('currency', $currency)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - self::REUSE_SECONDS))
            ->orderBy('id', 'desc')
            ->first();
    }

    public static function add($invoiceId, $checkoutId, $chargeLink, $amount, $currency)
    {
        self::ensureTable();
        Capsule::table(self::TABLE)->insert([
            'invoice_id' => (int) $invoiceId,
            'checkout_id' => (string) $checkoutId,
            'charge_link' => (string) $chargeLink,
            'amount' => round((float) $amount, 2),
            'currency' => (string) $currency,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** The checkout, when this WHMCS created it for that invoice; null otherwise. */
    public static function find($invoiceId, $checkoutId)
    {
        self::ensureTable();
        return Capsule::table(self::TABLE)
            ->where('invoice_id', (int) $invoiceId)
            ->where('checkout_id', (string) $checkoutId)
            ->first();
    }
}
