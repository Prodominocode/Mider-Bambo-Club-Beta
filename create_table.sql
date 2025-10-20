-- Run this SQL in phpMyAdmin to create the subscribers table
CREATE TABLE `subscribers` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(100),
  `mobile` VARCHAR(20) NOT NULL UNIQUE,
  `otp_code` VARCHAR(10),
  `verified` TINYINT(1) DEFAULT 0,
  `credit` INT DEFAULT 10,
  `email` VARCHAR(100),
  `city` VARCHAR(100),
  `birthday` VARCHAR(20),
  `admin_number` VARCHAR(20),
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
