-- Create advisors table for customer advisors management
-- This table stores advisor information for the customer advisor feature

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

-- Add some sample data for testing (optional - can be commented out)
-- INSERT INTO advisors (full_name, mobile_number, branch_id, sales_centers, manager_mobile) VALUES
-- ('مشاور نمونه ۱', '09123456789', 1, '[1]', '09119246366'),
-- ('مشاور نمونه ۲', '09987654321', 2, '[1,2]', '09194467966');