<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
require '../db.php';
require "../functions.php";
require "../fpdf/fpdf.php";
require "../pdfs/reports.php";

if (isset($_POST['username'], $_POST['password'])) {

    $username = $db->real_escape_string($_POST['username']);
    $password = md5($_POST['password']);

    $read = $db->query(
        "SELECT id, username, acc_type 
         FROM staff 
         WHERE username='$username' AND password='$password' 
         LIMIT 1"
    );

    if ($read && $read->num_rows > 0) {
        $user = $read->fetch_assoc();
        echo json_encode([
            "status" => true,
            "admin" => $user['acc_type'] === "admin",
            "user" => [
                "id" => $user['id'],
                "username" => $user['username'],
                "acc_type" => $user['acc_type']
            ]
        ]);
    } else {
        echo json_encode([
            "status" => false,
            "message" => "Invalid username or password"
        ]);
    }
}

?>