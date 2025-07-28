<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'auth_api.php';
require_once 'config.php';
require_once 'connect_event_database.php';

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $event_id        = $input['event_id'] ?? null;
    $user_id         = $input['user_id'] ?? null; // can be uniqueValue (industry) or user_id
    $registration_id = $input['registration_id'] ?? null; // can be industry.id or registration.id
    $app_user_id     = $input['app_user_id'] ?? null;
    $api_key         = $input['api_key'] ?? null;

    $date            = $input['date'] ?? date('Y-m-d');
    $time            = $input['time'] ?? date('H:i:s');
    $status          = $input['status'] ?? 1;
    $is_deleted      = $input['is_deleted'] ?? 0;
    $print_type      = $input['print_type'] ?? 'Issued';
    $scan_for        = $input['scan_for'] ?? 'badge';

    if (!$event_id || !$registration_id) {
        throw new Exception("Missing required fields: event_id or registration_id");
    }

    // Connect to event-specific database
    $connectionResult = connectEventDb($event_id);
    if (!$connectionResult['success']) {
        throw new Exception($connectionResult['message']);
    }

    /** @var mysqli $eventConn */
    $eventConn = $connectionResult['conn'];

    // 🔍 Detect whether it's an industry by checking event_industries
    $industryCheckStmt = $eventConn->prepare("
        SELECT id, unique_value FROM event_industries 
        WHERE event_id = ? AND id = ? AND is_deleted = 0 
        LIMIT 1
    ");
    $industryCheckStmt->bind_param("ii", $event_id, $registration_id);
    $industryCheckStmt->execute();
    $industryResult = $industryCheckStmt->get_result();
    $industry = $industryResult->fetch_assoc();
    $industryCheckStmt->close();

    $isIndustry = $industry ? true : false;

    if ($isIndustry) {
        // Industry ID already verified above
        // Optional: You could override $user_id here using $industry['unique_value']
        // Example: $user_id = $industry['unique_value'];
    } else {
        // Not an industry, must be a regular user registration
        $registrationCheckStmt = $eventConn->prepare("
            SELECT user_id FROM event_registrations
            WHERE event_id = ? AND id = ? AND user_id = ? AND is_deleted = 0
            LIMIT 1
        ");
        $registrationCheckStmt->bind_param("iii", $event_id, $registration_id, $user_id);
        $registrationCheckStmt->execute();
        $registrationResult = $registrationCheckStmt->get_result();
        $registration = $registrationResult->fetch_assoc();
        $registrationCheckStmt->close();

        if (!$registration) {
            throw new Exception("QR not valid for this event. Invalid registration ID: $registration_id for event $event_id");
        }
    }

    // 🔁 Check for duplicate scan unless Reissued
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

    // ✅ Insert scan log
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
