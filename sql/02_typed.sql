-- Stage 2: typed tables, filled from `raw` by lib/sort.php.
-- All timestamps UTC; tz = offset in minutes of the original reading (420 = +0700).
-- raw_id = the raw row the current values came from (newest delivery wins).

CREATE TABLE IF NOT EXISTS metric (
    id     SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(100) NOT NULL UNIQUE,
    unit   VARCHAR(32)  NULL,
    agg    ENUM('sum','avg') NOT NULL DEFAULT 'avg' COMMENT 'how values combine over time'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS source (
    id    SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Single-value metrics (steps, energy, SpO2, HRV, weight, ...), one row per metric and minute.
CREATE TABLE IF NOT EXISTS sample (
    metric_id  SMALLINT UNSIGNED NOT NULL,
    ts         DATETIME NOT NULL,
    tz         SMALLINT NOT NULL,
    qty        DOUBLE   NOT NULL,
    source_id  SMALLINT UNSIGNED NULL,
    raw_id     BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (metric_id, ts)
) ENGINE=InnoDB;

-- Heart rate per minute.
CREATE TABLE IF NOT EXISTS heart_rate (
    ts         DATETIME NOT NULL PRIMARY KEY,
    tz         SMALLINT NOT NULL,
    bpm_min    DOUBLE NOT NULL,
    bpm_avg    DOUBLE NOT NULL,
    bpm_max    DOUBLE NOT NULL,
    source_id  SMALLINT UNSIGNED NULL,
    raw_id     BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blood_pressure (
    ts         DATETIME NOT NULL PRIMARY KEY,
    tz         SMALLINT NOT NULL,
    systolic   DOUBLE NOT NULL,
    diastolic  DOUBLE NOT NULL,
    source_id  SMALLINT UNSIGNED NULL,
    raw_id     BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- One row per night (= local date the sleep ended). Per-source / per-format rows are merged.
CREATE TABLE IF NOT EXISTS sleep_night (
    night         DATE NOT NULL PRIMARY KEY,
    tz            SMALLINT NOT NULL,
    in_bed_start  DATETIME NULL,
    in_bed_end    DATETIME NULL,
    sleep_start   DATETIME NULL,
    sleep_end     DATETIME NULL,
    in_bed_h      DOUBLE NULL,
    asleep_h      DOUBLE NULL,
    core_h        DOUBLE NULL,
    deep_h        DOUBLE NULL,
    rem_h         DOUBLE NULL,
    awake_h       DOUBLE NULL,
    sources       VARCHAR(255) NULL,
    raw_id        BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ecg (
    ts              DATETIME NOT NULL PRIMARY KEY,
    tz              SMALLINT NOT NULL,
    ts_end          DATETIME NULL,
    classification  VARCHAR(64) NULL,
    avg_hr          DOUBLE NULL,
    sampling_hz     DOUBLE NULL,
    n_samples       INT NULL,
    voltages        LONGTEXT NULL COMMENT 'JSON array as delivered',
    source_id       SMALLINT UNSIGNED NULL,
    raw_id          BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS symptom (
    ts         DATETIME NOT NULL,
    name       VARCHAR(100) NOT NULL,
    tz         SMALLINT NOT NULL,
    ts_end     DATETIME NULL,
    severity   VARCHAR(32) NULL,
    source_id  SMALLINT UNSIGNED NULL,
    raw_id     BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (ts, name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS hr_notification (
    ts         DATETIME NOT NULL PRIMARY KEY,
    tz         SMALLINT NOT NULL,
    ts_end     DATETIME NULL,
    kind       VARCHAR(64) NULL,
    threshold  DOUBLE NULL,
    hr_data    JSON NULL,
    source_id  SMALLINT UNSIGNED NULL,
    raw_id     BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Workout summary columns for statistics; everything else (route, series, splits ...) in `details`.
CREATE TABLE IF NOT EXISTS workout (
    id            VARCHAR(64) NOT NULL PRIMARY KEY COMMENT 'Apple workout UUID',
    name          VARCHAR(100) NOT NULL,
    ts            DATETIME NOT NULL,
    tz            SMALLINT NOT NULL,
    ts_end        DATETIME NULL,
    duration_s    DOUBLE NULL,
    location      VARCHAR(32) NULL,
    distance_km   DOUBLE NULL,
    energy_kj     DOUBLE NULL COMMENT 'active energy',
    hr_avg        DOUBLE NULL,
    hr_max        DOUBLE NULL,
    elevation_m   DOUBLE NULL,
    route_points  INT NULL,
    source_id     SMALLINT UNSIGNED NULL,
    details       LONGTEXT NULL COMMENT 'full JSON as delivered',
    raw_id        BIGINT UNSIGNED NOT NULL,
    KEY ix_ts (ts)
) ENGINE=InnoDB;

-- Daily summary per metric and LOCAL day (charts over months/years read only this).
-- heart_rate and blood pressure appear here as metrics too
-- (heart_rate: min/avg/max of bpm; blood_pressure_systolic / _diastolic).
CREATE TABLE IF NOT EXISTS daily (
    metric_id  SMALLINT UNSIGNED NOT NULL,
    day        DATE NOT NULL,
    n          INT UNSIGNED NOT NULL,
    total      DOUBLE NULL COMMENT 'sum (meaningful for agg=sum metrics)',
    avg        DOUBLE NULL,
    min        DOUBLE NULL,
    max        DOUBLE NULL,
    PRIMARY KEY (metric_id, day)
) ENGINE=InnoDB;
