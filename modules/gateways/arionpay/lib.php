<?php
/**
 * Helpers for the ArionPay WHMCS module: signing, API calls, the pay button and webhook handling.
 *
 * Backend reference (ArionPay v2):
 *  - signed API requests: http/routes/apiV1.ts requireHmac
 *  - webhook signature and payload: services/webhooks.ts
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

const ARIONPAY_WHMCS_MODULE = 'arionpay';
const ARIONPAY_WHMCS_VERSION = '2.0.0';
const ARIONPAY_WHMCS_DEFAULT_API = 'https://merchant.arionpay.com';
const ARIONPAY_WHMCS_TABLE = 'mod_arionpay_invoices';
const ARIONPAY_WHMCS_SIGNATURE_TOLERANCE = 300;
const ARIONPAY_WHMCS_PAY_TOKEN_TTL = 43200;
const ARIONPAY_WHMCS_MAX_BODY_BYTES = 262144;
const ARIONPAY_WHMCS_LOCK_WAIT_SECONDS = 5;

/** Raised for any problem talking to the ArionPay API. Messages never contain secrets. */
class ArionPayWhmcsApiException extends RuntimeException
{
    /** @var string */
    public $apiCode;

    public function __construct($message, $httpStatus = 0, $apiCode = '')
    {
        parent::__construct($message, (int) $httpStatus);
        $this->apiCode = (string) $apiCode;
    }
}

// ---------------------------------------------------------------- settings

function arionpay_lib_is_test_mode(array $params)
{
    return isset($params['testMode']) && $params['testMode'] === 'on';
}

function arionpay_lib_is_configured(array $params)
{
    foreach (array('apiKey', 'apiSecret', 'webhookSecret') as $key) {
        if (empty($params[$key])) {
            return false;
        }
    }
    return true;
}

/** The API base URL, or null when it is not an https address (the local machine may use http, for development). */
function arionpay_lib_base_url(array $params)
{
    $url = rtrim(trim(isset($params['baseUrl']) ? (string) $params['baseUrl'] : ''), '/');
    if ($url === '') {
        $url = ARIONPAY_WHMCS_DEFAULT_API;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !empty($parts['path']) || !empty($parts['query'])) {
        return null;
    }
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
    $local = in_array(strtolower($parts['host']), array('localhost', '127.0.0.1', '[::1]'), true);
    return ($scheme === 'https' || ($scheme === 'http' && $local)) ? $url : null;
}

/** The address to paste into ArionPay, shown in the admin settings. */
function arionpay_lib_callback_url()
{
    $base = '';
    if (class_exists('WHMCS\\Config\\Setting')) {
        $base = (string) \WHMCS\Config\Setting::getValue('SystemURL');
    }
    $base = $base !== '' ? rtrim($base, '/') : 'https://YOUR-WHMCS-ADDRESS';
    return $base . '/modules/gateways/callback/' . ARIONPAY_WHMCS_MODULE . '.php';
}

// ---------------------------------------------------------------- signatures

/** x-signature for a signed API request. */
function arionpay_lib_sign_request($secret, $timestamp, $method, $pathAndQuery, $rawBody)
{
    $data = strtoupper((string) $method) === 'GET'
        ? $timestamp . '.GET ' . $pathAndQuery
        : $timestamp . '.' . $rawBody;
    return hash_hmac('sha256', $data, (string) $secret);
}

/** Read "t=<unix>,v1=<hex>"; null when malformed. */
function arionpay_lib_parse_signature($header)
{
    $values = array();
    foreach (explode(',', (string) $header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2) {
            $values[$pair[0]] = $pair[1];
        }
    }
    if (!isset($values['t'], $values['v1']) || !ctype_digit($values['t']) || !preg_match('/^[0-9a-fA-F]{64}$/', $values['v1'])) {
        return null;
    }
    return array('t' => (int) $values['t'], 'v1' => strtolower($values['v1']));
}

