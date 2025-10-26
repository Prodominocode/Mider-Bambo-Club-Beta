-- Migration: create purchase_advisors table
-- This table links purchases to multiple advisors

CREATE TABLE IF NOT EXISTS purchase_advisors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    purchase_id INT UNSIGNED NOT NULL,
    advisor_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    UNIQUE KEY unique_purchase_advisor (purchase_id, advisor_id),
    INDEX idx_purchase_id (purchase_id),
    INDEX idx_advisor_id (advisor_id),
    
    CONSTRAINT fk_purchase_advisors_purchase 
        FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_advisors_advisor 
        FOREIGN KEY (advisor_id) REFERENCES advisors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;