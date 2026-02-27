CREATE TABLE IF NOT EXISTS `PREFIX_collectlogs_js_rate_limit` (
    `dimension` VARCHAR(96) NOT NULL,
    `bucket` CHAR(12) NOT NULL,
    `count` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`dimension`, `bucket`),
    KEY `collectlogs_js_rate_bucket` (`bucket`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE COLLATE=COLLATE_TYPE;
