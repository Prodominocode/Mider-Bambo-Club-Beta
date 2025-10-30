-- Run this SQL script to ensure the database is ready for the improved transaction deletion
-- Execute these commands in phpMyAdmin or your MySQL client

-- 1. Ensure purchases table has active column
ALTER TABLE `purchases` 
ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deleted';

-- 2. Add index for active column if it doesn't exist
ALTER TABLE `purchases` 
ADD INDEX IF NOT EXISTS `idx_purchases_active` (`active`);

-- 3. Ensure pending_credits table has active column
ALTER TABLE `pending_credits` 
ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deactivated';

-- 4. Add index for pending_credits active column
ALTER TABLE `pending_credits` 
ADD INDEX IF NOT EXISTS `idx_pending_credits_active` (`active`);

-- 5. Update existing pending_credits lookup index to include active status
-- Drop old index if it exists
DROP INDEX IF EXISTS `idx_pending_lookup` ON `pending_credits`;

-- Add new optimized index
ALTER TABLE `pending_credits` 
ADD INDEX IF NOT EXISTS `idx_pending_lookup_active` (`subscriber_id`, `transferred`, `active`, `created_at`);

-- 6. Ensure credit_usage table has active column
ALTER TABLE `credit_usage` 
ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deleted';

-- 7. Add index for credit_usage active column
ALTER TABLE `credit_usage` 
ADD INDEX IF NOT EXISTS `idx_credit_usage_active` (`active`);

-- 8. Set all existing records to active=1 by default
UPDATE `purchases` SET `active` = 1 WHERE `active` IS NULL;
UPDATE `pending_credits` SET `active` = 1 WHERE `active` IS NULL;
UPDATE `credit_usage` SET `active` = 1 WHERE `active` IS NULL;
UPDATE `gift_credits` SET `active` = 1 WHERE `active` IS NULL;