<?php
/**
 * @formatter:off
 * Plugin Name: Easy Digital Downloads - Glue for Moneybird & Mollie
 * Description: Automatically mark invoices as paid, when a payment through Mollie is received.
 * Version: 1.0.1
 * Author: Daan van den Bergh
 * Author URI: https://daan.dev
 * License: GPL2v2 or later
 * Text Domain: moneybird-mollie-glue
 * @formatter:on
 */

defined('ABSPATH') || exit;

define('MONEYBIRD_MOLLIE_GLUE_PLUGIN_DIR', plugin_dir_path(__FILE__));

class MoneybirdMollieGlue
{
    /** @var MoneybirdClient $client */
    private $client;

    /**
     * MoneybirdMollieGlue constructor.
     */
    public function __construct()
    {
        // @formatter:off
        // EDD Moneybird runs at priority 999, this needs to run after that.
        add_action('edd_complete_purchase', [$this, 'init'], 1000, 2);
        // @formatter:on
    }

    /**
     * @param $payment_id
     * @param $payment
     *
     * @throws Exception
     */
    public function init($payment_id, $payment)
    {
        $client_data = [
            'client_id'          => edd_get_option('emb_mb_clientID'),
            'client_secret'      => edd_get_option('emb_mb_clientSecret'),
            'admin_name'         => edd_get_option('emb_mb_clientName'),
            'admin_id'           => get_option('mb_admin_id'),
            'redirect_uri'       => get_option('mb_redirect_uri'),
            'authorization_code' => get_option('mb_authorization_code'),
            'access_token'       => get_option('mb_access_token'),
            'refresh_token'      => get_option('mb_refresh_token')
        ];

        if (!class_exists('MoneyBirdClient')) {
            require_once(MONEYBIRD_MOLLIE_GLUE_PLUGIN_DIR . 'includes/class-moneybird-client.php');
        }

        $this->client     = new MoneybirdClient($client_data);
        $sales_invoice_id = get_post_meta($payment_id, "_emb_payment_synced", true);

        if (!$sales_invoice_id) {
            return false;
        }

        return $this->client->create_payment(
            $sales_invoice_id,
            $payment->transaction_id,
            $payment
        );
    }
}

new MoneybirdMollieGlue();
