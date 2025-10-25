-- Add active column for soft delete functionality
ALTER TABLE `purchases` 
ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deleted';

ALTER TABLE `credit_usage` 
ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deleted';

-- Add indexes for better performance when filtering by active status
ALTER TABLE `purchases` ADD INDEX `idx_purchases_active` (`active`);
ALTER TABLE `credit_usage` ADD INDEX `idx_credit_usage_active` (`active`);