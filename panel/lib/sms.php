<?php
/**
 * Wysyłka SMS do serwisu: SMSAPI (sms.do) i SerwerSMS (send_sms.json).
 * Konfiguracja wyłącznie z tabeli settings panelu (Ustawienia -> Formularz).
 *
 * TRYB TESTOWY (settings "sms_test_mode" = 1, domyślnie) NIE łączy się z żadnym
 * dostawcą - zwraca status testowy. Prawdziwa wysyłka jest możliwa dopiero po
 * świadomym wyłączeniu trybu testowego i uzupełnieniu tokenu oraz odbiorcy.
 */

const SMS_API_ENDPOINTS = [
    'smsapi' => 'https://api.smsapi.pl/sms.do',
    'serwersms' => 'https://api2.serwersms.pl/messages/send_sms.json',
];

/**
 * Konfiguracja SMS z bazy panelu (bez logowania wartości sekretów).
 */
function tickets_sms_config(): array
{
    $provider = setting_get('sms_provider', 'smsapi');
    if (!isset(SMS_API_ENDPOINTS[$provider])) {
        $provider = 'smsapi';
    }
    return [
        'test_mode' => setting_get('sms_test_mode', '1') === '1',
        'provider' => $provider,
        'token' => trim(setting_get('sms_api_token')),
        'sender' => trim(setting_get('sms_sender')),
        'recipient' => trim(setting_get('sms_recipient')),
        'template' => function_exists('tickets_sms_template') ? tickets_sms_template() : '',
    ];
}

/**
 * Wysyła SMS.
 * @return array{ok:bool,test?:bool,skipped?:bool,error?:string,provider_message_id?:string,delivery_status?:string}
 */
function sms_send(string $recipient, string $message, array $config): array
{
    if (!empty($config['test_mode'])) {
        // Tryb testowy: brak połączenia sieciowego, brak kosztów.
        return [
            'ok' => true,
            'test' => true,
            'provider_message_id' => '',
            'delivery_status' => 'test',
        ];
    }

    $provider = (string) ($config['provider'] ?? 'smsapi');
    if (!isset(SMS_API_ENDPOINTS[$provider])) {
        return ['ok' => false, 'error' => 'Nieznany dostawca SMS.'];
    }
    if (trim((string) ($config['token'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Brak tokenu API dostawcy SMS w ustawieniach.'];
    }
    $recipient = function_exists('tickets_normalize_phone') ? tickets_normalize_phone($recipient) : $recipient;
    if ($recipient === '') {
        return ['ok' => false, 'error' => 'Brak poprawnie skonfigurowanego numeru odbiorcy SMS.'];
    }
    $message = trim($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'Pusta treść SMS.'];
    }

    $token = trim((string) $config['token']);
    $sender = trim((string) ($config['sender'] ?? ''));

    if ($provider === 'smsapi') {
        $fields = [
            'to' => $recipient,
            'message' => $message,
            'format' => 'json',
            'encoding' => 'utf-8',
        ];
        if ($sender !== '') {
            $fields['from'] = $sender;
        }
        $response = sms_http_request(
            SMS_API_ENDPOINTS['smsapi'],
            http_build_query($fields),
            ['Authorization: Bearer ' . $token, 'Content-Type: application/x-www-form-urlencoded']
        );
        return sms_parse_smsapi_response($response);
    }

    $payload = [
        'phone' => $recipient,
        'text' => $message,
    ];
    if ($sender !== '') {
        $payload['sender'] = $sender;
    }
    $response = sms_http_request(
        SMS_API_ENDPOINTS['serwersms'],
        (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json']
    );
    return sms_parse_serwersms_response($response);
}

/**
 * Prosty klient HTTP (curl, a bez niego strumienie). Bez logowania treści.
 * @return array{ok:bool,status:int,body:string,error:string}
 */
function sms_http_request(string $url, string $body, array $headers): array
{
    $timeout = 10;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseBody === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'Błąd połączenia z API SMS: ' . mb_substr($error, 0, 200, 'UTF-8')];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $responseBody, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = [];
    if (function_exists('http_get_last_response_headers')) {
        $responseHeaders = http_get_last_response_headers() ?? [];
    } else {
        // PHP < 8.4: zmienna jest tworzona przez file_get_contents.
        $responseHeaders = $http_response_header ?? [];
    }
    if (!empty($responseHeaders) && preg_match('/\s(\d{3})\s/', (string) $responseHeaders[0], $m)) {
        $status = (int) $m[1];
    }
    if ($responseBody === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'Błąd połączenia z API SMS.'];
    }
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $responseBody, 'error' => ''];
}

/** Odpowiedź SMSAPI (format=json): {"count":1,"list":[{"id":"...","status":"QUEUE"}]}. */
function sms_parse_smsapi_response(array $response): array
{
    $data = json_decode($response['body'], true);
    if (is_array($data) && isset($data['error'])) {
        $message = trim((string) ($data['message'] ?? 'Błąd SMSAPI'));
        return ['ok' => false, 'error' => 'SMSAPI: ' . mb_substr($message, 0, 200, 'UTF-8')];
    }
    if (!$response['ok']) {
        return ['ok' => false, 'error' => 'SMSAPI: HTTP ' . $response['status']];
    }
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'SMSAPI: nieprawidłowa odpowiedź.'];
    }
    $item = $data['list'][0] ?? [];
    $id = (string) ($item['id'] ?? '');
    $status = (string) ($item['status'] ?? 'QUEUE');
    return [
        'ok' => true,
        'provider_message_id' => mb_substr($id, 0, 120, 'UTF-8'),
        'delivery_status' => mb_substr($status, 0, 40, 'UTF-8'),
    ];
}

/** Odpowiedź SerwerSMS: {"success":true,"items":[{"id":"..."}]} lub {"success":false,...}. */
function sms_parse_serwersms_response(array $response): array
{
    $data = json_decode($response['body'], true);
    if (is_array($data) && array_key_exists('success', $data) && !$data['success']) {
        $message = (string) ($data['error']['message'] ?? $data['message'] ?? 'Błąd SerwerSMS');
        return ['ok' => false, 'error' => 'SerwerSMS: ' . mb_substr($message, 0, 200, 'UTF-8')];
    }
    if (!$response['ok']) {
        return ['ok' => false, 'error' => 'SerwerSMS: HTTP ' . $response['status']];
    }
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'SerwerSMS: nieprawidłowa odpowiedź.'];
    }
    $item = $data['items'][0] ?? ($data['item'] ?? []);
    return [
        'ok' => true,
        'provider_message_id' => mb_substr((string) ($item['id'] ?? ''), 0, 120, 'UTF-8'),
        'delivery_status' => 'sent',
    ];
}
