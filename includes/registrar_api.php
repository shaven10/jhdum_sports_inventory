<?php
/**
 * Registrar Active Students API client.
 * @see active-students-api-documentation
 */

function registrarApiBaseUrl(): string
{
    $url = trim((string) getSetting('registrar_api_base_url', 'http://localhost/regdum_online_processing/api/v1'));
    return rtrim($url, '/');
}

function registrarApiKey(): string
{
    return normalizeRegistrarApiKey((string) getSetting('registrar_api_key', ''));
}

function normalizeRegistrarApiKey(string $key): string
{
    $key = trim($key);
    $key = trim($key, " \t\n\r\0\x0B\"'");
    return preg_replace('/\s+/', '', $key) ?? '';
}

function registrarApiKeyPrefix(): string
{
    $key = registrarApiKey();
    if ($key === '') {
        return '';
    }

    return substr($key, 0, 12);
}

function isValidRegistrarApiKeyFormat(string $key): bool
{
    $key = normalizeRegistrarApiKey($key);
    return (bool) preg_match('/^rd_[a-f0-9]{48}$/i', $key);
}

function isRegistrarStudentLoginConfigured(): bool
{
    return registrarApiBaseUrl() !== '' && registrarApiKey() !== '';
}

/**
 * @return array{ok:bool, student:?array, message:string, http_status:?int}
 */
function fetchActiveStudentByStudentId(string $studentId): array
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return ['ok' => false, 'student' => null, 'message' => 'Please enter your student ID.', 'http_status' => null];
    }

    if (!isRegistrarStudentLoginConfigured()) {
        return ['ok' => false, 'student' => null, 'message' => 'Student login is not configured yet. Please contact the Sports Development Office.', 'http_status' => null];
    }

    $query = http_build_query([
        'student_id' => $studentId,
        'per_page' => 1,
        'page' => 1,
    ]);
    $url = registrarApiBaseUrl() . '/active-students.php?' . $query;

    $response = registrarApiGet($url);
    if (!$response['ok']) {
        if (($response['http_status'] ?? null) === 401) {
            return [
                'ok' => false,
                'student' => null,
                'message' => 'Student login is temporarily unavailable. Please contact the Sports Development Office.',
                'http_status' => 401,
            ];
        }

        return [
            'ok' => false,
            'student' => null,
            'message' => $response['message'],
            'http_status' => $response['http_status'],
        ];
    }

    $payload = $response['body'];
    if (empty($payload['ok'])) {
        $message = (string) ($payload['error'] ?? 'Could not verify your student ID.');
        return ['ok' => false, 'student' => null, 'message' => $message, 'http_status' => $response['http_status']];
    }

    $students = $payload['data']['students'] ?? [];
    if (!is_array($students) || $students === []) {
        return [
            'ok' => false,
            'student' => null,
            'message' => 'Student ID not found or not currently enrolled. Only active enrolled students can borrow equipment.',
            'http_status' => $response['http_status'],
        ];
    }

    $student = $students[0];
    foreach ($students as $row) {
        if (strcasecmp((string) ($row['student_id'] ?? ''), $studentId) === 0) {
            $student = $row;
            break;
        }
    }

    if (strcasecmp((string) ($student['student_id'] ?? ''), $studentId) !== 0) {
        return [
            'ok' => false,
            'student' => null,
            'message' => 'Student ID not found or not currently enrolled.',
            'http_status' => $response['http_status'],
        ];
    }

    if (empty($student['is_active']) || ($student['enrollment_status'] ?? '') !== 'enrolled') {
        return [
            'ok' => false,
            'student' => null,
            'message' => 'Your enrollment is not active. Please contact the Registrar.',
            'http_status' => $response['http_status'],
        ];
    }

    return ['ok' => true, 'student' => $student, 'message' => 'OK', 'http_status' => $response['http_status']];
}

/**
 * Verify Registrar API connectivity and key validity (admin diagnostics).
 *
 * @return array{ok:bool, message:string, http_status:?int, key_prefix:string}
 */
