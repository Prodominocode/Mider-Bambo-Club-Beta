-- Add vcard_number column to subscribers table for Virtual Card support
-- Run this SQL to add virtual card functionality to the system

ALTER TABLE `subscribers` 
ADD COLUMN `vcard_number` VARCHAR(16) UNIQUE DEFAULT NULL COMMENT '16-digit virtual card number for vCard users';

-- Add index for fast vcard_number lookups
CREATE INDEX `idx_vcard_number` ON `subscribers` (`vcard_number`);

-- Optional: Add check constraint to ensure vcard_number is exactly 16 digits (MySQL 8.0+)
-- ALTER TABLE `subscribers` 
-- ADD CONSTRAINT `chk_vcard_number_format` 
-- CHECK (`vcard_number` IS NULL OR `vcard_number` REGEXP '^[0-9]{16}$');