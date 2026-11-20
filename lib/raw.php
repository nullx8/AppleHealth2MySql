<?php
/**
 * Stage 1: split an upload into data points and land them in `raw`.
 * Used by ios.php (live) and reimport.php (CLI, existing files).
 */

declare(strict_types=1);

function config(): array
{
    static $cfg = null;
    return $cfg ??= require __DIR__ . '/../config.php';
}

function db(bool $reconnect = false): PDO
{
    static $pdo = null;
    if ($pdo === null || $reconnect) {
        $c = config();
        $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/** Recursively sort object keys so key order doesn't change the hash. */
function canonical(mixed $v): mixed
{
    if (is_array($v)) {
        if (!array_is_list($v)) {
            ksort($v, SORT_STRING);
        }
        return array_map('canonical', $v);
    }
    return $v;
}

/**
 * "iPhone|Johnny Appleseed’s Apple\u{A0}Watch" and "Johnny Appleseed’s Apple Watch|iPhone" -> same string:
 * unify whitespace, drop empty/repeated parts, sort.
 */
function normalizeSource(string $s): string
{
    $parts = [];
    foreach (explode('|', $s) as $part) {
        $part = trim(preg_replace('/[\s\x{00A0}\x{202F}\x{2007}]+/u', ' ', $part) ?? $part);
        if ($part !== '') {
            $parts[$part] = true;
        }
    }
    $parts = array_keys($parts);
    sort($parts, SORT_STRING);
    return mb_substr(implode('|', $parts), 0, 255);
}

/** What the duplicate fingerprint is built from (payload kept verbatim elsewhere). */
function pointHashSource(string $section, string $name, ?string $units, array $p): string
{
    if (isset($p['source']) && is_scalar($p['source'])) {
        $p['source'] = normalizeSource((string)$p['source']);
    }
    return $section . '|' . $name . '|' . $units . '|'
         . json_encode(canonical($p), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** "2026-11-09 01:01:00 +0700" -> ['2026-11-08 18:01:00', 420] */
function parseTs(mixed $s): array
{
    if (!is_string($s) || $s === '') {
        return [null, null];
    }
    try {
        $d = new DateTimeImmutable($s);
    } catch (Exception) {
        return [null, null];
    }
    $offset = intdiv($d->getOffset(), 60);
    return [$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $offset];
}

/**
 * Yield [section, name, units, point] for every data point in the payload.
 *  - items with a `data` list (metrics style): one row per entry of `data`
 *  - other items (e.g. workouts): one row per item
 */
function explodePayload(array $json): Generator
{
    $data = $json['data'] ?? $json;
    if (!is_array($data)) {
        return;
    }
    foreach ($data as $section => $items) {
        if (!is_array($items) || !array_is_list($items)) {
            yield [(string)$section, '(section)', null, $items];
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                yield [(string)$section, '(value)', null, $item];
                continue;
            }
            $name  = (string)($item['name'] ?? '(unnamed)');
            $units = isset($item['units']) && is_scalar($item['units']) ? (string)$item['units'] : null;
            if (isset($item['data']) && is_array($item['data']) && array_is_list($item['data'])) {
                foreach ($item['data'] as $point) {
                    yield [(string)$section, $name, $units, $point];
                }
            } else {
                yield [(string)$section, $name, $units, $item];
            }
        }
    }
}

/**
 * Insert points ([section, name, units, point] tuples) into `raw`.
 * Returns [received, new, summary].
 */
function landPoints(int $uploadId, iterable $points): array
{
    $pdo = db();
    $batch = [];
    $batchBytes = 0;
    $received = 0;
    $new = 0;
    $summary = [];

    $flush = function () use (&$batch, &$batchBytes, &$new, &$summary, $pdo, $uploadId) {
        if (!$batch) {
            return;
        }
        $place = implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?)'));
        $args = [];
        foreach ($batch as $r) {
            array_push($args, $uploadId, $r['section'], $r['name'], $r['units'],
                $r['ts'], $r['tz'], $r['source'], $r['payload'], $r['hash']);
        }
        $st = $pdo->prepare("INSERT IGNORE INTO raw
            (upload_id, section, name, units, ts_utc, tz_offset, source, payload, hash) VALUES $place");
        $st->execute($args);
        if ($st->rowCount() === 0) {
            $batch = [];
            $batchBytes = 0;
            return;
        }
        // Per-metric "new" counts: rows of this batch that were actually inserted
        // (first id of this statement onwards, so earlier batches aren't recounted).
        $firstId = (int)$pdo->lastInsertId();
        $hashes = array_column($batch, 'hash');
        $in = implode(',', array_fill(0, count($hashes), '?'));
        $q = $pdo->prepare("SELECT section, name, COUNT(*) c FROM raw
                            WHERE upload_id = ? AND id >= ? AND hash IN ($in) GROUP BY section, name");
        $q->execute([$uploadId, $firstId, ...$hashes]);
        foreach ($q as $row) {
            $summary[$row['section']][$row['name']]['new'] += (int)$row['c'];
            $new += (int)$row['c'];
        }
        $batch = [];
        $batchBytes = 0;
    };

    $pdo->beginTransaction();
    try {
        foreach ($points as [$section, $name, $units, $point]) {
            $received++;
            $summary[$section][$name] ??= ['units' => $units, 'received' => 0, 'new' => 0];
            $summary[$section][$name]['received']++;

            $p = is_array($point) ? $point : ['value' => $point];
            [$ts, $tz] = parseTs($p['date'] ?? $p['startDate'] ?? $p['start'] ?? null);
            $payload = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                      | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
            $source = isset($p['source']) && is_scalar($p['source']) ? normalizeSource((string)$p['source']) : null;
            $hashSrc = pointHashSource($section, $name, $units, $p);

            if (strlen($payload) > 15 << 20) {
                throw new RuntimeException("single $section/$name entry is " . (strlen($payload) >> 20)
                    . " MB, above the database packet limit");
            }
            $batchBytes += strlen($payload) + 200;
            $batch[] = [
                'section' => $section, 'name' => mb_substr($name, 0, 100), 'units' => $units,
                'ts' => $ts, 'tz' => $tz, 'source' => $source,
                'payload' => $payload, 'hash' => sha1($hashSrc, true),
            ];
            // 5000 rows or ~4 MB per INSERT (max_allowed_packet is 16 MB; workouts can be large)
            if (count($batch) >= 5000 || $batchBytes >= 4 << 20) {
                $flush();
            }
        }
        $flush();
        $pdo->commit();
    } catch (Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (Throwable) {
            // connection already gone; reconnect so the caller can record the error
        }
        db(true);
        throw $e;
    }
    return [$received, $new, $summary];
}

/** Create the upload row. */
function createUpload(array $headers, ?string $file, int $bytes, ?string $remote, ?string $receivedAt = null): int
{
    $h = array_change_key_case($headers, CASE_LOWER);
    $st = db()->prepare("INSERT INTO upload
        (received_at, file, automation_name, automation_id, session_id, user_agent, remote_addr, bytes, headers)
        VALUES (COALESCE(?, NOW()), ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([
        $receivedAt, $file,
        $h['automation-name'] ?? null, $h['automation-id'] ?? null, $h['session-id'] ?? null,
        isset($h['user-agent']) ? urldecode($h['user-agent']) : null,
        $remote, $bytes,
        json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    return (int)db()->lastInsertId();
}

function finishUpload(int $id, string $status, int $received, int $new, ?array $summary, ?string $error = null): void
{
    db()->prepare("UPDATE upload SET status=?, points_received=?, points_new=?, summary=?, error=? WHERE id=?")
        ->execute([$status, $received, $new,
                   $summary === null ? null : json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                   $error, $id]);
}

/**
 * Full pipeline for one body: create upload row, decode, land points.
 * Returns the response array.
 */
function processBody(string $body, array $headers, ?string $file, ?string $remote, ?string $receivedAt = null): array
{
    $uploadId = createUpload($headers, $file, strlen($body), $remote, $receivedAt);

    $json = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($json)) {
        $err = 'invalid JSON: ' . json_last_error_msg();
        finishUpload($uploadId, 'error', 0, 0, null, $err);
        return ['ok' => false, 'upload_id' => $uploadId, 'error' => $err];
    }

    try {
        [$received, $new, $summary] = landPoints($uploadId, explodePayload($json));
    } catch (Throwable $e) {
        finishUpload($uploadId, 'error', 0, 0, null, $e->getMessage());
        return ['ok' => false, 'upload_id' => $uploadId, 'error' => 'database error, raw file kept'];
    }
    finishUpload($uploadId, $received ? 'ok' : 'empty', $received, $new, $summary);

    return [
        'ok'        => true,
        'upload_id' => $uploadId,
        'received'  => $received,
        'new'       => $new,
        'duplicate' => $received - $new,
        'detail'    => $summary,
    ];
}
