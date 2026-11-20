<?php
/**
 * Health Auto Export – receiver (stage 1)
 *
 * 1. saves the request body untouched as incoming/<timestamp>_<id>.json.gz
 * 2. creates an `upload` row (headers, automation, size)
 * 3. splits the payload into single data points -> `raw` (exact duplicates skipped)
 * 4. answers with counts: received / new / duplicate, per metric
 *
 * Sorting into typed tables is a separate step (stage 2).
 */

declare(strict_types=1);

require __DIR__ . '/lib/raw.php';
require __DIR__ . '/lib/sort.php';

ini_set('memory_limit', '2048M');
set_time_limit(900);
ignore_user_abort(true);   // finish landing even if the app stops waiting
date_default_timezone_set(config()['timezone']);

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'POST only']);
}

// Shared-secret header: nothing is stored unless it matches config upload_header / upload_token.
$expected = (string)(config()['upload_token'] ?? '');
if ($expected === '') {
    respond(503, ['ok' => false, 'error' => 'uploads disabled: upload_token not set in config.php']);
}
$headerName = strtolower((string)(config()['upload_header'] ?? 'x-upload-token'));
$sent = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER)[$headerName] ?? null;
if ($sent === null) {
    // header names only (never values), to help spot a misnamed header in the app
    error_log('ios.php: rejected upload without ' . $headerName . ' header from ' . ($_SERVER['REMOTE_ADDR'] ?? '?')
        . '; headers sent: ' . implode(', ', array_keys(function_exists('getallheaders') ? getallheaders() : [])));
    respond(401, ['ok' => false, 'error' => "missing header: $headerName"]);
}
if (!hash_equals($expected, (string)$sent)) {
    error_log('ios.php: rejected upload with wrong ' . $headerName . ' header from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    respond(403, ['ok' => false, 'error' => 'invalid token']);
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    respond(400, ['ok' => false, 'error' => 'empty request body']);
}

$headers = function_exists('getallheaders') ? getallheaders() : [];

// Unpack gzip if the client compressed the body.
$body = $raw;
if (str_starts_with($raw, "\x1f\x8b")) {
    $decoded = @gzdecode($raw);
    if ($decoded !== false) {
        $body = $decoded;
    }
}

// 1. Keep the original before anything else can fail.
$dir = config()['store_dir'];
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    respond(500, ['ok' => false, 'error' => 'storage directory missing']);
}
$file = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.json.gz';
if (file_put_contents($dir . '/' . $file, gzencode($body, 6)) === false) {
    respond(500, ['ok' => false, 'error' => 'failed to write payload']);
}

// 2.–4.
try {
    $result = processBody($body, $headers, $file, $_SERVER['REMOTE_ADDR'] ?? null);
} catch (Throwable $e) {
    respond(500, ['ok' => false, 'error' => 'database unavailable, raw file kept', 'file' => $file]);
}

// Stage 2: sort into typed tables right away (waits briefly if an admin sort is running;
// otherwise the rows stay pending for the next run). Upload is already safe at this point.
if ($result['ok'] && $result['new'] > 0) {
    try {
        $sorted = sortPending(20);
        $result['sorted'] = $sorted ?? 'deferred (sorter busy)';
    } catch (Throwable $e) {
        $result['sorted'] = 'failed, rows stay pending';
    }
}

respond($result['ok'] ? 200 : 422, $result);
