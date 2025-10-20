-- Migration: add admin_number column to subscribers
ALTER TABLE `subscribers`
  ADD COLUMN `admin_number` VARCHAR(20) NULL AFTER `birthday`;
