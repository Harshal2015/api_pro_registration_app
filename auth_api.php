<?php

header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);

$app_user_id = $input['app_user_id'] ?? null;
$api_key = $input['api_key'] ?? null;

if (!$app_user_id    || !$api_key) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing app_user_id or api_key"]);
    exit;
}

$conn = new mysqli("localhost", "proregistration", "X6c!eM8BQaUD[NwZ", "prop_propass");  

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM reg_app_users WHERE id = ? AND api_key = ?");
$stmt->bind_param("ss", $app_user_id, $api_key);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