/** Timing-safe webhook check with a timestamp window. Fails closed. */
function arionpay_lib_verify_webhook($secret, $rawBody, $header, $now = null)
{
    if ((string) $secret === '') {
        return false;
    }
    $parsed = arionpay_lib_parse_signature($header);
    if ($parsed === null) {
        return false;
    }
    $now = $now === null ? time() : (int) $now;
    if (abs($now - $parsed['t']) > ARIONPAY_WHMCS_SIGNATURE_TOLERANCE) {
        return false;
    }
    $expected = hash_hmac('sha256', $parsed['t'] . '.' . $rawBody, (string) $secret);
    return hash_equals($expected, $parsed['v1']);
}

/** Token that proves a pay-button form was built by this WHMCS for this invoice. */
function arionpay_lib_pay_token($secret, $invoiceId, $expires, $returnUrl)
{
    return hash_hmac('sha256', 'arionpay-whmcs-pay|' . (int) $invoiceId . '|' . (int) $expires . '|' . $returnUrl, (string) $secret);
}

// ---------------------------------------------------------------- logging (no secrets, ever)

/** Replace anything secret-looking before it can reach the WHMCS gateway log. */
function arionpay_lib_redact($value)
{
    if (is_array($value)) {
        $out = array();
        foreach ($value as $k => $v) {
            $out[$k] = (is_string($k) && preg_match('/secret|signature|apikey|api_key|token|password|authorization|customeremail/i', $k))
                ? '[redacted]'
                : arionpay_lib_redact($v);
        }
        return $out;
    }
    if (is_string($value)) {
        return preg_replace('/\b(pk|sk|whsec)_[A-Za-z0-9_\-]{6,}/', '$1_[redacted]', $value);
    }
    return $value;
}

function arionpay_lib_log(array $gatewayParams, $data, $status)
{
    logTransaction($gatewayParams['name'], arionpay_lib_redact($data), $status);
}

// ---------------------------------------------------------------- ArionPay API

/**
 * Send one signed request. The backend accepts each signature once, so a retry is signed again.
 *
 * @return array decoded response
 * @throws ArionPayWhmcsApiException
 */
function arionpay_lib_api(array $params, $method, $path, $body = null)
{
    $base = arionpay_lib_base_url($params);
    if ($base === null) {
        throw new ArionPayWhmcsApiException('The API Base URL setting is not a valid https address.');
    }
    $rawBody = '';
    if ($body !== null) {
        $rawBody = json_encode($body);
        if ($rawBody === false) {
            throw new ArionPayWhmcsApiException('Could not encode the request.');
        }
    }

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $timestamp = time();
        $headers = array(
            'Accept: application/json',
            'x-api-key: ' . $params['apiKey'],
            'x-timestamp: ' . $timestamp,
            'x-signature: ' . arionpay_lib_sign_request($params['apiSecret'], $timestamp, $method, $path, $rawBody),
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($base . $path);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'ArionPay-WHMCS/' . ARIONPAY_WHMCS_VERSION,
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($response === false) {
            throw new ArionPayWhmcsApiException('Could not reach ArionPay: ' . $error);
        }
        $decoded = json_decode((string) $response, true);
        $decoded = is_array($decoded) ? $decoded : array();
        if ($status >= 200 && $status < 300) {
            return $decoded;
        }
        $message = isset($decoded['error']) && is_scalar($decoded['error']) ? (string) $decoded['error'] : 'HTTP ' . $status;
        // Two identical signed requests in the same second have the same signature; only the first is accepted.
        if ($status === 401 && $attempt === 1 && stripos($message, 'already used') !== false) {
            sleep(1);
            continue;
        }
        $code = isset($decoded['code']) && is_scalar($decoded['code']) ? (string) $decoded['code'] : '';
        throw new ArionPayWhmcsApiException('ArionPay API error: ' . $message, $status, $code);
    }
    throw new ArionPayWhmcsApiException('ArionPay API request failed.');
}

// ---------------------------------------------------------------- WHMCS data

/**
 * The WHMCS invoice as far as a payment cares. Currency comes from the client, as WHMCS has no per-invoice currency.
 *
 * @return array|null
 */
