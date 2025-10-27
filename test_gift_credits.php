<?php
require_once 'db.php';

try {
    // Check if gift_credits table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'gift_credits'");
    $stmt->execute();
    $table_exists = $stmt->fetch();
    
    echo "Gift credits table exists: " . ($table_exists ? 'YES' : 'NO') . "\n";
    
    if ($table_exists) {
        // Check table structure
        $stmt = $pdo->prepare("DESCRIBE gift_credits");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nTable structure:\n";
        foreach ($columns as $column) {
            echo "- " . $column['Field'] . " (" . $column['Type'] . ")\n";
        }
        
        // Check if there are any records
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM gift_credits");
        $stmt->execute();
        $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "\nTotal records in gift_credits: " . $count_result['total'] . "\n";
        
        // Show all records
        $stmt = $pdo->prepare("SELECT * FROM gift_credits ORDER BY created_at DESC");
        $stmt->execute();
        $all_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nAll records:\n";
        foreach ($all_records as $record) {
            echo "- ID: " . $record['id'] . ", Mobile: " . $record['mobile'] . ", Gift Amount: " . $record['gift_amount_toman'] . ", Credit: " . $record['credit_amount'] . ", Active: " . $record['active'] . "\n";
        }
        
    } else {
        echo "\nCreating gift_credits table...\n";
        
        $create_sql = "
            CREATE TABLE gift_credits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mobile VARCHAR(20) NOT NULL,
                credit_amount INT NOT NULL DEFAULT 0,
                gift_amount_toman INT NOT NULL DEFAULT 0,
                notes TEXT,
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_mobile_active (mobile, active),
                INDEX idx_created_at (created_at)
            )
        ";
        
        $pdo->exec($create_sql);
        echo "gift_credits table created successfully!\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>