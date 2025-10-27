<?php
// Simple test page to isolate the issue
header('Content-Type: text/html; charset=utf-8');

echo "Testing basic PHP functionality...<br>";

try {
    echo "Including db.php...<br>";
    require_once 'db.php';
    echo "Database connection successful!<br>";
    
    echo "Testing basic query...<br>";
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    echo "Query result: " . $result['test'] . "<br>";
    
    echo "Checking gift_credits table...<br>";
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'gift_credits'");
    $stmt->execute();
    $table_exists = $stmt->fetch();
    
    echo "Gift credits table exists: " . ($table_exists ? 'YES' : 'NO') . "<br>";
    
    if ($table_exists) {
        echo "Table structure:<br>";
        $stmt = $pdo->prepare("DESCRIBE gift_credits");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($columns as $column) {
            echo "- " . $column['Field'] . " (" . $column['Type'] . ")<br>";
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM gift_credits");
        $stmt->execute();
        $count_result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<br>Total records: " . $count_result['total'] . "<br>";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>