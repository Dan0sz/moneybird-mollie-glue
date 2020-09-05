# Glue for EDD Moneybird and Easy Digital Downloads - Mollie Gateway

This plugin for WordPress provides the glue between [EDD Moneybird](https://really-simple-plugins.com/download/edd-moneybird/) (by Really Simple Plugins) and [Easy Digital Downloads - Mollie Gateway](https://wordpress.org/plugins/edd-mollie-gateway/) (by WP Overnight).

By default, EDD Moneybird only creates an invoice when a payment in Easy Digital Downloads is marked as 'complete'.

This plugin, triggers another action after the invoice is created. It [creates a payment](https://developer.moneybird.com/api/sales_invoices/#post_sales_invoices_sales_invoice_id_payments) using Moneybird's API with the transaction ID attached (provided by Mollie). This will automatically set the invoice's status to 'paid' in Moneybird. 