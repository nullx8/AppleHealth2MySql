<?php
/**
 * CLI: import manual exports (iPad / phone "Export" button) into `raw`.
 *
 *   php import.php                  everything in import/ (zip, json, json.gz)
 *   php import.php FILE [FILE...]   specific files, any location
 *
 * For each JSON document (also inside zips):
 *   - stored as incoming/<timestamp>_<id>.json.gz (same as live uploads)
 *   - an `upload` row with automation_name "manual: <file name>"
 *   - streamed into `raw` without decoding the whole file
 * Successfully processed files in import/ are deleted (archive copy stays in incoming/).
 * Other zip members (e.g. GPX routes – also contained in the JSON) are skipped.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib/raw.php';
require __DIR__ . '/lib/sort.php';
require __DIR__ . '/lib/stream.php';
ini_set('memory_limit', '4096M');
date_default_timezone_set(config()['timezone']);

$importDir = __DIR__ . '/import';
$store     = config()['store_dir'];
$args      = array_slice($argv, 1);
$fromDropFolder = !$args;
if ($fromDropFolder) {
    $args = glob($importDir . '/*.{zip,json,gz}', GLOB_BRACE) ?: [];
    sort($args);
    if (!$args) {
        echo "nothing to import in import/\n";
        exit(0);
    }
}

/** Import one JSON document given as a string. */
function importJson(string $body, string $label): bool
{
    global $store;
    $hash = sha1($body);
    $dupe = db()->prepare("SELECT id FROM upload WHERE JSON_VALUE(headers, '$.\"x-content-sha1\"') = ?");
    $dupe->execute([$hash]);
    if ($id = $dupe->fetchColumn()) {
        echo "  skip  $label (identical file already imported as upload $id)\n";
        return true;
    }

    $file = date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.json.gz';
    $gz = gzopen($store . '/' . $file, 'wb6');
    for ($o = 0, $n = strlen($body); $o < $n; $o += 8 << 20) {
        gzwrite($gz, substr($body, $o, 8 << 20));
    }
    gzclose($gz);
    @chown($store . '/' . $file, 'nobody');
    @chgrp($store . '/' . $file, 'nogroup');

    $uploadId = createUpload(
        ['automation-name' => "manual: $label", 'x-source' => 'import.php', 'x-content-sha1' => $hash],
        $file, strlen($body), 'cli'
    );
    $t = microtime(true);
    try {
        $scanner = new JsonScanner($body);
        [$received, $new, $summary] = landPoints($uploadId, $scanner->points());
    } catch (Throwable $e) {
        finishUpload($uploadId, 'error', 0, 0, null, $e->getMessage());
        echo "  FAIL  $label: {$e->getMessage()}\n";
        return false;
    }
    finishUpload($uploadId, $received ? 'ok' : 'empty', $received, $new, $summary);
    printf("  ok    %s  upload=%d received=%d new=%d dup=%d  %.0fs  peak=%dMB\n",
        $label, $uploadId, $received, $new, $received - $new, microtime(true) - $t,
        memory_get_peak_usage(true) >> 20);
    foreach ($summary as $section => $names) {
        $n = count($names);
        $pts = array_sum(array_column($names, 'received'));
        echo "        $section: $n names, $pts rows\n";
    }
    return true;
}

foreach ($args as $path) {
    if (!is_file($path)) {
        echo "missing: $path\n";
        continue;
    }
    $base = basename($path);
    echo "$base\n";
    $ok = true;

    if (str_ends_with(strtolower($path), '.zip')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            echo "  FAIL  cannot open zip\n";
            continue;
        }
        for ($k = 0; $k < $zip->numFiles; $k++) {
            $entry = $zip->getNameIndex($k);
            if (!preg_match('/\.json$/i', $entry) || str_starts_with(basename($entry), '._')) {
                echo "  skip  $entry\n";
                continue;
            }
            $ok = importJson($zip->getFromIndex($k), "$base/$entry") && $ok;
        }
        $zip->close();
    } else {
        $body = str_ends_with($path, '.gz') ? gzdecode(file_get_contents($path)) : file_get_contents($path);
        $ok = importJson($body, $base);
    }

    // The compressed copy in incoming/ is the archive; the original isn't needed any more.
    if ($ok && $fromDropFolder) {
        unlink($path);
    }
}

echo "\nsorting into typed tables …\n";
$stats = sortPending(600);
echo $stats === null ? "sorter busy – run php sort.php later\n" : json_encode($stats) . "\n";
