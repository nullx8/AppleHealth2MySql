<?php
/**
 * CLI: recompute fingerprints of all `raw` rows with the current rules.
 * Rows that turn out to be duplicates of an older row are deleted (oldest kept),
 * and the upload counters are corrected.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../lib/raw.php';
ini_set('memory_limit', '4096M');

$pdo = db();
$seen = [];      // hash => id of first row
$update = [];    // id => new hash
$delete = [];

$q = $pdo->query("SELECT id, section, name, units, payload, hash FROM raw ORDER BY id", PDO::FETCH_ASSOC);
$q->setFetchMode(PDO::FETCH_ASSOC);
foreach ($q as $r) {
    $p = json_decode($r['payload'], true);
    $h = sha1(pointHashSource($r['section'], $r['name'], $r['units'], is_array($p) ? $p : ['value' => $p]), true);
    if (isset($seen[$h])) {
        $delete[] = (int)$r['id'];
        continue;
    }
    $seen[$h] = (int)$r['id'];
    if ($h !== $r['hash']) {
        $update[(int)$r['id']] = $h;
    }
}
unset($seen);

$pdo->beginTransaction();
if ($delete) {
    foreach (array_chunk($delete, 5000) as $chunk) {
        $pdo->exec("DELETE FROM raw WHERE id IN (" . implode(',', $chunk) . ")");
    }
}
// Two passes avoid transient unique-key clashes between swapped hashes.
$st = $pdo->prepare("UPDATE raw SET hash = ? WHERE id = ?");
foreach ($update as $id => $h) {
    $st->execute([random_bytes(20), $id]);
}
foreach ($update as $id => $h) {
    $st->execute([$h, $id]);
}
// Normalise the source column too.
$src = $pdo->prepare("UPDATE raw SET source = ? WHERE id = ?");
foreach ($pdo->query("SELECT id, source FROM raw WHERE source IS NOT NULL")->fetchAll() as $r) {
    $n = normalizeSource($r['source']);
    if ($n !== $r['source']) {
        $src->execute([$n, $r['id']]);
    }
}
$pdo->exec("UPDATE upload u SET points_new = (SELECT COUNT(*) FROM raw r WHERE r.upload_id = u.id)");
$pdo->commit();

printf("rehashed: %d changed, %d duplicates removed\n", count($update), count($delete));
