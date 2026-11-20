-- Stage 1: landing tables. Every request -> one `upload` row,
-- every data point inside it -> one `raw` row (exact duplicates skipped).

CREATE TABLE IF NOT EXISTS upload (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    received_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    file            VARCHAR(255) NULL COMMENT 'raw body in incoming/ (gzip)',
    automation_name VARCHAR(255) NULL,
    automation_id   VARCHAR(64)  NULL,
    session_id      VARCHAR(64)  NULL,
    user_agent      VARCHAR(255) NULL,
    remote_addr     VARCHAR(45)  NULL,
    bytes           INT UNSIGNED NOT NULL DEFAULT 0,
    points_received INT UNSIGNED NOT NULL DEFAULT 0,
    points_new      INT UNSIGNED NOT NULL DEFAULT 0,
    status          ENUM('receiving','ok','empty','error') NOT NULL DEFAULT 'receiving',
    error           TEXT NULL,
    summary         JSON NULL COMMENT 'per section/metric: received/new',
    headers         JSON NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS raw (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_id  INT UNSIGNED  NOT NULL,
    section    VARCHAR(32)   NOT NULL COMMENT 'metrics, workouts, ...',
    name       VARCHAR(100)  NOT NULL COMMENT 'metric name / workout name',
    units      VARCHAR(32)   NULL,
    ts_utc     DATETIME      NULL COMMENT 'date / startDate / start, converted to UTC',
    tz_offset  SMALLINT      NULL COMMENT 'minutes, e.g. 420 = +0700',
    source     VARCHAR(255)  NULL,
    payload    JSON          NOT NULL COMMENT 'data point exactly as delivered',
    hash       BINARY(20)    NOT NULL COMMENT 'sha1 of section|name|units|key-sorted payload',
    sorted     TINYINT       NOT NULL DEFAULT 0 COMMENT '0 pending, 1 sorted, 2 unmapped',
    UNIQUE KEY uq_hash (hash),
    KEY ix_pending (sorted, id),
    KEY ix_name_ts (section, name, ts_utc),
    KEY ix_upload (upload_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;
