-- Migration: add credit column to advisors table
-- This adds a numeric credit field with 1 decimal place precision

ALTER TABLE advisors 
ADD COLUMN credit DECIMAL(10,1) NOT NULL DEFAULT 0.0 
COMMENT 'Credit amount for advisor with 1 decimal place precision';

-- Add index for credit column for better performance on queries
ALTER TABLE advisors 
ADD INDEX idx_credit (credit);