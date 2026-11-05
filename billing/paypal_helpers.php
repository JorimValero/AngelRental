<?php

require_once __DIR__ . '/../includes/mailer.php';

function paypal_client_id(): string
{
    return trim((string) (getenv('PAYPAL_CLIENT_ID') ?: ''));
}

function paypal_is_configured(): bool
{
    return paypal_client_id() !== '' && trim((string) (getenv('PAYPAL_CLIENT_SECRET') ?: '')) !== '';
}

function paypal_checkout_base_url(): string
{
    $configuredUrl = trim((string) (getenv('RENTAL_APP_URL') ?: ''));
    $configuredParts = $configuredUrl !== '' ? parse_url($configuredUrl) : false;
    $configuredHost = is_array($configuredParts)
        ? strtolower((string) ($configuredParts['host'] ?? ''))
        : '';
    $configuredForLocalhost = in_array($configuredHost, ['localhost', '::1'], true)
        || preg_match('/\A127(?:\.\d{1,3}){3}\z/', $configuredHost) === 1;

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $hostIsValid = preg_match('/\A[a-zA-Z0-9.-]+(?::\d{1,5})?\z/', $host) === 1;
    $requestHostIsLocal = preg_match(
        '/\A(?:localhost|127(?:\.\d{1,3}){3})(?::\d{1,5})?\z/i',
        $host
    ) === 1;
    $forwardedProto = strtolower(trim(explode(
        ',',
        (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')
    )[0]));
    $requestIsBehindHttpsProxy = $hostIsValid
        && !$requestHostIsLocal
        && $forwardedProto === 'https';

    if (
        $configuredUrl !== ''
        && !($configuredForLocalhost && $requestIsBehindHttpsProxy)
    ) {
        return $configuredUrl;
    }
    if (!$hostIsValid) {
        throw new RuntimeException('Configure RENTAL_APP_URL to enable PayPal redirects.');
    }

    $applicationPath = dirname(dirname($scriptName));
    $applicationPath = $applicationPath === '\\' || $applicationPath === '/'
        ? ''
        : rtrim(str_replace('\\', '/', $applicationPath), '/');
    $scheme = in_array($forwardedProto, ['http', 'https'], true)
        ? $forwardedProto
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    return $scheme . '://' . $host . $applicationPath;
}

function paypal_api_request(string $method, string $path, ?array $payload = null, ?string $requestId = null): array
{
    $clientId = paypal_client_id();
    $clientSecret = trim((string) (getenv('PAYPAL_CLIENT_SECRET') ?: ''));
    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('PayPal sandbox credentials are not configured.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for PayPal payments.');
    }

    $tokenHandle = curl_init('https://api-m.sandbox.paypal.com/v1/oauth2/token');
    curl_setopt_array($tokenHandle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $clientId . ':' . $clientSecret,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_TIMEOUT => 20,
    ]);
    $tokenResponse = curl_exec($tokenHandle);
    $tokenStatus = (int) curl_getinfo($tokenHandle, CURLINFO_HTTP_CODE);
    $tokenError = curl_error($tokenHandle);
    curl_close($tokenHandle);

    if (!is_string($tokenResponse) || $tokenStatus < 200 || $tokenStatus >= 300) {
        error_log('PayPal OAuth request failed. HTTP ' . $tokenStatus . ($tokenError !== '' ? ': ' . $tokenError : ''));
        throw new RuntimeException('PayPal is temporarily unavailable. Please try again later.');
    }
    $tokenData = json_decode($tokenResponse, true);
    $accessToken = is_array($tokenData) ? ($tokenData['access_token'] ?? null) : null;
    if (!is_string($accessToken) || $accessToken === '') {
        error_log('PayPal OAuth response did not contain an access token.');
        throw new RuntimeException('PayPal is temporarily unavailable. Please try again later.');
    }

    $headers = ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'];
    if ($requestId !== null) {
        $headers[] = 'PayPal-Request-Id: ' . $requestId;
    }
    $handle = curl_init('https://api-m.sandbox.paypal.com' . $path);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $requestError = curl_error($handle);
    curl_close($handle);

    if (!is_string($response) || $status < 200 || $status >= 300) {
        error_log('PayPal API request failed. HTTP ' . $status . ($requestError !== '' ? ': ' . $requestError : ''));
        throw new RuntimeException('PayPal could not process this payment. Please try again.');
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log('PayPal API returned an invalid JSON response.');
        throw new RuntimeException('PayPal returned an invalid response. Please try again.');
    }

    return $data;
}

