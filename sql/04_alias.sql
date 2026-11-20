-- Same metric delivered under different names by different app versions.
CREATE TABLE IF NOT EXISTS metric_alias (
    alias  VARCHAR(100) NOT NULL PRIMARY KEY COMMENT 'name as delivered',
    name   VARCHAR(100) NOT NULL COMMENT 'canonical metric.name'
) ENGINE=InnoDB;
INSERT IGNORE INTO metric_alias VALUES ('dietary_caffeine', 'caffeine');   -- phone app 7.x vs iPad app 10.x
