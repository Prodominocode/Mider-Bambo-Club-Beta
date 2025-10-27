<?php
echo "Starting test script...\n";

try {
    echo "Loading database connection...\n";
    require_once 'db.php';
    echo "Database connection loaded.\n";
    
    // Check if gift_credits table exists
    echo "Checking if gift_credits table exists...\n";
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'gift_credits'");
    $stmt->execute();
    $table_exists = $stmt->fetch();
    
    echo "Gift credits table exists: " . ($table_exists ? 'YES' : 'NO') . "\n";
    
    if ($table_exists) {
        echo "Getting table structure...\n";
        // Check table structure
        $stmt = $pdo->prepare("DESCRIBE gift_credits");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nTable structure:\n";
        foreach ($columns as $column) {
            echo "- " . $column['Field'] . " (" . $column['Type'] . ")\n";
        }
        
        echo "Counting records...\n";
        // Check if there are any records
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM gift_credits");
        $stmt->execute();
        $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "\nTotal records in gift_credits: " . $count_result['total'] . "\n";
        
        if ($count_result['total'] > 0) {
            echo "Fetching sample records...\n";
            // Show sample records
            $stmt = $pdo->prepare("SELECT * FROM gift_credits ORDER BY created_at DESC LIMIT 5");
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\nSample records:\n";
            foreach ($records as $record) {
                echo "- ID: " . $record['id'] . ", Mobile: " . $record['mobile'] . ", Gift Amount: " . $record['gift_amount_toman'] . ", Credit: " . $record['credit_amount'] . ", Active: " . $record['active'] . "\n";
            }
        }
    } else {
        echo "\nChecking subscribers table for reference...\n";
        $stmt = $pdo->prepare("SELECT mobile FROM subscribers LIMIT 1");
        $stmt->execute();
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($subscriber) {
            echo "Found subscriber with mobile: " . $subscriber['mobile'] . "\n";
            echo "Creating gift_credits table and adding test data...\n";
            
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
            
            // Add test data for the found subscriber
            $stmt = $pdo->prepare("
                INSERT INTO gift_credits (mobile, credit_amount, gift_amount_toman, notes, active, created_at) 
                VALUES (?, ?, ?, ?, 1, NOW())
            ");
            
            $stmt->execute([
                $subscriber['mobile'], 
                50000, 
                250000, 
                'Test gift credit for debugging'
            ]);
            
            echo "Test gift credit added for mobile: " . $subscriber['mobile'] . "\n";
            echo "Gift amount: 250,000 Toman\n";
            echo "Credit amount: 50,000 points\n";
        }
    }
    
    echo "Test completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>