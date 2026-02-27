CREATE TABLE IF NOT EXISTS `PREFIX_collectlogs_js_error` (
    `id_collectlogs_js_error` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(11) UNSIGNED NOT NULL,
    `id_lang` INT(11) UNSIGNED NULL,
    `id_customer` INT(11) UNSIGNED NULL,
    `customer_hash` CHAR(40) NULL,
    `visitor_id` VARCHAR(64) NULL,
    `severity` ENUM('info', 'warn', 'error', 'fatal') NOT NULL DEFAULT 'error',
    `message` TEXT NOT NULL,
    `error_type` VARCHAR(128) NOT NULL,
    `url` TEXT NOT NULL,
    `referrer` TEXT NULL,
    `user_agent` TEXT NOT NULL,
    `dt_iso` DATETIME NOT NULL,
    `stack_trace_json` LONGTEXT NULL,
    `extra_json` LONGTEXT NULL,
    `script_url` TEXT NULL,
    `line` INT(11) UNSIGNED NULL,
    `column` INT(11) UNSIGNED NULL,
    `fingerprint` CHAR(40) NOT NULL,
    `occurrences` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `first_seen` DATETIME NOT NULL,
    `last_seen` DATETIME NOT NULL,
    `status` ENUM('open', 'ignored', 'resolved') NOT NULL DEFAULT 'open',
    PRIMARY KEY (`id_collectlogs_js_error`),
    KEY `clje_shop_date` (`id_shop`, `last_seen`),
    KEY `clje_fingerprint` (`fingerprint`),
    KEY `clje_status` (`status`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE COLLATE=COLLATE_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_collectlogs_js_rate_limit` (
    `rate_key` CHAR(64) NOT NULL,
    `bucket` INT(11) UNSIGNED NOT NULL,
    `hits` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`rate_key`, `bucket`),
    KEY `cljrl_date_upd` (`date_upd`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE COLLATE=COLLATE_TYPE;
