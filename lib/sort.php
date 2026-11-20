<?php
/**
 * Stage 2: sort pending `raw` rows into the typed tables, then refresh `daily`.
 *
 * raw.sorted: 0 pending | 1 sorted | 2 unmapped (unknown layout) | 3 sorted, but had extra fields
 *
 * Rules
 *  - single values / heart rate: one row per minute; blood pressure per second;
 *    sleep per night (local date of sleep end), sleep segments per start (night = date of start + 12 h);
 *    workouts by Apple UUID.
 *  - the newest delivery (highest raw.id) wins; a missing source never erases a known one.
 *  - sleep: rows of one night (per source, per app version, re-deliveries) are merged.
 *
 * Everything is set-based SQL, processed in id windows.
 */

declare(strict_types=1);

require_once __DIR__ . '/raw.php';

const SORT_WINDOW = 100000;

/** Metrics that add up over time (daily total is meaningful). Everything else averages. */
const SUM_METRICS = [
    'active_energy', 'basal_energy_burned', 'step_count', 'walking_running_distance', 'flights_climbed',
    'apple_exercise_time', 'apple_stand_time', 'apple_stand_hour', 'apple_move_time', 'cycling_distance',
    'swimming_distance', 'swimming_stroke_count', 'distance_downhill_snow_sports', 'wheelchair_distance',
    'push_count', 'mindful_minutes', 'handwashing', 'toothbrushing', 'caffeine', 'calcium', 'carbohydrates',
    'chloride', 'chromium', 'copper', 'fiber', 'folate', 'iodine', 'iron', 'magnesium', 'manganese',
    'molybdenum', 'monounsaturated_fat', 'niacin', 'pantothenic_acid', 'polyunsaturated_fat', 'potassium',
    'protein', 'riboflavin', 'saturated_fat', 'selenium', 'sodium', 'thiamin', 'total_fat', 'zinc', 'biotin',
    'number_of_times_fallen', 'sexual_activity', 'inhaler_usage', 'insulin_delivery',
    'high_heart_rate_notifications', 'low_heart_rate_notifications', 'irregular_heart_rate_notifications',
];

/** Metrics handled by their own table instead of `sample`. */
const SPECIAL_METRICS = ['heart_rate', 'blood_pressure', 'sleep_analysis'];

/** SQL: "2026-11-13 09:19:45 +0700" (JSON path) -> UTC DATETIME, NULL if absent. */
function sqlUtc(string $path): string
{
    $v = "JSON_VALUE(r.payload, '$path')";
    return "IF($v IS NULL OR $v = '', NULL,
              STR_TO_DATE(LEFT($v, 19), '%Y-%m-%d %H:%i:%s')
              - INTERVAL (IF(SUBSTRING($v, 21, 1) = '-', -1, 1)
                          * (SUBSTRING($v, 22, 2) * 60 + SUBSTRING($v, 24, 2))) MINUTE)";
}

function sqlNum(string $path): string
{
    return "CAST(JSON_VALUE(r.payload, '$path') AS DOUBLE)";
}

/** Assignment list for "newest wins, keep known source": raw_id must come last. */
function newerWins(string $t, array $cols, bool $withSource = true): string
{
    $set = [];
    foreach ($cols as $c) {
        $set[] = "$t.$c = IF(VALUES(raw_id) >= $t.raw_id, VALUES($c), $t.$c)";
    }
    if ($withSource) {
        $set[] = "$t.source_id = IF(VALUES(raw_id) >= $t.raw_id, COALESCE(VALUES(source_id), $t.source_id),
                                     COALESCE($t.source_id, VALUES(source_id)))";
    }
    $set[] = "$t.raw_id = GREATEST($t.raw_id, VALUES(raw_id))";
    return implode(",\n  ", $set);
}

