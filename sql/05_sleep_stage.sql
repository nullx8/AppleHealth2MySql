-- Sleep segments (export with "Summarize data" off): InBed (iPhone, Sleep Focus) and
-- Asleep / Core / Deep / REM / Awake (Watch). One row per segment.
-- night = local date of (start + 12 h): everything starting after noon belongs to the next morning.
CREATE TABLE IF NOT EXISTS sleep_stage (
    source_id  INT UNSIGNED NOT NULL DEFAULT 0,
    start_ts   DATETIME NOT NULL COMMENT 'UTC',
    value      VARCHAR(16) NOT NULL COMMENT 'InBed, Asleep, Core, Deep, REM, Awake',
    end_ts     DATETIME NOT NULL COMMENT 'UTC',
    tz         SMALLINT NOT NULL,
    night      DATE NOT NULL,
    hours      DOUBLE NULL,
    raw_id     BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (source_id, start_ts, value),
    KEY night (night, value)
) ENGINE=InnoDB;