function arionpay_lib_whmcs_invoice($invoiceId)
{
    $invoice = localAPI('GetInvoice', array('invoiceid' => (int) $invoiceId));
    if (!is_array($invoice) || !isset($invoice['result']) || $invoice['result'] !== 'success') {
        return null;
    }
    $client = localAPI('GetClientsDetails', array('clientid' => (int) $invoice['userid'], 'stats' => false));
    $currency = '';
    if (is_array($client)) {
        if (isset($client['client']['currency_code'])) {
            $currency = (string) $client['client']['currency_code'];
        } elseif (isset($client['currency_code'])) {
            $currency = (string) $client['currency_code'];
        }
    }
    return array(
        'id' => (int) $invoiceId,
        'status' => (string) $invoice['status'],
        'date' => isset($invoice['date']) ? (string) $invoice['date'] : '',
        'balance' => isset($invoice['balance']) ? (string) $invoice['balance'] : '0',
        'currency' => strtoupper($currency),
    );
}

function arionpay_lib_is_payable($status)
{
    return in_array($status, array('Unpaid', 'Payment Pending'), true);
}

/** Exact to the cent. Not a tolerance: an amount that differs at all is a mismatch. */
function arionpay_lib_same_amount($a, $b)
{
    return is_numeric($a) && is_numeric($b) && (int) round((float) $a * 100) === (int) round((float) $b * 100);
}

// ---------------------------------------------------------------- the module's table

/** One row per ArionPay invoice made for a WHMCS invoice. The newest row is the one offered to the customer. */
function arionpay_lib_ensure_table()
{
    if (Capsule::schema()->hasTable(ARIONPAY_WHMCS_TABLE)) {
        return;
    }
    try {
        Capsule::schema()->create(ARIONPAY_WHMCS_TABLE, function ($table) {
            $table->increments('id');
            $table->unsignedInteger('whmcs_invoice_id')->index();
            $table->string('arionpay_invoice_id', 24)->unique();
            $table->string('hosted_url', 500);
            $table->decimal('amount', 16, 2);
            $table->string('currency', 5);
            $table->string('mode', 4);
            $table->unsignedInteger('attempt');
            $table->dateTime('created_at');
        });
    } catch (\Exception $e) {
        // Two first requests at once: the other one created it.
        if (!Capsule::schema()->hasTable(ARIONPAY_WHMCS_TABLE)) {
            throw $e;
        }
    }
}

function arionpay_lib_latest_row($whmcsInvoiceId)
{
    return Capsule::table(ARIONPAY_WHMCS_TABLE)->where('whmcs_invoice_id', (int) $whmcsInvoiceId)->orderBy('id', 'desc')->first();
}

function arionpay_lib_row_by_arionpay_id($arionpayInvoiceId)
{
    return Capsule::table(ARIONPAY_WHMCS_TABLE)->where('arionpay_invoice_id', (string) $arionpayInvoiceId)->first();
}

// ---------------------------------------------------------------- locking

/**
 * Per-invoice lock (MySQL GET_LOCK). It belongs to the DB connection, so it is released even if the script dies,
 * for example inside checkCbTransID(). A failure to take the lock means "busy": nothing is paid and ArionPay retries.
 */
function arionpay_lib_lock($whmcsInvoiceId)
{
    try {
        $row = Capsule::connection()->selectOne(
            'SELECT GET_LOCK(?, ?) AS arionpay_lock',
            array('arionpay_invoice_' . (int) $whmcsInvoiceId, ARIONPAY_WHMCS_LOCK_WAIT_SECONDS)
        );
    } catch (\Exception $e) {
        return false;
    }
    $value = is_array($row) ? ($row['arionpay_lock'] ?? null) : (is_object($row) ? ($row->arionpay_lock ?? null) : null);
    return (int) $value === 1;
}

function arionpay_lib_unlock($whmcsInvoiceId)
{
    try {
        Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS arionpay_lock', array('arionpay_invoice_' . (int) $whmcsInvoiceId));
    } catch (\Exception $e) {
        // The lock goes away with the connection anyway.
    }
}

// ---------------------------------------------------------------- applying a paid invoice

/**
 * Add the payment. Only the caller's verified data gets here: a checked webhook or a signed invoice read.
 * The module never compares crypto amounts: ArionPay alone decides whether an invoice is paid.
 *
 * @return string outcome for the caller
 */
