-- Migration: Create pending_credits table for temporary credit storage
-- Purpose: Store credits that need to wait 48 hours before becoming available

CREATE TABLE IF NOT EXISTS `pending_credits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscriber_id` INT NOT NULL,
  `mobile` VARCHAR(20) NOT NULL,
  `purchase_id` INT UNSIGNED NULL,
  `credit_amount` DECIMAL(10,1) NOT NULL COMMENT 'Credit amount in points (not Toman)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `transferred` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=pending, 1=transferred to main credit',
  `transferred_at` DATETIME NULL,
  `branch_id` INT NULL,
  `sales_center_id` INT NULL,
  `admin_number` VARCHAR(20) NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_subscriber_id` (`subscriber_id`),
  INDEX `idx_mobile` (`mobile`),
  INDEX `idx_purchase_id` (`purchase_id`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_transferred` (`transferred`),
  INDEX `idx_pending_lookup` (`subscriber_id`, `transferred`, `created_at`),
  CONSTRAINT `fk_pending_credits_subscriber` 
    FOREIGN KEY (`subscriber_id`) 
    REFERENCES `subscribers`(`id`) 
    ON DELETE CASCADE,
  CONSTRAINT `fk_pending_credits_purchase` 
    FOREIGN KEY (`purchase_id`) 
    REFERENCES `purchases`(`id`) 
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;