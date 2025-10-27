-- Migration: create gift_credits table
CREATE TABLE IF NOT EXISTS `gift_credits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscriber_id` INT NULL,
  `mobile` VARCHAR(20) NOT NULL,
  `gift_amount_toman` DECIMAL(12,2) NOT NULL COMMENT 'Gift amount in Toman',
  `credit_amount` DECIMAL(10,2) NOT NULL COMMENT 'Credit amount (gift_amount_toman / 5000)',
  `admin_number` VARCHAR(20) NOT NULL COMMENT 'Admin who gave the gift',
  `notes` TEXT NULL COMMENT 'Optional notes about the gift',
  `active` TINYINT(1) DEFAULT 1 COMMENT 'Whether this gift credit is active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX (`mobile`),
  INDEX (`admin_number`),
  INDEX (`created_at`),
  INDEX (`active`),
  CONSTRAINT `fk_gift_credits_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;