function arionpay_lib_apply_paid(array $gatewayParams, array $invoice, array $row)
{
    $whmcsInvoiceId = (int) $row['whmcs_invoice_id'];
    $arionpayInvoiceId = (string) $invoice['id'];
    $logData = arionpay_lib_log_fields($invoice);

    // Stops here if WHMCS does not know the invoice.
    checkCbInvoiceID($whmcsInvoiceId, $gatewayParams['name']);
    // Stops here on a replay: this transaction is already recorded.
    checkCbTransID($arionpayInvoiceId);

    $whmcs = arionpay_lib_whmcs_invoice($whmcsInvoiceId);
    if ($whmcs === null) {
        arionpay_lib_log($gatewayParams, $logData, 'ArionPay invoice is paid but the WHMCS invoice could not be read. NOT paid.');
        return 'not_paid_unknown_invoice';
    }

    if (!arionpay_lib_is_payable($whmcs['status'])) {
        arionpay_lib_log($gatewayParams, $logData, 'ArionPay reports this invoice paid, but the WHMCS invoice is ' . $whmcs['status'] . '. NOT paid again. Possible double payment: check your wallet.');
        return 'not_paid_status';
    }

    $invoiceCurrency = strtoupper(isset($invoice['currency']) && is_scalar($invoice['currency']) ? (string) $invoice['currency'] : '');
    if ($invoiceCurrency === '' || $whmcs['currency'] === '' || $invoiceCurrency !== $whmcs['currency']) {
        arionpay_lib_log($gatewayParams, $logData, 'Currency mismatch: ArionPay invoice is ' . $invoiceCurrency . ', WHMCS invoice is ' . $whmcs['currency'] . '. NOT paid.');
        return 'not_paid_currency';
    }

    $amount = isset($invoice['amountFiat']) ? $invoice['amountFiat'] : null;
    if (!arionpay_lib_same_amount($amount, $whmcs['balance'])) {
        arionpay_lib_log($gatewayParams, $logData, 'Amount mismatch: ArionPay invoice is ' . (is_scalar($amount) ? (string) $amount : '?') . ' ' . $invoiceCurrency . ', WHMCS balance is ' . $whmcs['balance'] . '. NOT paid.');
        return 'not_paid_amount';
    }

    addInvoicePayment($whmcsInvoiceId, $arionpayInvoiceId, (float) $amount, 0, ARIONPAY_WHMCS_MODULE);
    arionpay_lib_log($gatewayParams, $logData, 'Payment added to invoice ' . $whmcsInvoiceId . '.' . arionpay_lib_paid_notes($invoice));
    return 'paid';
}

/** Words for the log about how the invoice was paid, as far as ArionPay tells us. */
function arionpay_lib_paid_notes(array $invoice)
{
    $notes = '';
    if (!empty($invoice['test'])) {
        $notes .= ' TEST invoice, no real money.';
    }
    if (!empty($invoice['late'])) {
        $notes .= ' Paid late: the payment arrived after the invoice expired.';
    }
    if (isset($invoice['paidVia']) && $invoice['paidVia'] === 'radar') {
        $notes .= ' Paid via Payment Radar: the amount was not exact and the merchant accepted it.';
    } elseif (!empty($invoice['manual'])) {
        $notes .= ' Accepted by hand in the ArionPay dashboard (Payment Radar or mark paid).';
    }
    return $notes;
}

function arionpay_lib_log_fields(array $invoice)
{
    $out = array();
    foreach (array('id', 'test', 'orderId', 'status', 'amountFiat', 'currency', 'asset', 'network', 'amountCrypto', 'paidCrypto', 'overpaid', 'underpaidWithinTolerance', 'late', 'needsReview', 'reviewReason', 'manual') as $key) {
        if (array_key_exists($key, $invoice)) {
            $out[$key] = $invoice[$key];
        }
    }
    return $out;
}

