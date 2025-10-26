-- Migration: Add advisor support to purchases and credit_usage tables
-- This migration adds advisor_id columns to properly link transactions with advisors

-- Add advisor_id to purchases table
ALTER TABLE `purchases` 
ADD COLUMN `advisor_id` INT NULL AFTER `sales_center_id`,
ADD INDEX `idx_purchases_advisor` (`advisor_id`),
ADD CONSTRAINT `fk_purchases_advisor` 
    FOREIGN KEY (`advisor_id`) 
    REFERENCES `advisors`(`id`) 
    ON DELETE SET NULL 
    ON UPDATE CASCADE;

-- Add advisor_id to credit_usage table
ALTER TABLE `credit_usage` 
ADD COLUMN `advisor_id` INT NULL AFTER `sales_center_id`,
ADD INDEX `idx_credit_usage_advisor` (`advisor_id`),
ADD CONSTRAINT `fk_credit_usage_advisor` 
    FOREIGN KEY (`advisor_id`) 
    REFERENCES `advisors`(`id`) 
    ON DELETE SET NULL 
    ON UPDATE CASCADE;

-- Add compound indexes for better query performance
ALTER TABLE `purchases` 
ADD INDEX `idx_purchases_branch_center_advisor` (`branch_id`, `sales_center_id`, `advisor_id`);

ALTER TABLE `credit_usage` 
ADD INDEX `idx_credit_usage_branch_center_advisor` (`branch_id`, `sales_center_id`, `advisor_id`);

-- Verify the changes
DESCRIBE purchases;
DESCRIBE credit_usage;