<?php
echo "Testing basic PHP...<br>";

// Test database connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=sasadiir_miderCDB;charset=utf8mb4", 
                   "sasadiir_MiderclUs", "5TcCpBoXz7W71oi9", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Database connected!<br>";
    
    // Test gift credits
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM gift_credits");
    $stmt->execute();
    $result = $stmt->fetch();
    echo "Gift credits table has " . $result['count'] . " records<br>";
    
    // Test with a specific mobile number
    if (isset($_GET['mobile'])) {
        $mobile = $_GET['mobile'];
        echo "<br>Testing with mobile: $mobile<br>";
        
        $stmt = $pdo->prepare("SELECT * FROM gift_credits WHERE mobile = ?");
        $stmt->execute([$mobile]);
        $gifts = $stmt->fetchAll();
        
        echo "Found " . count($gifts) . " gift credits:<br>";
        foreach ($gifts as $gift) {
            echo "- Amount: " . $gift['gift_amount_toman'] . " Toman<br>";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}

echo "<br><a href='?mobile=09123456789'>Test with mobile 09123456789</a>";
?>