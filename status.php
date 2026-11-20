<?php
/**
 * CLI (admin): overview of uploads, sorting state and freshness per metric.
 *   php status.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib/raw.php';
date_default_timezone_set(config()['timezone']);
$pdo = db();

echo "== last uploads\n";
foreach ($pdo->query("SELECT id, received_at, automation_name a, status, points_received r, points_new n
                      FROM upload ORDER BY id DESC LIMIT 8") as $u) {
    printf("  #%-4d %s  %-7s recv %8d  new %8d  %s\n", $u['id'], $u['received_at'], $u['status'], $u['r'], $u['n'],
        mb_strimwidth((string)$u['a'], 0, 60, '…'));
}

echo "\n== raw sorting state\n";
$labels = [0 => 'pending', 1 => 'sorted', 2 => 'UNMAPPED', 3 => 'sorted, extra fields'];
foreach ($pdo->query("SELECT sorted, COUNT(*) n FROM raw GROUP BY sorted") as $r) {
    printf("  %-22s %d\n", $labels[$r['sorted']] ?? $r['sorted'], $r['n']);
}
foreach ($pdo->query("SELECT section, name, sorted, COUNT(*) n FROM raw WHERE sorted IN (2,3)
                      GROUP BY section, name, sorted") as $r) {
    printf("    %s %s/%s: %d\n", $labels[$r['sorted']], $r['section'], $r['name'], $r['n']);
}

echo "\n== latest data (local time)\n";
$rows = $pdo->query("
    SELECT m.name, MAX(d.day) last_day FROM daily d JOIN metric m ON m.id = d.metric_id GROUP BY m.name
    UNION ALL SELECT 'sleep_night', MAX(night) FROM sleep_night
    UNION ALL SELECT 'workout', DATE(MAX(ts + INTERVAL tz MINUTE)) FROM workout
    UNION ALL SELECT 'ecg', DATE(MAX(ts + INTERVAL tz MINUTE)) FROM ecg
    ORDER BY last_day DESC, name")->fetchAll();
foreach ($rows as $r) {
    printf("  %-36s %s\n", $r['name'], $r['last_day']);
}
