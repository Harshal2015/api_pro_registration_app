<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'auth_api.php';
require_once 'config.php';
require_once 'connect_event_database.php';

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $event_id        = $input['event_id'] ?? null;
    $user_id         = $input['user_id'] ?? null;
    $registration_id = $input['registration_id'] ?? null;
    $app_user_id     = $input['app_user_id'] ?? null;
    $print_type      = $input['print_type'] ?? null;
    $scan_for        = $input['scan_for'] ?? 'kit';
    $date            = $input['date'] ?? date('Y-m-d');
    $time            = $input['time'] ?? date('H:i:s');
    $status          = $input['status'] ?? 1;
    $is_deleted      = $input['is_deleted'] ?? 0;

    if (!$event_id || !$registration_id || $user_id === null || !$app_user_id) {
        throw new Exception("Missing required fields: event_id, user_id, registration_id, or app_user_id");
    }

    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) {
        throw new Exception($eventResult['message']);
    }
    $eventConn = $eventResult['conn'];

    $isIndustry = ($user_id == 0);
    $category_id = null;

    // Step 1: Validate registration/industry ID and get category_id
    if ($isIndustry) {
        $stmt = $eventConn->prepare("
            SELECT category_id FROM event_industries
            WHERE event_id = ? AND id = ? AND is_deleted = 0
            LIMIT 1
        ");
        $stmt->bind_param("ii", $event_id, $registration_id);
    } else {
        $stmt = $eventConn->prepare("
            SELECT category_id FROM event_registrations
            WHERE event_id = ? AND id = ? AND user_id = ? AND is_deleted = 0
            LIMIT 1
        ");
        $stmt->bind_param("iii", $event_id, $registration_id, $user_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception("QR not valid for this event.");
    }
    $row = $res->fetch_assoc();
    $category_id = $row['category_id'];
    $stmt->close();

    // Step 2: Check if kit access is allowed based on category
    if (strtolower($scan_for) === 'kit') {
        $catStmt = $eventConn->prepare("
            SELECT name, is_kit FROM event_categories
            WHERE id = ? LIMIT 1
        ");
        $catStmt->bind_param("i", $category_id);
        $catStmt->execute();
        $catRes = $catStmt->get_result();
        $catInfo = $catRes->fetch_assoc();
        $catStmt->close();

        if (!$catInfo || intval($catInfo['is_kit']) !== 1) {
            echo json_encode([
                'success' => false,
                'message' => 'No access for Kit based on category.',
            ]);
            exit;
        }
    }

    // Step 3: Check for duplicate scan unless it's a Reissued
    $checkStmt = $eventConn->prepare("
        SELECT id FROM event_scan_logg
        WHERE event_id = ? AND user_id = ? AND registration_id = ?
          AND app_user_id = ? AND is_deleted = 0 AND scan_for = ?
        LIMIT 1
    ");
    $checkStmt->bind_param("iiiis", $event_id, $user_id, $registration_id, $app_user_id, $scan_for);
    $checkStmt->execute();
    $alreadyScanned = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();

    if ($alreadyScanned && strtolower($print_type) !== 'reissued') {
        echo json_encode([
            'success' => false,
            'require_permission' => true,
            'message' => "Already scanned $scan_for. Allow manual override?",
        ]);
        exit;
    }

    if (!$print_type) {
        $print_type = 'Issued';
    }

    // Step 4: Log the scan
    $insertStmt = $eventConn->prepare("
        INSERT INTO event_scan_logg (
            event_id, user_id, registration_id, app_user_id,
            date, time, print_type, status, is_deleted, scan_for,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $insertStmt->bind_param(
        "iiiisssiss",
        $event_id, $user_id, $registration_id, $app_user_id,
        $date, $time, $print_type, $status, $is_deleted, $scan_for
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