/** The module's row must exist and agree with the event, or the event is refused. */
function arionpay_lib_row_matches($row, array $invoice, $whmcsInvoiceId)
{
    if ($row === null) {
        return false;
    }
    $metadata = isset($invoice['metadata']) && is_array($invoice['metadata']) ? $invoice['metadata'] : array();
    if ((int) $row['whmcs_invoice_id'] !== (int) $whmcsInvoiceId) {
        return false;
    }
    if (!isset($metadata['whmcs_invoice_id']) || (int) $metadata['whmcs_invoice_id'] !== (int) $whmcsInvoiceId) {
        return false;
    }
    if (!isset($invoice['orderId']) || (string) $invoice['orderId'] !== (string) $whmcsInvoiceId) {
        return false;
    }
    if (array_key_exists('test', $invoice) && (bool) $invoice['test'] !== ($row['mode'] === 'test')) {
        return false;
    }
    return true;
}

// ---------------------------------------------------------------- pay button

/**
 * Handle the pay-button post: check its token, then create or reuse the ArionPay invoice and redirect.
 */
function arionpay_lib_handle_pay(array $gatewayParams, array $post)
{
    $invoiceId = isset($post['invoice_id']) ? (int) $post['invoice_id'] : 0;
    $expires = isset($post['expires']) ? (int) $post['expires'] : 0;
    $returnUrl = isset($post['return_url']) ? (string) $post['return_url'] : '';
    $token = isset($post['pay_token']) ? (string) $post['pay_token'] : '';

    $expected = arionpay_lib_pay_token(isset($gatewayParams['apiSecret']) ? (string) $gatewayParams['apiSecret'] : '', $invoiceId, $expires, $returnUrl);
    if ($invoiceId <= 0 || !hash_equals($expected, $token)) {
        arionpay_lib_log($gatewayParams, array('invoice' => $invoiceId), 'Pay button refused: bad token.');
        arionpay_lib_error_page(403, 'This payment link is not valid. Please open your invoice and try again.', '');
    }
    if ($expires < time()) {
        arionpay_lib_error_page(403, 'This payment link has expired. Please open your invoice and try again.', $returnUrl);
    }
    if (!arionpay_lib_is_configured($gatewayParams)) {
        arionpay_lib_log($gatewayParams, array('invoice' => $invoiceId), 'Pay button refused: keys are not set in the gateway settings.');
        arionpay_lib_error_page(503, 'Crypto payment is not available right now. Please contact us.', $returnUrl);
    }

    try {
        $url = arionpay_lib_start_payment($gatewayParams, $invoiceId, $returnUrl);
    } catch (ArionPayWhmcsApiException $e) {
        arionpay_lib_log($gatewayParams, array('invoice' => $invoiceId, 'error' => $e->getMessage(), 'http' => $e->getCode()), 'Could not create the ArionPay invoice.');
        arionpay_lib_error_page(502, 'We could not start the crypto payment. Please try again in a moment or contact us.', $returnUrl);
    } catch (\Exception $e) {
        arionpay_lib_log($gatewayParams, array('invoice' => $invoiceId, 'error' => $e->getMessage()), 'Could not start the payment.');
        arionpay_lib_error_page(500, 'We could not start the crypto payment. Please try again in a moment or contact us.', $returnUrl);
    }

    header('Location: ' . $url, true, 303);
    exit;
}

/**
 * Create or reuse. The WHMCS invoice amount and currency come from WHMCS, never from the browser.
 *
 * @return string URL to send the customer to
 */
function arionpay_lib_start_payment(array $gatewayParams, $invoiceId, $returnUrl)
{
    $whmcs = arionpay_lib_whmcs_invoice($invoiceId);
    if ($whmcs === null) {
        throw new ArionPayWhmcsApiException('WHMCS invoice ' . $invoiceId . ' could not be read.');
    }
    if (!arionpay_lib_is_payable($whmcs['status']) || (float) $whmcs['balance'] <= 0) {
        return $returnUrl;
    }

    arionpay_lib_ensure_table();
    if (!arionpay_lib_lock($invoiceId)) {
        throw new ArionPayWhmcsApiException('Another payment attempt for this invoice is running.');
    }
    try {
        $mode = arionpay_lib_is_test_mode($gatewayParams) ? 'test' : 'live';
        $row = arionpay_lib_latest_row($invoiceId);
        $row = $row === null ? null : (array) $row;
        $attempt = 1;

        if ($row !== null) {
            $same = $row['mode'] === $mode && strtoupper($row['currency']) === $whmcs['currency'] && arionpay_lib_same_amount($row['amount'], $whmcs['balance']);
            if ($same) {
                $outcome = arionpay_lib_check_existing($gatewayParams, $row, $invoiceId);
                if ($outcome !== null) {
                    return $outcome === '__paid__' ? $returnUrl : $outcome;
                }
            }
            $attempt = (int) $row['attempt'] + 1;
        }

        return arionpay_lib_create_invoice($gatewayParams, $whmcs, $returnUrl, $mode, $attempt);
    } finally {
        arionpay_lib_unlock($invoiceId);
    }
}