function testRegistrarApiConnection(?string $apiKeyOverride = null): array
{
    $apiKey = ($apiKeyOverride !== null && $apiKeyOverride !== '')
        ? normalizeRegistrarApiKey($apiKeyOverride)
        : registrarApiKey();
    $baseUrl = registrarApiBaseUrl();

    if ($baseUrl === '' || $apiKey === '') {
        return [
            'ok' => false,
            'message' => 'Configure the Registrar API base URL and API key first.',
            'http_status' => null,
            'key_prefix' => $apiKey !== '' ? substr($apiKey, 0, 12) : '',
        ];
    }

    if (!isValidRegistrarApiKeyFormat($apiKey)) {
        return [
            'ok' => false,
            'message' => 'Invalid API key format. Copy the full key from Registrar → External API (starts with rd_ and is 51 characters long).',
            'http_status' => null,
            'key_prefix' => substr($apiKey, 0, 12),
        ];
    }

    $query = http_build_query([
        'per_page' => 1,
        'page' => 1,
    ]);
    $url = $baseUrl . '/active-students.php?' . $query;
    $response = registrarApiGet($url, $apiKey);
    $keyPrefix = substr($apiKey, 0, 12);

    if ($response['http_status'] === 401) {
        return [
            'ok' => false,
            'message' => 'API key rejected (401). In Registrar → External API, confirm the API is enabled, the key is active, and paste the full key here (starts with rd_, 51 characters). Regenerated keys must be updated here too.',
            'http_status' => 401,
            'key_prefix' => $keyPrefix,
        ];
    }

    if ($response['http_status'] === 503) {
        return [
            'ok' => false,
            'message' => 'Registrar External API is disabled. Enable it in Registrar → External API settings.',
            'http_status' => 503,
            'key_prefix' => $keyPrefix,
        ];
    }

    if (!$response['ok']) {
        return [
            'ok' => false,
            'message' => $response['message'],
            'http_status' => $response['http_status'],
            'key_prefix' => $keyPrefix,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Connection successful. API key is valid.',
        'http_status' => $response['http_status'],
        'key_prefix' => $keyPrefix,
    ];
}

/**
 * @return array{ok:bool, body:?array, message:string, http_status:?int}
 */
function registrarApiGet(string $url, ?string $apiKey = null): array
{
    $apiKey = $apiKey ?? registrarApiKey();
    if ($apiKey === '') {
        return [
            'ok' => false,
            'body' => null,
            'message' => 'Student login is not configured yet. Please contact the Sports Development Office.',
            'http_status' => null,
        ];
    }

    // Query-string fallback for Apache/CGI setups that drop custom auth headers.
    if (!str_contains($url, 'api_key=')) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'api_key=' . rawurlencode($apiKey);
    }

    $headers = [
        'Accept: application/json',
        'X-API-Key: ' . $apiKey,
        'Authorization: Bearer ' . $apiKey,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'body' => null,
                'message' => 'Could not reach the student records service. ' . ($curlError ?: 'Please try again later.'),
                'http_status' => $httpStatus ?: null,
            ];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $httpStatus = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpStatus = (int) $m[1];
        }
        if ($raw === false) {
            return [
                'ok' => false,
                'body' => null,
                'message' => 'Could not reach the student records service. Please try again later.',
                'http_status' => $httpStatus ?: null,
            ];
        }
    }

    if ($httpStatus === 401) {
        $parsedError = registrarApiParseJsonBody($raw);
        $apiError = is_array($parsedError) ? (string) ($parsedError['error'] ?? '') : '';
        $message = 'Student login is temporarily unavailable (API authentication failed).';
        if ($apiError !== '') {
            $message = $apiError;
        }

        return ['ok' => false, 'body' => $parsedError, 'message' => $message, 'http_status' => 401];
    }
    if ($httpStatus === 503) {
        return ['ok' => false, 'body' => null, 'message' => 'Student login is temporarily unavailable. Please try again later.', 'http_status' => 503];
    }
    if ($httpStatus >= 400) {
        $parsedError = registrarApiParseJsonBody($raw);
        if (is_array($parsedError) && !empty($parsedError['error'])) {
            return ['ok' => false, 'body' => $parsedError, 'message' => (string) $parsedError['error'], 'http_status' => $httpStatus];
        }
        return ['ok' => false, 'body' => null, 'message' => 'Student records service returned an error. Please try again later.', 'http_status' => $httpStatus];
    }

    $body = registrarApiParseJsonBody($raw);
    if (!is_array($body)) {
        $hint = registrarApiNonJsonHint($raw);
        return ['ok' => false, 'body' => null, 'message' => $hint, 'http_status' => $httpStatus];
    }

    return ['ok' => true, 'body' => $body, 'message' => 'OK', 'http_status' => $httpStatus];
}

/** @return array<string, mixed>|null */
function registrarApiParseJsonBody(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if ($raw[0] !== '{' && $raw[0] !== '[') {
        if (preg_match('/[\[{].*[\]}]/s', $raw, $match)) {
            $raw = $match[0];
        }
    }

    $body = json_decode($raw, true);
    return is_array($body) ? $body : null;
}

function registrarApiNonJsonHint(string $raw): string
{
    if (stripos($raw, '<b>Fatal error</b>') !== false || stripos($raw, '<br />') !== false) {
        return 'Student records service returned an invalid response (server error). Please contact the Registrar or Sports Development Office.';
    }

    if (stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<html') !== false) {
        return 'Student records service returned an HTML page instead of data. Check the Registrar API base URL in System Settings.';
    }

    return 'Invalid response from student records service. Verify the Registrar API URL and key in System Settings.';
}

function registrarStudentLoginEmail(array $student): string
{
    $studentId = trim((string) ($student['student_id'] ?? ''));
    $email = trim((string) ($student['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    $slug = preg_replace('/[^a-zA-Z0-9._-]/', '', $studentId);
    if ($slug === '') {
        $slug = 'student' . (int) ($student['id'] ?? 0);
    }

    return strtolower($slug) . '@student.jhcsc.local';
}

function registrarStudentUsername(array $student): string
{
    $studentId = trim((string) ($student['student_id'] ?? ''));
    $username = preg_replace('/[^a-zA-Z0-9._-]/', '', $studentId);
    return $username !== '' ? $username : ('student' . (int) ($student['id'] ?? 0));
}