function process_paypal_deposit_refund(PDO $connection, int $paymentId): bool
{
    $paymentStatement = $connection->prepare(
        "SELECT reservation_id, paypal_capture_id, paypal_refund_id, amount, currency, status
         FROM payment_transactions
         WHERE payment_id = :payment_id
           AND purpose IN ('deposit_refund', 'reservation_refund') AND method = 'paypal'"
    );
    $paymentStatement->execute(['payment_id' => $paymentId]);
    $payment = $paymentStatement->fetch();
    if (!$payment || $payment['paypal_capture_id'] === null) {
        throw new RuntimeException('The PayPal deposit refund could not be matched to its original capture.');
    }
    if ($payment['status'] === 'paid') {
        return true;
    }
    if ($payment['status'] !== 'pending') {
        throw new RuntimeException('This PayPal deposit refund is no longer pending.');
    }

    $refundNeedsVerification = !is_string($payment['paypal_refund_id'])
        || $payment['paypal_refund_id'] === '';
    if (!$refundNeedsVerification) {
        $refund = paypal_api_request(
            'GET',
            '/v2/payments/refunds/' . rawurlencode($payment['paypal_refund_id'])
        );
    } else {
        $refund = paypal_api_request(
            'POST',
            '/v2/payments/captures/' . rawurlencode((string) $payment['paypal_capture_id']) . '/refund',
            [
                'amount' => [
                    'currency_code' => $payment['currency'],
                    'value' => number_format((float) $payment['amount'], 2, '.', ''),
                ],
            ],
            'rental-refund-' . $paymentId
        );
    }

    $refundId = $refund['id'] ?? null;
    if (!is_string($refundId) || $refundId === '') {
        throw new RuntimeException('PayPal did not return a refund reference. Contact the shop before trying again.');
    }

    $saveRefundId = $connection->prepare(
        'UPDATE payment_transactions
         SET paypal_refund_id = :refund_id
         WHERE payment_id = :payment_id AND status = \'pending\'
           AND (paypal_refund_id IS NULL OR paypal_refund_id = :existing_refund_id)'
    );
    $saveRefundId->execute([
        'refund_id' => $refundId,
        'existing_refund_id' => $refundId,
        'payment_id' => $paymentId,
    ]);
    $savedRefundId = $connection->prepare(
        'SELECT paypal_refund_id, status FROM payment_transactions WHERE payment_id = :payment_id'
    );
    $savedRefundId->execute(['payment_id' => $paymentId]);
    $savedPayment = $savedRefundId->fetch();
    if (!$savedPayment || $savedPayment['paypal_refund_id'] !== $refundId) {
        if ($savedPayment && $savedPayment['status'] === 'paid') {
            return true;
        }
        throw new RuntimeException('The PayPal refund reference could not be saved. Please check the refund status before retrying.');
    }

    if ($refundNeedsVerification) {
        $verifiedRefund = paypal_api_request(
            'GET',
            '/v2/payments/refunds/' . rawurlencode($refundId)
        );
        if (($verifiedRefund['id'] ?? null) !== $refundId) {
            error_log('PayPal refund lookup did not match payment #' . $paymentId);
            throw new RuntimeException('PayPal could not verify the refund reference. Check its status before taking further action.');
        }
        $refund = $verifiedRefund;
    }

    $refundAmountDetails = $refund['amount'] ?? null;
    if (
        !is_array($refundAmountDetails)
        || !isset($refundAmountDetails['value'], $refundAmountDetails['currency_code'])
    ) {
        $refundAmountDetails = $refund['seller_payable_breakdown']['gross_amount'] ?? null;
    }
    $refundAmount = is_array($refundAmountDetails) ? ($refundAmountDetails['value'] ?? null) : null;
    $refundCurrency = is_array($refundAmountDetails) ? ($refundAmountDetails['currency_code'] ?? null) : null;
    $hasRefundAmount = $refundAmount !== null && $refundAmount !== '';
    $hasRefundCurrency = $refundCurrency !== null && $refundCurrency !== '';
    if (
        ($hasRefundAmount && (
            !is_numeric($refundAmount)
            || number_format((float) $refundAmount, 2, '.', '') !== number_format((float) $payment['amount'], 2, '.', '')
        ))
        || ($hasRefundCurrency && (
            !is_string($refundCurrency)
            || strtoupper($refundCurrency) !== strtoupper((string) $payment['currency'])
        ))
    ) {
        $actualAmount = is_scalar($refundAmount) ? (string) $refundAmount : 'missing';
        $actualCurrency = is_string($refundCurrency) ? $refundCurrency : 'missing';
        error_log(
            'PayPal refund validation failed for payment #' . $paymentId
            . ': expected ' . number_format((float) $payment['amount'], 2, '.', '') . ' '
            . strtoupper((string) $payment['currency'])
            . ', received ' . $actualAmount . ' ' . strtoupper($actualCurrency)
        );
        throw new RuntimeException('PayPal returned a refund amount or currency that does not match this payment. The refund reference was saved; check its status before taking further action.');
    }

    $refundStatus = isset($refund['status']) && is_string($refund['status'])
        ? strtoupper(trim($refund['status']))
        : '';
    if ($refundStatus === 'COMPLETED') {
        $newStatus = 'paid';
    } elseif ($refundStatus === 'PENDING') {
        $newStatus = 'pending';
    } elseif (in_array($refundStatus, ['CANCELLED', 'FAILED'], true)) {
        $newStatus = 'failed';
    } else {
        error_log(
            'PayPal returned an unrecognized refund status for payment #' . $paymentId
            . ': ' . ($refundStatus !== '' ? $refundStatus : 'missing')
        );
        throw new RuntimeException('PayPal returned an unrecognized refund status. Please check again or contact the shop.');
    }
    $updateStatement = $connection->prepare(
        'UPDATE payment_transactions
         SET paypal_refund_id = :refund_id, status = :status,
             paid_at = CASE WHEN :is_paid = 1 THEN CURRENT_TIMESTAMP ELSE paid_at END
         WHERE payment_id = :payment_id AND status = \'pending\''
    );
    $updateStatement->execute([
        'refund_id' => $refundId,
        'status' => $newStatus,
        'is_paid' => $newStatus === 'paid' ? 1 : 0,
        'payment_id' => $paymentId,
    ]);

    if ($updateStatement->rowCount() === 1 && $newStatus === 'paid') {
        send_customer_transaction_email(
            $connection,
            (int) $payment['reservation_id'],
            'Refund confirmed',
            'PayPal confirmed your refund of ' . $payment['currency'] . ' '
                . number_format((float) $payment['amount'], 2) . '.'
        );
    } elseif ($updateStatement->rowCount() === 1 && $newStatus === 'failed') {
        send_customer_transaction_email(
            $connection,
            (int) $payment['reservation_id'],
            'Refund needs attention',
            'PayPal could not complete your refund automatically. The shop has been notified and will follow up.'
        );
        send_admin_transaction_email(
            $connection,
            (int) $payment['reservation_id'],
            'PayPal refund failed',
            'PayPal did not complete refund #' . $paymentId . '. Please review and follow up with the customer.'
        );
    }

    return $newStatus === 'paid';
}
