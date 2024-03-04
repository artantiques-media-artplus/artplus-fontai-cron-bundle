SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

CREATE TABLE IF NOT EXISTS `cron`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `active` TINYINT(1) DEFAULT 0 NOT NULL,
    `active_from` DATETIME,
    `active_to` DATETIME,
    `name` VARCHAR(255) NOT NULL,
    `command` VARCHAR(255) NOT NULL,
    `type` TINYINT(1) NOT NULL,
    `interval` VARCHAR(3) NOT NULL,
    `days` VARCHAR(255),
    `priority_run` TINYINT(1) DEFAULT 0 NOT NULL,
    `last_run_at` DATETIME,
    `last_run_end_at` DATETIME,
    `last_is_error` TINYINT(1) DEFAULT 0 NOT NULL,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unique_name` (`name`)
) ENGINE=InnoDB;

INSERT INTO `cron` (`id`, `active`, `active_from`, `active_to`, `name`, `command`, `type`, `interval`, `days`, `priority_run`, `last_run_at`, `last_run_end_at`, `last_is_error`, `created_at`, `updated_at`) VALUES
(1, 1,  NULL,  NULL, 'Odesílání e-mailů',  'swiftmailer:spool:send', 0,  '2',  '| 2 | 3 | 4 | 5 | 6 | 7 | 1 |',  0,  NULL,  NULL,  0,  NOW(),  NOW())
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS `cron_error`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `cron_id` INTEGER NOT NULL,
    `error` TEXT NOT NULL,
    `created_at` DATETIME,
    PRIMARY KEY (`id`),
    INDEX `fi_n_error_FK_1` (`cron_id`),
    CONSTRAINT `cron_error_FK_1`
        FOREIGN KEY (`cron_id`)
        REFERENCES `cron` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB;