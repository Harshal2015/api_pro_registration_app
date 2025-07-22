<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';               
require_once 'connect_event_database.php';      

try {
    $event_id    = $_POST['event_id'] ?? null;
    $user_id     = $_POST['user_id'] ?? null;
    $registration_id = $_POST['registration_id'] ?? null;
    $app_user_id = $_POST['app_user_id'] ?? null;

    $date        = $_POST['date'] ?? date('Y-m-d');
    $time        = $_POST['time'] ?? date('H:i:s');
    $status      = $_POST['status'] ?? 1;
    $is_deleted   = $_POST['is_deleted'] ?? 0;
    $print_type  = $_POST['print_type'] ?? 'Issued';
    $scan_for    = $_POST['scan_for'] ?? 'badge';

    if (!$event_id || !$user_id || !$registration_id) {
        throw new Exception("Missing required fields: event_id, user_id, or registration_id");
    }

    $connectionResult = connectEventDb($event_id);
    if (!$connectionResult['success']) {
        throw new Exception($connectionResult['message']);
    }

    /** @var mysqli $eventConn */
    $eventConn = $connectionResult['conn'];

    $checkStmt = $eventConn->prepare("
        SELECT id FROM event_scan_logg
        WHERE event_id = ? AND user_id = ? AND registration_id = ? AND is_deleted = 0 AND scan_for = ?
        LIMIT 1
    ");
    $checkStmt->bind_param("iiis", $event_id, $user_id, $registration_id, $scan_for);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $alreadyScanned = $checkResult->fetch_assoc();
    $checkStmt->close();

    if ($alreadyScanned && $print_type !== 'Reissued') {
        echo json_encode([
            'success' => false,
            'require_permission' => true,
            'message' => "Already scanned $scan_for before. Allow manual override?",
        ]);
        exit;
    }

    $insertStmt = $eventConn->prepare("
        INSERT INTO event_scan_logg (
            event_id, user_id, registration_id, app_user_id, date, time,
            print_type, status, is_deleted, scan_for, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
    ");
    $insertStmt->bind_param(
        "iiiisssiis",
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
        'success' => true,
        'message' => ucfirst($scan_for) . " scan logged successfully as $print_type.",
        'print_type' => $print_type,
        'scan_for' => $scan_for,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
