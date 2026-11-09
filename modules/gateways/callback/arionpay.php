<?php
/**
 * ArionPay callback for WHMCS.
 *
 * One public address for two things:
 *  - the pay button on the invoice page (a signed form post that starts or reuses a payment), and
 *  - ArionPay webhooks (signed with the store's webhook secret).
 * A WHMCS invoice can only be paid by a verified webhook or a signed invoice read made by this server.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../arionpay/lib.php';

$gatewayParams = getGatewayVariables(ARIONPAY_WHMCS_MODULE);

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    die('Module Not Activated');
}

if (isset($_POST['arionpay_pay'])) {
    arionpay_lib_handle_pay($gatewayParams, $_POST);
} else {
    arionpay_lib_handle_webhook($gatewayParams, (string) file_get_contents('php://input'), $_SERVER);
}
