<?php
// Start auth_api.php

header("Content-Type: application/json");

// Get raw POST body and decode JSON
$input = json_decode(file_get_contents("php://input"), true);

// Validate input
$app_user_id = $input['app_user_id'] ?? null;
$api_key = $input['api_key'] ?? null;

if (!$app_user_id    || !$api_key) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing app_user_id or api_key"]);
    exit;
}

// Database connection
$conn = new mysqli("localhost", "root", "", "prop_propass");  // Teri Config File yha include kr lena

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Check user and api_key
$stmt = $conn->prepare("SELECT * FROM reg_app_users WHERE id = ? AND api_key = ?");
$stmt->bind_param("ss", $app_user_id, $api_key);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

