<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'auth_api.php';
require_once 'config.php';         
require_once 'connect_event_database.php';

try {
    // Read raw JSON input from the request body
    $input = json_decode(file_get_contents("php://input"), true);

    // Step 1: Collect Input from decoded JSON
    $event_id       = $input['event_id'] ?? null;
    $user_id        = $input['user_id'] ?? null;
    $registration_id= $input['registration_id'] ?? null;
    $app_user_id    = $input['app_user_id'] ?? null;
    $print_type     = $input['print_type'] ?? null;
    $status         = $input['status'] ?? 1;
    $is_deleted     = $input['is_deleted'] ?? 0;
    $date           = $input['date'] ?? date('Y-m-d');
    $time           = $input['time'] ?? date('H:i:s');
    $scan_for       = $input['scan_for'] ?? 'kit';

    // Step 2: Validate required inputs
    if (!$event_id || !$user_id || !$registration_id || !$app_user_id) {
        throw new Exception("Missing required fields: event_id, user_id, registration_id, or app_user_id");
    }

    // Step 3: Get event short name
    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ? LIMIT 1");
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) throw new Exception("Event not found");
    $event = $result->fetch_assoc();
    $shortName = strtolower($event['short_name']);
    $stmt->close();

    // Step 4: Connect to event-specific DB
    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) throw new Exception($eventResult['message']);
    $eventConn = $eventResult['conn'];

    // Step 5: Fetch category_id of the attendee
    $regStmt = $eventConn->prepare("SELECT category_id FROM event_registrations WHERE id = ? LIMIT 1");
    if (!$regStmt) throw new Exception("Prepare failed (Registration fetch): " . $eventConn->error);
    $regStmt->bind_param("i", $registration_id);
    $regStmt->execute();
    $regResult = $regStmt->get_result();
    if ($regResult->num_rows === 0) throw new Exception("Attendee not found in event_registrations.");
    $regRow = $regResult->fetch_assoc();
    $regStmt->close();

    $category_id = $regRow['category_id'];

    // Step 6: Check if category has kit access
    $catStmt = $eventConn->prepare("SELECT name, is_kit FROM event_categories WHERE id = ? LIMIT 1");
    if (!$catStmt) throw new Exception("Prepare failed (Category fetch): " . $eventConn->error);
    $catStmt->bind_param("i", $category_id);
    $catStmt->execute();
    $catResult = $catStmt->get_result();
    if ($catResult->num_rows === 0) throw new Exception("Category not found in event_categories.");
    $categoryInfo = $catResult->fetch_assoc();
    $catStmt->close();

    if (intval($categoryInfo['is_kit']) !== 1) {
        echo json_encode([
            'success' => false,
            'message' => 'No access for Kit based on category.',
        ]);
        exit;
    }

    // Step 7: Check for duplicate scan (unless Reissued)
    $checkStmt = $eventConn->prepare("
        SELECT id FROM event_scan_logg 
        WHERE event_id = ? AND user_id = ? AND registration_id = ? 
          AND app_user_id = ? AND is_deleted = 0 AND scan_for = ?
        LIMIT 1
    ");
    if (!$checkStmt) throw new Exception("Prepare failed (Duplicate check): " . $eventConn->error);
    $checkStmt->bind_param("iiiis", $event_id, $user_id, $registration_id, $app_user_id, $scan_for);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $alreadyScanned = $checkResult->num_rows > 0;
    $checkStmt->close();

    if ($alreadyScanned && strtolower($print_type) !== 'reissued') {
        echo json_encode([
            'success'             => false,
            'require_permission'  => true,
            'message'             => "Already scanned $scan_for. Allow manual override?",
        ]);
        exit;
    }

    if (!$print_type) $print_type = 'Issued';

    // Step 8: Insert scan log
    $insertStmt = $eventConn->prepare("
        INSERT INTO event_scan_logg (
            event_id, user_id, registration_id, app_user_id,
            date, time, print_type, status, is_deleted, scan_for,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    if (!$insertStmt) throw new Exception("Prepare failed (Insert): " . $eventConn->error);

    $insertStmt->bind_param(
        "iiiisssiss",
        $event_id,
        $user_id,
        $registration_id,
        $app_user_id,
        $date,
        $time,
        $print_type,
        $status,
        $is_deleted,
        $scan_for
    );
    $insertStmt->execute();
    $insertStmt->close();

    echo json_encode([
        'success'    => true,
        'message'    => ucfirst($scan_for) . " scan logged successfully as $print_type.",
        'print_type' => $print_type,
        'scan_for'   => $scan_for,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
