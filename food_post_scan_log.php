<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'auth_api.php'; 
require_once 'config.php'; 
require_once 'connect_event_database.php'; 

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $event_id        = $input['event_id'] ?? null;
    $app_user_id     = $input['app_user_id'] ?? null;
    $user_id         = $input['user_id'] ?? null;
    $registration_id = $input['registration_id'] ?? null;
    $print_type      = $input['print_type'] ?? 'Issued'; 
    $is_deleted      = $input['is_deleted'] ?? 0;

    if (!$event_id || !$app_user_id || $user_id === null || $registration_id === null) {
        throw new Exception("Missing required fields: event_id, app_user_id, user_id, or registration_id");
    }

    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception("Event not found");
    }
    $stmt->close();

    $isMasterQR = ($user_id == 0 && $registration_id == 0);

    $currentTime = date('H:i:s');
    list($h, $m, $s) = explode(':', $currentTime);
    $currentMinutes = intval($h) * 60 + intval($m);

    $scan_for = null;

    if ($currentMinutes >= 600 && $currentMinutes <= 1080) {
        $scan_for = 'Lunch';
    }
    elseif (($currentMinutes >= 1080 && $currentMinutes <= 1439) || ($currentMinutes >= 0 && $currentMinutes <= 180)) {
        $scan_for = 'Dinner';
    } else {
        throw new Exception("Scan time outside Lunch and Dinner windows.");
    }

    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) {
        throw new Exception($eventResult['message']);
    }
    $eventConn = $eventResult['conn'];

    if ($isMasterQR) {
        $insertStmt = $eventConn->prepare("
            INSERT INTO event_scan_logs_food (
                event_id, app_user_id, user_id, registration_id,
                date, time, print_type, status, is_deleted, scan_for,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, CURDATE(), CURTIME(), ?, 1, 0, ?, NOW(), NOW())
        ");

        $insertStmt->bind_param(
            "iiiiss",
            $event_id, $app_user_id, $user_id, $registration_id,
            $print_type, $scan_for
        );
        $insertStmt->execute();
        $insertStmt->close();

        echo json_encode([
            'success' => true,
            'message' => "Master QR logged successfully as $print_type for $scan_for.",
            'print_type' => $print_type,
            'scan_for' => $scan_for
        ]);
        exit;
    }

    $isIndustry = ($user_id == 0);
    $hasLunch = 0;
    $hasDinner = 0;

    if ($isIndustry) {
       $stmt = $eventConn->prepare("
    SELECT ei.category_id, ec.is_lunch, ec.is_dinner, ec.name AS category_name
    FROM event_industries ei
    JOIN event_categories ec ON ei.category_id = ec.id
    WHERE ei.id = ? AND ei.event_id = ? AND ei.is_deleted = 0
    LIMIT 1
");

        $stmt->bind_param("ii", $registration_id, $event_id);
    } else {
        $stmt = $eventConn->prepare("
    SELECT r.category_id, ec.is_lunch, ec.is_dinner, ec.name AS category_name
    FROM event_registrations r
    JOIN event_categories ec ON r.category_id = ec.id
    WHERE r.id = ? AND r.event_id = ? AND r.user_id = ? AND r.is_deleted = 0
    LIMIT 1
");

        $stmt->bind_param("iii", $registration_id, $event_id, $user_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception("QR not valid or no access for this event.");
    }

    $row = $res->fetch_assoc();
$hasLunch = intval($row['is_lunch']);
$hasDinner = intval($row['is_dinner']);
$categoryName = $row['category_name'] ?? 'Unknown';

    $stmt->close();

    if ($scan_for === 'Lunch' && !$hasLunch) {
    echo json_encode([
        'success' => false,
        'message' => "No access for Lunch for $categoryName."
    ]);
    exit;
}
if ($scan_for === 'Dinner' && !$hasDinner) {
    echo json_encode([
        'success' => false,
        'message' => "No access for Dinner for $categoryName."
    ]);
    exit;
}


    $dupStmt = $eventConn->prepare("
        SELECT id FROM event_scan_logs_food
        WHERE event_id = ? AND app_user_id = ? AND user_id = ? AND registration_id = ?
        AND date = CURDATE() AND scan_for = ? AND is_deleted = 0
        LIMIT 1
    ");
    $dupStmt->bind_param("iiiis", $event_id, $app_user_id, $user_id, $registration_id, $scan_for);
    $dupStmt->execute();
    $already = $dupStmt->get_result()->num_rows > 0;
    $dupStmt->close();

    if ($already && strtolower($print_type) !== 'reissued') {
        echo json_encode([
            'success' => false,
            'require_permission' => true,
            'message' => "$scan_for already taken. Do you want to Reissue?"
        ]);
        exit;
    }

    $insertStmt = $eventConn->prepare("
        INSERT INTO event_scan_logs_food (
            event_id, app_user_id, user_id, registration_id,
            date, time, print_type, status, is_deleted, scan_for,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, CURDATE(), CURTIME(), ?, 1, ?, ?, NOW(), NOW())
    ");
    $insertStmt->bind_param(
        "iiiisis",
        $event_id, $app_user_id, $user_id, $registration_id,
        $print_type, $is_deleted, $scan_for
    );
    $insertStmt->execute();
    $insertStmt->close();

    echo json_encode([
        'success'     => true,
        'message'     => "$scan_for  $print_type Succesfully ",
        'print_type'  => $print_type,
        'scan_for'    => $scan_for
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => "Server error: " . $e->getMessage()
    ]);
}
