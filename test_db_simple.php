<?php
echo "Testing database connection...\n";

// Database connection
$host = 'localhost';
$db   = 'sasadiir_miderCDB'; 
$user = 'sasadiir_MiderclUs';      
$pass = '5TcCpBoXz7W71oi9';          
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    echo "Connecting to database...\n";
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "Connected successfully!\n";
    
    // Test basic query
    echo "Testing basic query...\n";
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    echo "Query result: " . $result['test'] . "\n";
    
    // Check gift_credits table
    echo "Checking gift_credits table...\n";
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'gift_credits'");
    $stmt->execute();
    $table_exists = $stmt->fetch();
    
    echo "Gift credits table exists: " . ($table_exists ? 'YES' : 'NO') . "\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>