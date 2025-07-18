<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';         
require_once 'connect_event_database.php';

try {
    $event_id    = $_POST['event_id'] ?? null;
    $user_id     = $_POST['user_id'] ?? null;
    $attendee_id = $_POST['attendee_id'] ?? null;
    $app_user_id = $_POST['app_user_id'] ?? null;  // NEW
    $date        = $_POST['date'] ?? date('Y-m-d');
    $time        = $_POST['time'] ?? date('H:i:s');
    $status      = $_POST['status'] ?? 1;
    $is_deleted   = $_POST['is_deleted'] ?? 0;
    $print_type  = $_POST['print_type'] ?? null;
    $scan_for    = $_POST['scan_for'] ?? 'kit'; 

    // Validate required fields including app_user_id
    if (!$event_id || !$user_id || !$attendee_id || !$app_user_id) {
        throw new Exception("Missing required fields: event_id, user_id, attendee_id, or app_user_id");
    }

    // Get event short_name
    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) throw new Exception("Event not found");
    $event = $result->fetch_assoc();
    $shortName = strtolower($event['short_name']);
    $stmt->close();

    // Connect to event database
    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) throw new Exception($eventResult['message']);
    $eventConn = $eventResult['conn'];

    // Check if already scanned (including app_user_id)
    $checkStmt = $eventConn->prepare("
        SELECT id FROM event_scan_logg 
        WHERE event_id = ? AND user_id = ? AND attendee_id = ? AND app_user_id = ? AND is_deleted = 0 AND scan_for = ?
        LIMIT 1
    ");
    $checkStmt->bind_param("iiiis", $event_id, $user_id, $attendee_id, $app_user_id, $scan_for);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $alreadyScanned = $checkResult->fetch_assoc();
    $checkStmt->close();

    if ($alreadyScanned && strtolower($print_type) !== 'reissued') {
        echo json_encode([
            'success' => false,
            'require_permission' => true,
            'message' => "Already scanned $scan_for before. Allow manual override?",
        ]);
        exit;
    }

    if (!$print_type) {
        $print_type = 'Issued';
    }

    // Insert scan log with app_user_id
    $insertStmt = $eventConn->prepare("
        INSERT INTO event_scan_logg (
            event_id, user_id, attendee_id, app_user_id, date, time, print_type, status, is_deleted, scan_for, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $insertStmt->bind_param(
        "iiiisssiss",
        $event_id,
        $user_id,
        $attendee_id,
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