/**
 * Decide from a signed read whether the newest invoice can be reused.
 *
 * @return string|null hosted URL, "__paid__", or null when a new invoice is needed
 */
function arionpay_lib_check_existing(array $gatewayParams, array $row, $invoiceId)
{
    try {
        $read = arionpay_lib_api($gatewayParams, 'GET', '/api/v1/invoices/' . rawurlencode($row['arionpay_invoice_id']));
    } catch (ArionPayWhmcsApiException $e) {
        if ($e->getCode() === 404) {
            return null;
        }
        throw $e;
    }

    $status = isset($read['status']) ? strtoupper((string) $read['status']) : '';
    if ($status === 'PAID') {
        $invoice = array(
            'id' => isset($read['invoiceId']) ? (string) $read['invoiceId'] : '',
            'test' => !empty($read['test']),
            'orderId' => isset($read['orderId']) ? (string) $read['orderId'] : '',
            'status' => 'paid',
            'amountFiat' => $read['amountFiat'] ?? null,
            'currency' => $read['currency'] ?? '',
            'asset' => $read['settledCurrency'] ?? '',
            'network' => $read['network'] ?? '',
            'paidCrypto' => $read['amountReceived'] ?? '',
            'late' => !empty($read['late']),
            'manual' => !empty($read['manual']),
            'metadata' => (isset($read['metadata']) && is_array($read['metadata'])) ? $read['metadata'] : array(),
        );
        if ($invoice['id'] === $row['arionpay_invoice_id'] && arionpay_lib_row_matches($row, $invoice, $invoiceId)) {
            arionpay_lib_apply_paid($gatewayParams, $invoice, $row);
        } else {
            arionpay_lib_log($gatewayParams, arionpay_lib_log_fields($invoice), 'Signed read does not match this WHMCS invoice. NOT paid.');
        }
        return '__paid__';
    }
    if (in_array($status, array('NEW', 'PENDING', 'PAID_PARTIAL', 'CLAIM_PENDING'), true)) {
        return (string) $row['hosted_url'];
    }
    return null;
}

/**
 * Create the invoice through the signed API and remember it.
 *
 * @return string hosted checkout URL
 */
