<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata'); // Change as needed

// DB credentials
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "prop_propass_event_indiavalves2025"; // ✅ Replace with actual DB name

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "DB connection failed"]);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents("php://input"), true);

// Validate input
$app_user_id = isset($input['app_user_id']) ? intval($input['app_user_id']) : 0;
$event_id = isset($input['event_id']) ? intval($input['event_id']) : 0;
$attendee_id = isset($input['attendee_id']) ? intval($input['attendee_id']) : 0;
$attendee_name = isset($input['attendee_name']) ? trim($input['attendee_name']) : '';
$attendee_category = isset($input['attendee_category']) ? trim($input['attendee_category']) : '';
$attendee_subcategory = isset($input['attendee_subcategory']) ? trim($input['attendee_subcategory']) : '';
$action_type = isset($input['action_type']) ? intval($input['action_type']) : 0; // 1 to 4

if (!$app_user_id || !$event_id || !$action_type) {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

$now = date('Y-m-d H:i:s');

// Check if row already exists
$checkQuery = $conn->prepare("SELECT id, is_preview FROM tbl_badge_print_animation WHERE app_user_id = ? AND event_id = ? AND is_delete = 0 LIMIT 1");
$checkQuery->bind_param("ii", $app_user_id, $event_id);
$checkQuery->execute();
$result = $checkQuery->get_result();

if ($result->num_rows === 0 && $action_type === 1) {
    // First time insert
    $insertQuery = $conn->prepare("
        INSERT INTO tbl_badge_print_animation 
        (app_user_id, event_id, attendee_id, attendee_name, attendee_category, attendee_subcategory, is_preview, is_printed, is_delete, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, 0, 0, ?, ?)
    ");
    $insertQuery->bind_param("iiisssss", $app_user_id, $event_id, $attendee_id, $attendee_name, $attendee_category, $attendee_subcategory, $now, $now);
    
    if ($insertQuery->execute()) {
        echo json_encode(["success" => true, "message" => "Inserted successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Insert failed"]);
    }
    $insertQuery->close();
} elseif ($result->num_rows > 0) {
    // Row exists — update based on action_type (2, 3, or 4)
    $row = $result->fetch_assoc();
    $is_preview = $action_type;

    // Update `is_printed` to 1 only when is_preview becomes 4
    $is_printed = ($action_type === 4) ? 1 : 0;

    $updateQuery = $conn->prepare("
        UPDATE tbl_badge_print_animation 
        SET is_preview = ?, is_printed = ?, updated_at = ?
        WHERE id = ?
    ");
    $updateQuery->bind_param("iisi", $is_preview, $is_printed, $now, $row['id']);

    if ($updateQuery->execute()) {
        echo json_encode(["success" => true, "message" => "Updated successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Update failed"]);
    }
    $updateQuery->close();
} else {
    echo json_encode(["success" => false, "message" => "No matching record for update or wrong action"]);
}

$conn->close();
?>
