<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';


try {
    // Get JSON input and decode to array
    $input = json_decode(file_get_contents("php://input"), true);

    // Validate and sanitize inputs
    $app_user_id          = isset($input['app_user_id']) ? intval($input['app_user_id']) : 0;
    $event_id             = isset($input['event_id']) ? intval($input['event_id']) : 0;
    $attendee_id          = isset($input['attendee_id']) ? intval($input['attendee_id']) : 0;
    $attendee_name        = isset($input['attendee_name']) ? trim($input['attendee_name']) : '';
    $attendee_category    = isset($input['attendee_category']) ? trim($input['attendee_category']) : '';
    $attendee_subcategory = isset($input['attendee_subcategory']) ? trim($input['attendee_subcategory']) : '';
    $action_type          = isset($input['action_type']) ? intval($input['action_type']) : 0;

    if (!$app_user_id || !$event_id || !$action_type) {
        throw new Exception("Missing required fields: app_user_id, event_id, and action_type are mandatory");
    }

    // Connect to the event-specific database
    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) {
        throw new Exception($eventResult['message']);
    }
    $eventConn = $eventResult['conn'];

    $now = date('Y-m-d H:i:s');

    // Check if a record already exists for this app_user_id, event_id, and attendee_id
    $checkQuery = $eventConn->prepare("
        SELECT id, is_preview 
        FROM " . TABLE_BADGE_PRINT_ANIMATION . " 
        WHERE app_user_id = ? AND event_id = ? AND attendee_id = ? AND is_delete = 0 
        LIMIT 1
    ");
    if (!$checkQuery) {
        throw new Exception("Prepare failed: " . $eventConn->error);
    }
    $checkQuery->bind_param("iii", $app_user_id, $event_id, $attendee_id);
    $checkQuery->execute();
    $result = $checkQuery->get_result();

    if ($result->num_rows === 0) {
        // No matching record: insert new
        $insertQuery = $eventConn->prepare("
            INSERT INTO " . TABLE_BADGE_PRINT_ANIMATION . " 
            (app_user_id, event_id, attendee_id, attendee_name, attendee_category, attendee_subcategory, is_preview, is_printed, is_delete, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
        ");
        if (!$insertQuery) {
            throw new Exception("Prepare insert failed: " . $eventConn->error);
        }

        $is_preview = $action_type;
        $is_printed = ($action_type === 4) ? 1 : 0;

        $insertQuery->bind_param(
            "iiisssiiss",
            $app_user_id,
            $event_id,
            $attendee_id,
            $attendee_name,
            $attendee_category,
            $attendee_subcategory,
            $is_preview,
            $is_printed,
            $now,
            $now
        );

        if ($insertQuery->execute()) {
            echo json_encode(["success" => true, "message" => "Inserted successfully"]);
        } else {
            throw new Exception("Insert failed: " . $insertQuery->error);
        }
        $insertQuery->close();

    } else {
        // Matching record exists: update
        $row = $result->fetch_assoc();

        $is_preview = $action_type;
        $is_printed = ($action_type === 4) ? 1 : 0;

        $updateQuery = $eventConn->prepare("
            UPDATE " . TABLE_BADGE_PRINT_ANIMATION . "
            SET is_preview = ?, is_printed = ?, updated_at = ?
            WHERE id = ?
        ");
        if (!$updateQuery) {
            throw new Exception("Prepare update failed: " . $eventConn->error);
        }

        $updateQuery->bind_param("iisi", $is_preview, $is_printed, $now, $row['id']);

        if ($updateQuery->execute()) {
            echo json_encode(["success" => true, "message" => "Updated successfully"]);
        } else {
            throw new Exception("Update failed: " . $updateQuery->error);
        }
        $updateQuery->close();
    }

    $checkQuery->close();
    $eventConn->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage(),
        "trace" => $e->getTraceAsString()
    ]);
    exit;
}
?>
