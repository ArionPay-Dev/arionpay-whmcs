<?php
/**
 * ArionPay gateway module for WHMCS (ArionPay API v2).
 *
 * Module name "arionpay": this file, modules/gateways/callback/arionpay.php and the helper
 * folder modules/gateways/arionpay/ belong together. Existing installs upgrade in place.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/arionpay/lib.php';

/**
 * Module metadata.
 *
 * @return array
 */
function arionpay_MetaData()
{
    return array(
        'DisplayName' => 'ArionPay Crypto',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Admin settings. The names apiKey, apiSecret and baseUrl are the ones the old module saved,
 * so an existing install keeps its keys when the files are replaced.
 *
 * @return array
 */
function arionpay_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'ArionPay (Crypto)',
        ),
        'apiKey' => array(
            'FriendlyName' => 'Public Key',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'From your store in the ArionPay dashboard. It starts with pk_.',
        ),
        'apiSecret' => array(
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'The API secret of your store in the ArionPay dashboard. It starts with sk_.',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Webhook Secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'The signing secret on the Webhooks page of the ArionPay dashboard. It starts with whsec_. '
                . 'Nothing else to paste: every invoice tells ArionPay to send its updates to <code>' . htmlspecialchars(arionpay_lib_callback_url(), ENT_QUOTES, 'UTF-8') . '</code> '
                . '(you can also paste that address on the ArionPay Webhooks page to use "Send test webhook" there).',
        ),
        'baseUrl' => array(
            'FriendlyName' => 'API Base URL',
            'Type' => 'text',
            'Size' => '60',
            'Default' => ARIONPAY_WHMCS_DEFAULT_API,
            'Description' => 'Leave this as it is unless ArionPay tells you to change it. It must be https.',
        ),
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Create test invoices (no real money). Turn this off before you take real payments.',
        ),
    );
}

/**
 * The pay button on the WHMCS invoice page. Nothing is sent to ArionPay until the customer presses it:
 * WHMCS builds this every time the invoice page is shown.
 *
 * @param array $params WHMCS gateway parameters.
 * @return string HTML
 */
function arionpay_link($params)
{
    if (!arionpay_lib_is_configured($params)) {
        return '<div class="alert alert-warning">Crypto payment is not available right now. Please contact us.</div>';
    }

    $invoiceId = (int) $params['invoiceid'];
    $returnUrl = (string) $params['returnurl'];
    $expires = time() + ARIONPAY_WHMCS_PAY_TOKEN_TTL;
    $token = arionpay_lib_pay_token((string) $params['apiSecret'], $invoiceId, $expires, $returnUrl);
    $action = rtrim((string) $params['systemurl'], '/') . '/modules/gateways/callback/' . ARIONPAY_WHMCS_MODULE . '.php';
    $label = !empty($params['langpaynow']) ? (string) $params['langpaynow'] : 'Pay with Crypto';

    $e = function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    $html = '<form action="' . $e($action) . '" method="post">'
        . '<input type="hidden" name="arionpay_pay" value="1" />'
        . '<input type="hidden" name="invoice_id" value="' . $e($invoiceId) . '" />'
        . '<input type="hidden" name="expires" value="' . $e($expires) . '" />'
        . '<input type="hidden" name="return_url" value="' . $e($returnUrl) . '" />'
        . '<input type="hidden" name="pay_token" value="' . $e($token) . '" />'
        . '<input type="submit" class="btn btn-primary" value="' . $e($label) . '" />'
        . '</form>';

    if (arionpay_lib_is_test_mode($params)) {
        $html .= '<p class="text-muted"><small>Test mode is on: no real money is taken.</small></p>';
    }
    return $html;
}
