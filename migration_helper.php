<?php
/**
 * Auto-migration script for transaction deletion
 * This script automatically adds the required 'active' columns if they don't exist
 * Call this from admin.php before attempting any delete operations
 */

function ensure_active_columns_exist($pdo) {
    try {
        // Check and add active column to purchases table
        $stmt = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'active'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deleted'");
            $pdo->exec("ALTER TABLE purchases ADD INDEX idx_purchases_active (active)");
            error_log("Added active column to purchases table");
        }
        
        // Check and add active column to credit_usage table  
        $stmt = $pdo->query("SHOW COLUMNS FROM credit_usage LIKE 'active'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE credit_usage ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deleted'");
            $pdo->exec("ALTER TABLE credit_usage ADD INDEX idx_credit_usage_active (active)");
            error_log("Added active column to credit_usage table");
        }
        
        // Check and add active column to pending_credits table (if it exists)
        $stmt = $pdo->query("SHOW TABLES LIKE 'pending_credits'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("SHOW COLUMNS FROM pending_credits LIKE 'active'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE pending_credits ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status: 1=active, 0=deactivated'");
                $pdo->exec("ALTER TABLE pending_credits ADD INDEX idx_pending_credits_active (active)");
                error_log("Added active column to pending_credits table");
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error ensuring active columns exist: " . $e->getMessage());
        return false;
    }
}

// Function to safely check if a column exists
function column_exists($pdo, $table, $column) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Function to safely check if a table exists
function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}
?>