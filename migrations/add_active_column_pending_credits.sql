-- Migration: Add active column to pending_credits table for soft delete functionality
-- This ensures pending credits can be deactivated without being processed by the cron job

ALTER TABLE `pending_credits` 
ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deactivated';

-- Add index for better performance when filtering by active status
ALTER TABLE `pending_credits` ADD INDEX `idx_pending_credits_active` (`active`);

-- Update the existing query index to include active status for optimal performance
ALTER TABLE `pending_credits` DROP INDEX `idx_pending_lookup`;
ALTER TABLE `pending_credits` ADD INDEX `idx_pending_lookup_active` (`subscriber_id`, `transferred`, `active`, `created_at`);