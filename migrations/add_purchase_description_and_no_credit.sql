-- Migration: add description and no_credit fields to purchases table
-- Add purchase description and no-credit flag fields

ALTER TABLE purchases 
ADD COLUMN description VARCHAR(500) NULL 
COMMENT 'Optional description for the purchase';

ALTER TABLE purchases 
ADD COLUMN no_credit TINYINT(1) NOT NULL DEFAULT 0 
COMMENT 'Flag indicating if this purchase does not grant credit to user (0=grants credit, 1=no credit)';

-- Add index for no_credit for performance
ALTER TABLE purchases 
ADD INDEX idx_no_credit (no_credit);