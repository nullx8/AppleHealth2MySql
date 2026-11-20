<?php
/**
 * CLI (admin): sort all pending `raw` rows into the typed tables and refresh `daily`.
 *   php sort.php           sort pending rows
 *   php sort.php --all     re-sort everything (resets raw.sorted first; typed tables are upserted)
 *
 * ios.php runs the same sorter automatically after each upload.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib/sort.php';
ini_set('memory_limit', '1024M');
set_time_limit(0);

if (in_array('--all', $argv, true)) {
    $n = db()->exec("UPDATE raw SET sorted = 0 WHERE sorted <> 0");
    echo "reset $n rows to pending\n";
}

$stats = sortPending(600, function (int $done, int $max, array $s) {
    static $t0;
    $t0 ??= microtime(true);
    printf("  … id %d / %d  (%.0fs)\n", $done, $max, microtime(true) - $t0);
});
if ($stats === null) {
    echo "another sorter is running, try again later\n";
    exit(1);
}
foreach ($stats as $k => $v) {
    printf("%-16s %s\n", $k, $v);
}
if (($stats['unmapped'] ?? 0) > 0) {
    echo "\nUNMAPPED rows (unknown layout, kept in raw with sorted=2):\n";
    foreach (db()->query("SELECT section, name, COUNT(*) n FROM raw WHERE sorted = 2 GROUP BY section, name") as $r) {
        echo "  {$r['section']}/{$r['name']}: {$r['n']}\n";
    }
}
