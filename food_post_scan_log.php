<?php
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';          
require_once 'connect_event_database.php';

header('Content-Type: application/json');

try {
    $event_id    = $_POST['event_id'] ?? null;
    $user_id     = $_POST['user_id'] ?? null;
    $attendee_id = $_POST['attendee_id'] ?? null;
    $print_type  = $_POST['print_type'] ?? null;
    $status      = $_POST['status'] ?? 1;
    $is_deleted   = $_POST['is_deleted'] ?? 0;

    if (!$event_id || (!$user_id && $print_type !== 'Master QR') || (!$attendee_id && $print_type !== 'Master QR')) {
        throw new Exception("Missing required fields: event_id" .
            (($print_type !== 'Master QR' && !$user_id) ? ", user_id" : "") .
            (($print_type !== 'Master QR' && !$attendee_id) ? ", attendee_id" : "")
        );
    }

    $date = date('Y-m-d');
    $time = date('H:i:s');
    $scanTimestamp = strtotime($time);
    $lunchStart    = strtotime('11:00:00');
    $lunchEnd      = strtotime('15:59:59');
    $dinnerStart1  = strtotime('16:00:00');
    $dinnerEnd1    = strtotime('23:59:59');
    $dinnerStart2  = strtotime('00:00:00');
    $dinnerEnd2    = strtotime('02:59:59');

    if ($scanTimestamp >= $lunchStart && $scanTimestamp <= $lunchEnd) {
        $scan_for = 'Lunch';
    } elseif (
        ($scanTimestamp >= $dinnerStart1 && $scanTimestamp <= $dinnerEnd1) ||
        ($scanTimestamp >= $dinnerStart2 && $scanTimestamp <= $dinnerEnd2)
    ) {
        $scan_for = 'Dinner';
    } else {
        $scan_for = 'Lunch';
    }

    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Event not found");
    }
    $event = $result->fetch_assoc();
    $stmt->close();

    $shortName = strtolower($event['short_name']);

    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) throw new Exception($eventResult['message']);
    $eventConn = $eventResult['conn'];

    if ($print_type !== 'Master QR') {
        $checkStmt = $eventConn->prepare("
            SELECT id FROM event_scan_logs_food 
            WHERE event_id = ?
              AND user_id = ?
              AND attendee_id = ?
              AND date = ?
              AND scan_for = ?
              AND is_deleted = 0
            LIMIT 1
        ");
        $checkStmt->bind_param("iiiss", $event_id, $user_id, $attendee_id, $date, $scan_for);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0 && $print_type !== 'Reissued') {
            echo json_encode([
                'success' => false,
                'require_permission' => true,
                'message' => "Already scanned for $scan_for today. Allow manual override?",
                'scan_for' => $scan_for,
            ]);
            exit;
        }
        $checkStmt->close();
    }

    if (!$print_type) {
        $print_type = 'Issued';
    }

    $insertStmt = $eventConn->prepare("
        INSERT INTO event_scan_logs_food (
            event_id, user_id, attendee_id, date, time, print_type, status, is_deleted, scan_for, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $insertStmt->bind_param(
        "iiisssiis",
        $event_id,
        $user_id,
        $attendee_id,
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
        'message'    => "Scan logged successfully as $print_type.",
        'print_type' => $print_type,
        'scan_for'   => $scan_for,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
?>
