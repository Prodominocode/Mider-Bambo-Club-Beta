-- Migration: change subscribers.birthday from DATE to VARCHAR(20)
-- Run this in phpMyAdmin or via MySQL client.

ALTER TABLE `subscribers`
  MODIFY COLUMN `birthday` VARCHAR(20) NULL;

-- After running, existing dates will become string representations (YYYY-MM-DD). This preserves existing data.
