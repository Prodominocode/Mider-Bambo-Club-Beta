-- Migration: Add branch support to existing tables

-- Add branch_id to subscribers table
ALTER TABLE `subscribers` 
ADD COLUMN `branch_id` TINYINT NOT NULL DEFAULT 1 AFTER `admin_number`,
ADD INDEX (`branch_id`);

-- Add branch_id to purchases table
ALTER TABLE `purchases` 
ADD COLUMN `branch_id` TINYINT NOT NULL DEFAULT 1 AFTER `admin_number`,
ADD COLUMN `sales_center_id` TINYINT NOT NULL DEFAULT 1 AFTER `branch_id`,
ADD INDEX (`branch_id`),
ADD INDEX (`sales_center_id`);

-- Add branch_id to credit_usage table (if it exists)
ALTER TABLE `credit_usage` 
ADD COLUMN `branch_id` TINYINT NOT NULL DEFAULT 1 AFTER `admin_number`,
ADD COLUMN `sales_center_id` TINYINT NOT NULL DEFAULT 1 AFTER `branch_id`,
ADD INDEX (`branch_id`),
ADD INDEX (`sales_center_id`);