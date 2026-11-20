<?php
/**
 * CLI: land existing recordings from incoming/ into `raw`.
 *
 *   php reimport.php                 all *.json / *.json.gz not yet in `upload`
 *   php reimport.php FILE [FILE...]  specific files (relative to incoming/)
 *
 * Plain *.json files (from the recording phase) are gzip-compressed afterwards;
 * their *.meta.json companion supplies headers and the original receive time.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/lib/raw.php';
ini_set('memory_limit', '4096M');
date_default_timezone_set(config()['timezone']);

$dir = config()['store_dir'];
$files = array_slice($argv, 1);
if (!$files) {
    $files = array_map('basename', array_filter(
        glob($dir . '/*.json*'),
        fn($f) => !str_ends_with($f, '.meta.json') && preg_match('/\.json(\.gz)?$/', $f)
    ));
    sort($files);
}

$known = db()->query("SELECT file FROM upload WHERE file IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

foreach ($files as $f) {
    $path = $dir . '/' . $f;
    $gzName = str_ends_with($f, '.gz') ? $f : $f . '.gz';
    if (in_array($gzName, $known, true)) {
        echo "skip  $f (already imported)\n";
        continue;
    }
    $body = str_ends_with($f, '.gz') ? gzdecode(file_get_contents($path)) : file_get_contents($path);

    $headers = [];
    $receivedAt = null;
    $remote = null;
    $metaPath = preg_replace('/\.json(\.gz)?$/', '.meta.json', $path);
    if (is_file($metaPath)) {
        $meta = json_decode(file_get_contents($metaPath), true) ?: [];
        $headers = $meta['headers'] ?? [];
        $remote = $meta['remote_addr'] ?? null;
        if (!empty($meta['received_at'])) {
            $receivedAt = (new DateTimeImmutable($meta['received_at']))->format('Y-m-d H:i:s');
        }
    }

    // Compress plain recordings so incoming/ holds one format.
    if (!str_ends_with($f, '.gz')) {
        file_put_contents($path . '.gz', gzencode($body, 6));
        chown($path . '.gz', fileowner($path));
        chgrp($path . '.gz', filegroup($path));
    }

    $t = microtime(true);
    $r = processBody($body, $headers, $gzName, $remote, $receivedAt);
    printf("%-5s %s  received=%d new=%d dup=%d  %.1fs%s\n",
        $r['ok'] ? 'ok' : 'FAIL', $f, $r['received'] ?? 0, $r['new'] ?? 0, $r['duplicate'] ?? 0,
        microtime(true) - $t, $r['ok'] ? '' : '  ' . $r['error']);

    if ($r['ok'] && !str_ends_with($f, '.gz')) {
        unlink($path);
        if (is_file($metaPath)) {
            unlink($metaPath);
        }
    }
}