function sortWindow(PDO $pdo, int $from, int $to, array &$touched, array &$stats): void
{
    $w = "r.id BETWEEN $from AND $to AND r.sorted = 0";
    $special = "'" . implode("','", SPECIAL_METRICS) . "'";
    $sums = "'" . implode("','", SUM_METRICS) . "'";
    $src = "LEFT JOIN source s ON s.name = COALESCE(JSON_VALUE(r.payload, '$.source.name'), NULLIF(r.source, ''))";
    // Same metric under different names (app versions): metric_alias maps raw name -> canonical name.
    $al  = "LEFT JOIN metric_alias al ON al.alias = r.name";
    $cn  = "COALESCE(al.name, r.name)";

    // Remember which metric/day ranges change (for `daily`).
    $q = $pdo->query("SELECT $cn name, MIN(r.ts_utc) a, MAX(r.ts_utc) b FROM raw r $al
                      WHERE $w AND r.section = 'metrics' AND r.ts_utc IS NOT NULL GROUP BY $cn");
    foreach ($q as $row) {
        $t = &$touched[$row['name']];
        $t = [min($t[0] ?? $row['a'], $row['a']), max($t[1] ?? $row['b'], $row['b'])];
        unset($t);
    }

    // Registries.
    $pdo->exec("INSERT IGNORE INTO metric (name, unit, agg)
        SELECT $cn, MIN(r.units),
               IF($cn IN ($sums) OR $cn LIKE 'dietary\\_%' OR $cn LIKE 'vitamin\\_%', 'sum', 'avg')
        FROM raw r $al WHERE $w AND r.section = 'metrics' AND r.name NOT IN ($special) GROUP BY $cn");
    $pdo->exec("INSERT IGNORE INTO source (name)
        SELECT DISTINCT COALESCE(JSON_VALUE(r.payload, '$.source.name'), NULLIF(r.source, '')) n
        FROM raw r WHERE $w HAVING n IS NOT NULL");

    // --- single values -------------------------------------------------------
    $isSample = "r.section = 'metrics' AND r.name NOT IN ($special) AND r.ts_utc IS NOT NULL
                 AND JSON_VALUE(r.payload, '$.qty') IS NOT NULL";
    $stats['sample'] = ($stats['sample'] ?? 0) + $pdo->exec("
        INSERT INTO sample (metric_id, ts, tz, qty, source_id, raw_id)
        SELECT m.id, DATE_FORMAT(r.ts_utc, '%Y-%m-%d %H:%i:00'), r.tz_offset, " . sqlNum('$.qty') . ", s.id, r.id
        FROM raw r $al JOIN metric m ON m.name = $cn $src
        WHERE $w AND $isSample ORDER BY r.id
        ON DUPLICATE KEY UPDATE " . newerWins('sample', ['tz', 'qty']));
    // known fields: date, qty, source (+ start/end from newer app versions)
    $pdo->exec("UPDATE raw r SET r.sorted = IF(JSON_LENGTH(r.payload) >
            JSON_CONTAINS_PATH(r.payload, 'one', '$.date') + JSON_CONTAINS_PATH(r.payload, 'one', '$.qty')
          + JSON_CONTAINS_PATH(r.payload, 'one', '$.source') + JSON_CONTAINS_PATH(r.payload, 'one', '$.start')
          + JSON_CONTAINS_PATH(r.payload, 'one', '$.end'), 3, 1)
        WHERE $w AND $isSample");

    // --- heart rate (several readings in one minute are combined) ------------
    $isHr = "r.section = 'metrics' AND r.name = 'heart_rate' AND r.ts_utc IS NOT NULL
             AND JSON_VALUE(r.payload, '$.Avg') IS NOT NULL";
    $stats['heart_rate'] = ($stats['heart_rate'] ?? 0) + $pdo->exec("
        INSERT INTO heart_rate (ts, tz, bpm_min, bpm_avg, bpm_max, source_id, raw_id)
        SELECT * FROM (
          SELECT DATE_FORMAT(r.ts_utc, '%Y-%m-%d %H:%i:00') m, MIN(r.tz_offset) tz,
                 MIN(COALESCE(" . sqlNum('$.Min') . ", " . sqlNum('$.Avg') . ")) mn, AVG(" . sqlNum('$.Avg') . ") av,
                 MAX(COALESCE(" . sqlNum('$.Max') . ", " . sqlNum('$.Avg') . ")) mx, MAX(s.id) sid, MAX(r.id) rid
          FROM raw r $src WHERE $w AND $isHr GROUP BY m) g ORDER BY rid
        ON DUPLICATE KEY UPDATE " . newerWins('heart_rate', ['tz', 'bpm_min', 'bpm_avg', 'bpm_max']));
    $pdo->exec("UPDATE raw r SET r.sorted = 1 WHERE $w AND $isHr");

    // --- blood pressure (seconds dropped: auto uploads have them, manual exports don't -> same reading once) ---
    $isBp = "r.section = 'metrics' AND r.name = 'blood_pressure' AND r.ts_utc IS NOT NULL
             AND JSON_VALUE(r.payload, '$.systolic') IS NOT NULL";
    $stats['blood_pressure'] = ($stats['blood_pressure'] ?? 0) + $pdo->exec("
        INSERT INTO blood_pressure (ts, tz, systolic, diastolic, source_id, raw_id)
        SELECT r.ts_utc - INTERVAL SECOND(r.ts_utc) SECOND, r.tz_offset, " . sqlNum('$.systolic') . ", " . sqlNum('$.diastolic') . ", s.id, r.id
        FROM raw r $src WHERE $w AND $isBp ORDER BY r.id
        ON DUPLICATE KEY UPDATE " . newerWins('blood_pressure', ['tz', 'systolic', 'diastolic']));
    $pdo->exec("UPDATE raw r SET r.sorted = 1 WHERE $w AND $isBp");

    // --- sleep: nightly summaries, merged per night ----------------------------
    $isSleep = "r.section = 'metrics' AND r.name = 'sleep_analysis' AND JSON_VALUE(r.payload, '$.inBed') IS NOT NULL";
    $endStr = "COALESCE(NULLIF(JSON_VALUE(r.payload, '$.sleepEnd'), ''), NULLIF(JSON_VALUE(r.payload, '$.inBedEnd'), ''),
                        JSON_VALUE(r.payload, '$.date'))";
    $hours = fn(string $f) => "NULLIF(" . sqlNum('$.' . $f) . ", 0)";
    $least = fn(string $c) => "sleep_night.$c = LEAST(COALESCE(sleep_night.$c, VALUES($c)), COALESCE(VALUES($c), sleep_night.$c))";
    $great = fn(string $c) => "sleep_night.$c = GREATEST(COALESCE(sleep_night.$c, VALUES($c)), COALESCE(VALUES($c), sleep_night.$c))";
    $stats['sleep'] = ($stats['sleep'] ?? 0) + $pdo->exec("
        INSERT INTO sleep_night (night, tz, in_bed_start, in_bed_end, sleep_start, sleep_end,
                                 in_bed_h, asleep_h, core_h, deep_h, rem_h, awake_h, sources, raw_id)
        SELECT LEFT($endStr, 10), r.tz_offset,
               " . sqlUtc('$.inBedStart') . ", " . sqlUtc('$.inBedEnd') . ",
               " . sqlUtc('$.sleepStart') . ", " . sqlUtc('$.sleepEnd') . ",
               {$hours('inBed')}, NULLIF(GREATEST(COALESCE(" . sqlNum('$.asleep') . ", 0),
                                                  COALESCE(" . sqlNum('$.totalSleep') . ", 0)), 0),
               {$hours('core')}, {$hours('deep')}, {$hours('rem')}, {$hours('awake')},
               NULLIF(r.source, ''), r.id
        FROM raw r WHERE $w AND $isSleep AND $endStr IS NOT NULL ORDER BY r.id
        ON DUPLICATE KEY UPDATE
          {$least('in_bed_start')}, {$great('in_bed_end')}, {$least('sleep_start')}, {$great('sleep_end')},
          {$great('in_bed_h')}, {$great('asleep_h')}, {$great('core_h')}, {$great('deep_h')},
          {$great('rem_h')}, {$great('awake_h')},
          sleep_night.sources = LEFT(IF(VALUES(sources) IS NULL
                                        OR LOCATE(VALUES(sources), COALESCE(sleep_night.sources, '')) > 0,
                                        sleep_night.sources, CONCAT_WS('|', sleep_night.sources, VALUES(sources))), 255),
          sleep_night.raw_id = GREATEST(sleep_night.raw_id, VALUES(raw_id))");
    $pdo->exec("UPDATE raw r SET r.sorted = 1 WHERE $w AND $isSleep AND $endStr IS NOT NULL");

    // --- sleep: single segments ("Summarize data" off) -------------------------
    $isStage = "r.section = 'metrics' AND r.name = 'sleep_analysis' AND JSON_VALUE(r.payload, '$.value') IS NOT NULL
                AND r.ts_utc IS NOT NULL AND NULLIF(JSON_VALUE(r.payload, '$.endDate'), '') IS NOT NULL";
    $stats['sleep_stage'] = ($stats['sleep_stage'] ?? 0) + $pdo->exec("
        INSERT INTO sleep_stage (source_id, start_ts, value, end_ts, tz, night, hours, raw_id)
        SELECT COALESCE(s.id, 0), r.ts_utc, REPLACE(JSON_VALUE(r.payload, '$.value'), ' ', ''),
               " . sqlUtc('$.endDate') . ", r.tz_offset,
               DATE(r.ts_utc + INTERVAL r.tz_offset MINUTE + INTERVAL 12 HOUR), " . sqlNum('$.qty') . ", r.id
        FROM raw r $src WHERE $w AND $isStage ORDER BY r.id
        ON DUPLICATE KEY UPDATE " . newerWins('sleep_stage', ['end_ts', 'tz', 'night', 'hours'], false));
    $pdo->exec("UPDATE raw r SET r.sorted = 1 WHERE $w AND $isStage");

    // --- ECG ---------------------------------------------------------------------
    $isEcg = "r.section = 'ecg' AND r.ts_utc IS NOT NULL";
    $stats['ecg'] = ($stats['ecg'] ?? 0) + $pdo->exec("
        INSERT INTO ecg (ts, tz, ts_end, classification, avg_hr, sampling_hz, n_samples, voltages, source_id, raw_id)
        SELECT r.ts_utc, r.tz_offset, " . sqlUtc('$.end') . ", JSON_VALUE(r.payload, '$.classification'),
               " . sqlNum('$.averageHeartRate') . ", " . sqlNum('$.samplingFrequency') . ",
               JSON_VALUE(r.payload, '$.numberOfVoltageMeasurements'),
               JSON_EXTRACT(r.payload, '$.voltageMeasurements'), s.id, r.id
        FROM raw r $src WHERE $w AND $isEcg ORDER BY r.id
        ON DUPLICATE KEY UPDATE " . newerWins('ecg', ['tz', 'ts_end', 'classification', 'avg_hr', 'sampling_hz', 'n_samples', 'voltages']));
    $pdo->exec("UPDATE raw r SET r.sorted = 1 WHERE $w AND $isEcg");

    // --- symptoms ------------------------------------------------------------------
    $isSym = "r.section = 'symptoms' AND r.ts_utc IS NOT NULL";
    $stats['symptom'] = ($stats['symptom'] ?? 0) + $pdo->exec("
        INSERT INTO symptom (ts, name, tz, ts_end, severity, source_id, raw_id)
        SELECT r.ts_utc, r.name, r.tz_offset, " . sqlUtc('$.end') . ", JSON_VALUE(r.payload, '$.severity'), s.id, r.id
        FROM raw r $src WHERE $w AND $isSym ORDER BY r.id
        ON DUPLICATE KEY UPDATE " . newerWins('symptom', ['tz', 'ts_end', 'severity']));
    $pdo->exec("UPDATE raw r SET r.sorted = 1 WHERE $w AND $isSym");

    // --- heart rate notifications --------------------------------------------------
    $isHrn = "r.section = 'heartRateNotifications' AND r.ts_utc IS NOT NULL";
    $stats['hr_notification'] = ($stats['hr_notification'] ?? 0) + $pdo->exec("
        INSERT INTO hr_notification (ts, tz, ts_end, kind, threshold, hr_data, source_id, raw_id)
        SELECT r.ts_utc, r.tz_offset, " . sqlUtc('$.end') . ", JSON_VALUE(r.payload, '$.heartNotification'),
               " . sqlNum('$.threshold') . ", JSON_EXTRACT(r.payload, '$.heartRateData'), s.id, r.id
        FROM raw r $src WHERE $w AND $isHrn ORDER BY r.id
        ON DUPLICATE KEY UPDATE " . newerWins('hr_notification', ['tz', 'ts_end', 'kind', 'threshold', 'hr_data']));
    $pdo->exec("UPDATE raw r SET r.sorted = 1 WHERE $w AND $isHrn");

    // --- workouts --------------------------------------------------------------------
    $isWo = "r.section = 'workouts' AND r.ts_utc IS NOT NULL";
    $unitQty = function (string $obj, array $factors): string {
        $cases = [];
        foreach ($factors as $unit => $f) {
            $cases[] = "WHEN '$unit' THEN " . sqlNum("$obj.qty") . " * $f";
        }
        return "CASE JSON_VALUE(r.payload, '$obj.units') " . implode(' ', $cases) . " ELSE " . sqlNum("$obj.qty") . " END";
    };
    $stats['workout'] = ($stats['workout'] ?? 0) + $pdo->exec("
        INSERT INTO workout (id, name, ts, tz, ts_end, duration_s, location, distance_km, energy_kj,
                             hr_avg, hr_max, elevation_m, route_points, source_id, details, raw_id)
        SELECT COALESCE(JSON_VALUE(r.payload, '$.id'), CONCAT(r.name, '@', r.ts_utc)), r.name, r.ts_utc, r.tz_offset,
               " . sqlUtc('$.end') . ", " . sqlNum('$.duration') . ", JSON_VALUE(r.payload, '$.location'),
               " . $unitQty('$.distance', ['km' => 1, 'mi' => 1.609344, 'm' => 0.001]) . ",
               " . $unitQty('$.activeEnergyBurned', ['kJ' => 1, 'kcal' => 4.184]) . ",
               COALESCE(" . sqlNum('$.heartRate.avg.qty') . ", " . sqlNum('$.avgHeartRate.qty') . "),
               COALESCE(" . sqlNum('$.heartRate.max.qty') . ", " . sqlNum('$.maxHeartRate.qty') . "),
               " . $unitQty('$.elevationUp', ['m' => 1, 'ft' => 0.3048]) . ",
               JSON_LENGTH(r.payload, '$.route'), s.id, r.payload, r.id
        FROM raw r $src WHERE $w AND $isWo ORDER BY r.id
        ON DUPLICATE KEY UPDATE " . newerWins('workout', ['name', 'ts', 'tz', 'ts_end', 'duration_s', 'location', 'distance_km',
            'energy_kj', 'hr_avg', 'hr_max', 'elevation_m', 'route_points', 'details']));
    $pdo->exec("UPDATE raw r SET r.sorted = 1 WHERE $w AND $isWo");

    // --- anything left has an unknown layout ------------------------------------------
    $stats['unmapped'] = ($stats['unmapped'] ?? 0) + $pdo->exec("UPDATE raw r SET r.sorted = 2 WHERE $w");
}

/** Rebuild `daily` for the given metric => [fromUtc, toUtc] ranges. */
function refreshDaily(PDO $pdo, array $touched): int
{
    $rows = 0;
    $ids = $pdo->query("SELECT name, id FROM metric")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ensure = function (string $name, ?string $unit) use ($pdo, &$ids): int {
        if (!isset($ids[$name])) {
            $pdo->prepare("INSERT IGNORE INTO metric (name, unit, agg) VALUES (?, ?, 'avg')")->execute([$name, $unit]);
            $ids[$name] = (int)$pdo->query("SELECT id FROM metric WHERE name = " . $pdo->quote($name))->fetchColumn();
        }
        return (int)$ids[$name];
    };

    foreach ($touched as $name => [$a, $b]) {
        // local days can be up to ±14 h away from UTC
        $from = (new DateTimeImmutable($a))->modify('-1 day')->format('Y-m-d');
        $to   = (new DateTimeImmutable($b))->modify('+1 day')->format('Y-m-d');
        $range = "BETWEEN '$from' AND '$to'";
        $tsRange = "ts BETWEEN '$from' - INTERVAL 1 DAY AND '$to' + INTERVAL 2 DAY";
        $day = "DATE(ts + INTERVAL tz MINUTE)";

        $jobs = [];
        if ($name === 'heart_rate') {
            $id = $ensure('heart_rate', 'count/min');
            $jobs[$id] = "SELECT $id, $day d, COUNT(*), NULL, AVG(bpm_avg), MIN(bpm_min), MAX(bpm_max)
                          FROM heart_rate WHERE $tsRange GROUP BY d";
        } elseif ($name === 'blood_pressure') {
            foreach (['systolic', 'diastolic'] as $col) {
                $id = $ensure("blood_pressure_$col", 'mmHg');
                $jobs[$id] = "SELECT $id, $day d, COUNT(*), NULL, AVG($col), MIN($col), MAX($col)
                              FROM blood_pressure WHERE $tsRange GROUP BY d";
            }
        } elseif ($name !== 'sleep_analysis' && isset($ids[$name])) {
            $id = (int)$ids[$name];
            $jobs[$id] = "SELECT $id, $day d, COUNT(*), SUM(qty), AVG(qty), MIN(qty), MAX(qty)
                          FROM sample WHERE metric_id = $id AND $tsRange GROUP BY d";
        }
        foreach ($jobs as $id => $select) {
            $pdo->exec("DELETE FROM daily WHERE metric_id = $id AND day $range");
            $rows += $pdo->exec("INSERT INTO daily (metric_id, day, n, total, avg, min, max)
                                 SELECT * FROM ($select) x WHERE d $range");
        }
    }
    return $rows;
}

/**
 * Sort everything pending. Only one sorter runs at a time; a caller that can't get
 * the lock within $waitSeconds returns null (its rows are picked up by the next run).
 */
function sortPending(int $waitSeconds = 0, ?callable $progress = null): ?array
{
    $pdo = db();
    if ((int)$pdo->query("SELECT GET_LOCK('health_sort', $waitSeconds)")->fetchColumn() !== 1) {
        return null;
    }
    $t = microtime(true);
    $stats = [];
    $touched = [];
    try {
        // Loop: rows may arrive while sorting.
        while (true) {
            $r = $pdo->query("SELECT MIN(id) a, MAX(id) b FROM raw WHERE sorted = 0")->fetch();
            if ($r['a'] === null) {
                break;
            }
            for ($from = (int)$r['a']; $from <= (int)$r['b']; $from += SORT_WINDOW) {
                $to = min($from + SORT_WINDOW - 1, (int)$r['b']);
                $pdo->beginTransaction();
                sortWindow($pdo, $from, $to, $touched, $stats);
                $pdo->commit();
                if ($progress) {
                    $progress($to, (int)$r['b'], $stats);
                }
            }
        }
        $stats['daily_rows'] = $touched ? refreshDaily($pdo, $touched) : 0;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('health_sort')");
    }
    $stats['seconds'] = round(microtime(true) - $t, 1);
    return $stats;
}
