<?php
// Minimal mobile inquiry test
header('Content-Type: text/html; charset=utf-8');

try {
    // Database connection (hardcoded to avoid config issues)
    $host = 'localhost';
    $db   = 'sasadiir_miderCDB'; 
    $user = 'sasadiir_MiderclUs';      
    $pass = '5TcCpBoXz7W71oi9';          
    
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    
    echo "Database connected successfully!<br><br>";
    
    // Check if gift_credits table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'gift_credits'");
    $stmt->execute();
    $table_exists = $stmt->fetch();
    
    if (!$table_exists) {
        echo "Creating gift_credits table...<br>";
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
        echo "Table created!<br>";
        
        // Add test data
        $stmt = $pdo->prepare("
            INSERT INTO gift_credits (mobile, credit_amount, gift_amount_toman, notes, active) 
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute(['09123456789', 50000, 250000, 'Test gift credit']);
        echo "Test data added for mobile: 09123456789<br>";
    }
    
    // Test query for gift credits
    $test_mobile = '09123456789';
    echo "<br>Testing gift credits query for mobile: $test_mobile<br>";
    
    $stmt = $pdo->prepare("
        SELECT id, credit_amount, gift_amount_toman, created_at, notes as description
        FROM gift_credits 
        WHERE mobile = ? AND active = 1
        ORDER BY created_at DESC
    ");
    $stmt->execute([$test_mobile]);
    $gift_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($gift_credits) . " gift credits:<br>";
    foreach ($gift_credits as $gift) {
        echo "- ID: " . $gift['id'] . ", Amount: " . number_format($gift['gift_amount_toman']) . " Toman, Credits: " . number_format($gift['credit_amount']) . "<br>";
    }
    
    // Test the JavaScript functionality that would be in mobile_inquiry.php
    echo "<br><br>Testing JavaScript display logic:<br>";
    echo "<script>";
    echo "console.log('Gift credits data:', " . json_encode($gift_credits) . ");";
    echo "</script>";
    
    echo "<div id='testDisplay'></div>";
    echo "<script>
        const giftCredits = " . json_encode($gift_credits) . ";
        let html = '<h3>Gift Credits:</h3>';
        if (giftCredits && giftCredits.length > 0) {
            html += '<table border=1>';
            html += '<tr><th>Amount (Toman)</th><th>Date</th><th>Description</th></tr>';
            giftCredits.forEach(gift => {
                const date = new Date(gift.created_at);
                const persianDate = date.toLocaleDateString('fa-IR');
                html += '<tr>';
                html += '<td>' + gift.gift_amount_toman.toLocaleString() + '</td>';
                html += '<td>' + persianDate + '</td>';
                html += '<td>' + (gift.description || '') + '</td>';
                html += '</tr>';
            });
            html += '</table>';
        } else {
            html += '<p>No gift credits found</p>';
        }
        document.getElementById('testDisplay').innerHTML = html;
    </script>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
    echo "Stack trace:<br>" . nl2br($e->getTraceAsString());
}
?>