function arionpay_lib_create_invoice(array $gatewayParams, array $whmcs, $returnUrl, $mode, $attempt)
{
    $invoiceId = (int) $whmcs['id'];
    $amount = number_format((float) $whmcs['balance'], 2, '.', '');

    for ($try = 0; $try < 2; $try++) {
        // Same invoice, amount and attempt gets the same ArionPay invoice, also after a network timeout.
        $key = substr(sprintf('whmcs-%d-%s-%s-%s%s-%d', $invoiceId, str_replace('-', '', $whmcs['date']), $mode, str_replace('.', '', $amount), $whmcs['currency'], $attempt), 0, 100);
        $fields = array(
            'amount' => $amount,
            'currency' => $whmcs['currency'],
            'orderId' => (string) $invoiceId,
            'description' => 'WHMCS invoice ' . $invoiceId,
            'successUrl' => $returnUrl,
            'cancelUrl' => $returnUrl,
            'idempotencyKey' => $key,
            'metadata' => array(
                'whmcs_invoice_id' => $invoiceId,
                'source' => 'whmcs',
                'module_version' => ARIONPAY_WHMCS_VERSION,
            ),
        );
        if ($mode === 'test') {
            $fields['test'] = true;
        }

        $response = arionpay_lib_api($gatewayParams, 'POST', '/api/v1/invoices', $fields);
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : array();
        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';
        $url = isset($data['invoice_url']) && is_string($data['invoice_url']) ? $data['invoice_url'] : '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!preg_match('/^[0-9a-fA-F]{24}$/', $id) || !in_array($scheme, array('http', 'https'), true)) {
            throw new ArionPayWhmcsApiException('ArionPay API returned an unexpected response.');
        }

        // The key can come back with an old invoice (for example after a restore); never send a customer to a finished one.
        $status = isset($data['status']) && is_string($data['status']) ? strtolower($data['status']) : '';
        if (!in_array($status, array('new', 'pending', 'paid_partial', 'claim_pending'), true)) {
            $attempt++;
            continue;
        }

        if (arionpay_lib_row_by_arionpay_id($id) === null) {
            Capsule::table(ARIONPAY_WHMCS_TABLE)->insert(array(
                'whmcs_invoice_id' => $invoiceId,
                'arionpay_invoice_id' => $id,
                'hosted_url' => $url,
                'amount' => $amount,
                'currency' => $whmcs['currency'],
                'mode' => $mode,
                'attempt' => $attempt,
                'created_at' => date('Y-m-d H:i:s'),
            ));
        }
        return $url;
    }
    throw new ArionPayWhmcsApiException('ArionPay returned finished invoices for this WHMCS invoice.');
}

/** A simple page for the customer. Details stay in the gateway log. */
function arionpay_lib_error_page($status, $message, $returnUrl)
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $e = function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };
    $link = (is_string($returnUrl) && preg_match('#^https?://#i', $returnUrl)) ? '<p><a href="' . $e($returnUrl) . '">Back to your invoice</a></p>' : '';
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Payment</title></head><body><p>' . $e($message) . '</p>' . $link . '</body></html>';
    exit;
}

// ---------------------------------------------------------------- webhook

function arionpay_lib_respond($status, array $body)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    exit;
}

/**
 * Handle one webhook. Never changes anything unless the signature is valid. Backend reference: services/webhooks.ts.
 */
