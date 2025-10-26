-- Migration: Add earned_credit column to purchase_advisors table
-- This column tracks the credit earned by each advisor for each specific purchase

ALTER TABLE purchase_advisors 
ADD COLUMN earned_credit DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Credit earned from this specific purchase';

-- Add index for earned_credit column
ALTER TABLE purchase_advisors 
ADD INDEX idx_earned_credit (earned_credit);