-- Run this script to create the advisors table
USE miderclub; -- Replace with your actual database name

-- Create advisors table for customer advisors management
CREATE TABLE IF NOT EXISTS advisors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    mobile_number VARCHAR(20) NULL,
    branch_id INT NOT NULL DEFAULT 1,
    sales_centers JSON NULL COMMENT 'JSON array of sales center IDs that this advisor covers',
    manager_mobile VARCHAR(20) NOT NULL COMMENT 'Mobile number of the manager who created this advisor',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_manager_mobile (manager_mobile),
    INDEX idx_branch_id (branch_id),
    INDEX idx_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify table creation
SHOW TABLES LIKE 'advisors';
DESCRIBE advisors;