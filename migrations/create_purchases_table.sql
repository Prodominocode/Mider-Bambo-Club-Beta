-- Migration: create purchases table
CREATE TABLE IF NOT EXISTS `purchases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscriber_id` INT NULL,
  `mobile` VARCHAR(20) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `admin_number` VARCHAR(20) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX (`mobile`),
  CONSTRAINT `fk_purchases_subscriber` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers`(`id`) ON DELETE SET NULL
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