function arionpay_lib_handle_webhook(array $gatewayParams, $rawBody, array $server)
{
    if (!isset($server['REQUEST_METHOD']) || $server['REQUEST_METHOD'] !== 'POST') {
        arionpay_lib_respond(405, array('error' => 'method_not_allowed'));
    }
    $secret = isset($gatewayParams['webhookSecret']) ? (string) $gatewayParams['webhookSecret'] : '';
    if ($secret === '') {
        arionpay_lib_log($gatewayParams, array(), 'Webhook refused: the Webhook Secret is not set in the gateway settings.');
        arionpay_lib_respond(503, array('error' => 'not_configured'));
    }
    if (strlen($rawBody) > ARIONPAY_WHMCS_MAX_BODY_BYTES) {
        arionpay_lib_respond(413, array('error' => 'payload_too_large'));
    }

    $header = isset($server['HTTP_ARIONPAY_SIGNATURE']) ? (string) $server['HTTP_ARIONPAY_SIGNATURE'] : '';
    if (!arionpay_lib_verify_webhook($secret, $rawBody, $header)) {
        $hint = ($header === '' && isset($server['HTTP_X_SIGNATURE']))
            ? 'Webhook refused: it has the old format. Turn off "Old ArionPay (v1) integration compatibility" for this store in ArionPay.'
            : 'Webhook refused: bad, stale or missing signature. Check the Webhook Secret and this server\'s clock.';
        arionpay_lib_log($gatewayParams, array('body_bytes' => strlen($rawBody)), $hint);
        arionpay_lib_respond(401, array('error' => 'invalid_signature'));
    }

    $event = json_decode($rawBody, true);
    if (!is_array($event) || !isset($event['id'], $event['type']) || !is_string($event['id']) || !is_string($event['type'])) {
        arionpay_lib_respond(400, array('error' => 'malformed_event'));
    }
    $invoice = isset($event['data']['invoice']) && is_array($event['data']['invoice']) ? $event['data']['invoice'] : null;
    if ($invoice === null) {
        arionpay_lib_respond(400, array('error' => 'malformed_event'));
    }
    $arionpayInvoiceId = isset($invoice['id']) && is_string($invoice['id']) ? $invoice['id'] : '';

    // "Send test webhook" events describe a made-up invoice and never touch WHMCS.
    if (!empty($event['test']) || strpos($event['id'], 'evt_test_') === 0 || strpos($arionpayInvoiceId, 'inv_test_') === 0) {
        arionpay_lib_log($gatewayParams, array('event' => $event['id']), 'Verified ArionPay test webhook received. Nothing changed.');
        arionpay_lib_respond(200, array('received' => true, 'test_event' => true));
    }

    $type = $event['type'];
    if (!in_array($type, array('invoice.pending', 'invoice.partial', 'invoice.paid', 'invoice.expired', 'invoice.needs_review'), true)) {
        arionpay_lib_log($gatewayParams, array('event' => $event['id'], 'type' => $type), 'Webhook ignored: unknown event type.');
        arionpay_lib_respond(200, array('received' => true, 'ignored' => 'unknown_event'));
    }

    if (!preg_match('/^[0-9a-fA-F]{24}$/', $arionpayInvoiceId)) {
        arionpay_lib_respond(400, array('error' => 'malformed_event'));
    }

    $metadata = isset($invoice['metadata']) && is_array($invoice['metadata']) ? $invoice['metadata'] : array();
    if (!isset($metadata['whmcs_invoice_id'])) {
        // An invoice from the dashboard or a payment link on the same store: not ours, nothing to do.
        arionpay_lib_respond(200, array('received' => true, 'ignored' => 'not_a_whmcs_invoice'));
    }
    $whmcsInvoiceId = (int) $metadata['whmcs_invoice_id'];
    if ($whmcsInvoiceId <= 0) {
        arionpay_lib_respond(400, array('error' => 'malformed_event'));
    }

    if (!arionpay_lib_lock($whmcsInvoiceId)) {
        header('Retry-After: 30');
        arionpay_lib_respond(503, array('error' => 'busy'));
    }

    // Under the lock, so a concurrent delivery sees the first one's result. WHMCS's die() in checkCb* still frees the lock.
    arionpay_lib_ensure_table();
    $row = arionpay_lib_row_by_arionpay_id($arionpayInvoiceId);
    $row = $row === null ? null : (array) $row;
    if (!arionpay_lib_row_matches($row, $invoice, $whmcsInvoiceId)) {
        arionpay_lib_log($gatewayParams, arionpay_lib_log_fields($invoice), 'Webhook refused: this ArionPay invoice does not belong to WHMCS invoice ' . $whmcsInvoiceId . '.');
        arionpay_lib_unlock($whmcsInvoiceId);
        arionpay_lib_respond(409, array('error' => 'invoice_mismatch'));
    }

    $fields = arionpay_lib_log_fields($invoice);
    switch ($type) {
        case 'invoice.paid':
            $outcome = arionpay_lib_apply_paid($gatewayParams, $invoice, $row);
            arionpay_lib_unlock($whmcsInvoiceId);
            arionpay_lib_respond(200, array('received' => true, 'outcome' => $outcome));
            break;
        case 'invoice.pending':
            arionpay_lib_log($gatewayParams, $fields, 'Payment seen on the network, waiting for confirmations. WHMCS invoice not changed.');
            break;
        case 'invoice.partial':
            arionpay_lib_log($gatewayParams, $fields, 'Partial payment received. WHMCS invoice not changed.');
            break;
        case 'invoice.expired':
            arionpay_lib_log($gatewayParams, $fields, 'ArionPay invoice expired without full payment. WHMCS invoice left unpaid.');
            break;
        case 'invoice.needs_review':
            arionpay_lib_log($gatewayParams, $fields, 'ArionPay flagged this invoice for your review in the dashboard. WHMCS invoice not changed.');
            break;
    }
    arionpay_lib_unlock($whmcsInvoiceId);
    arionpay_lib_respond(200, array('received' => true));
}
