<?php
// Test script to verify purchase_advisors functionality
require_once 'db.php';

echo "=== Purchase Advisors Test Script ===\n\n";

try {
    // Check if purchase_advisors table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'purchase_advisors'");
    $stmt->execute();
    $tableExists = $stmt->rowCount() > 0;
    
    echo "1. purchase_advisors table exists: " . ($tableExists ? "YES" : "NO") . "\n";
    
    if (!$tableExists) {
        echo "   ERROR: purchase_advisors table does not exist. Please run the migration.\n";
        exit;
    }
    
    // Check table structure
    $stmt = $pdo->prepare("DESCRIBE purchase_advisors");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n2. Table structure:\n";
    foreach ($columns as $column) {
        echo "   - {$column['Field']}: {$column['Type']}\n";
    }
    
    // Check recent purchases
    $stmt = $pdo->prepare("
        SELECT p.id, p.mobile, p.amount, p.created_at,
               GROUP_CONCAT(pa.advisor_id) as advisor_ids,
               GROUP_CONCAT(a.full_name) as advisor_names
        FROM purchases p
        LEFT JOIN purchase_advisors pa ON p.id = pa.purchase_id
        LEFT JOIN advisors a ON pa.advisor_id = a.id
        WHERE DATE(p.created_at) >= CURDATE() - INTERVAL 7 DAYS
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n3. Recent purchases (last 7 days) with advisor assignments:\n";
    if (empty($purchases)) {
        echo "   No recent purchases found.\n";
    } else {
        foreach ($purchases as $purchase) {
            echo "   Purchase ID: {$purchase['id']}\n";
            echo "   Mobile: {$purchase['mobile']}\n";
            echo "   Amount: {$purchase['amount']}\n";
            echo "   Date: {$purchase['created_at']}\n";
            echo "   Advisor IDs: " . ($purchase['advisor_ids'] ?: 'None') . "\n";
            echo "   Advisor Names: " . ($purchase['advisor_names'] ?: 'None') . "\n";
            echo "   ---\n";
        }
    }
    
    // Check advisors table
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM advisors WHERE active = 1");
    $stmt->execute();
    $advisorCount = $stmt->fetchColumn();
    
    echo "\n4. Active advisors count: $advisorCount\n";
    
    if ($advisorCount > 0) {
        $stmt = $pdo->prepare("
            SELECT id, full_name, branch_id, 
                   CASE WHEN credit IS NOT NULL THEN credit ELSE 'Column not exists' END as credit
            FROM advisors 
            WHERE active = 1 
            LIMIT 5
        ");
        $stmt->execute();
        $advisors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\n5. Sample advisors:\n";
        foreach ($advisors as $advisor) {
            echo "   ID: {$advisor['id']}, Name: {$advisor['full_name']}, Branch: {$advisor['branch_id']}, Credit: {$advisor['credit']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>