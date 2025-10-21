<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';

try {
    // Require logged-in session
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
        exit;
    }

    // Use session user id to determine mobile
    $user_id = $_SESSION['user_id'];

    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : null;
    $city = isset($_POST['city']) ? trim($_POST['city']) : null;
    $birthday = isset($_POST['birthday']) ? trim($_POST['birthday']) : null; // Gregorian (ignored if birthday_jalali provided)
    $birthday_jalali = isset($_POST['birthday_jalali']) ? trim($_POST['birthday_jalali']) : null; // prefer this

    // If a Jalali string was provided, store that instead as requested
    if (!empty($birthday_jalali)) {
        $save_birthday = $birthday_jalali;
    } else {
        $save_birthday = $birthday;
    }

    // Fetch user and ensure verified
    $stmt = $pdo->prepare("SELECT * FROM subscribers WHERE id=? AND verified=1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found or not verified.']);
        exit;
    }

    $mobile = $user['mobile'];

    // Use provided full_name or fallback to previous one
    if (!$full_name) {
        $full_name = $user['full_name'];
    }

    // Only add credit if profile fields were empty before
    $add_credit = 0;
    if (empty($user['email']) && $email) $add_credit = 0;

    $stmt = $pdo->prepare("UPDATE subscribers SET full_name=?, email=?, city=?, birthday=?, credit=credit+? WHERE id=?");
    $stmt->execute([$full_name, $email, $city, $save_birthday, $add_credit, $user_id]);

    // Get new credit
    $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id=?");
    $stmt->execute([$user_id]);
    $new_credit = $stmt->fetchColumn();

    echo json_encode(['status' => 'success', 'credit' => (int)$new_credit]);
    exit;

} catch (Exception $e) {
    // Always return JSON on errors
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
    // Optionally log $e->getMessage() to server logs
    exit;
}
