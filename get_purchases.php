<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
session_start();
if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
$uid = (int) $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare('SELECT amount, created_at FROM purchases WHERE subscriber_id = ? ORDER BY created_at DESC LIMIT 200');
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','purchases'=>$rows]);
} catch (Throwable $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}




















