<?php

$mainHost = 'localhost';
$mainDb   = 'prop_propass';
$user     = 'root';
$pass     = '';
$charset  = 'utf8mb4';

$mainDsn = "mysql:host=$mainHost;dbname=$mainDb;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $mainPdo = new PDO($mainDsn, $user, $pass, $options);

    $event_id    = $_POST['event_id'] ?? null;
    $user_id     = $_POST['user_id'] ?? null;
    $attendee_id = $_POST['attendee_id'] ?? null;
    $date        = $_POST['date'] ?? date('Y-m-d');
    $time        = $_POST['time'] ?? date('H:i:s');
    $status      = $_POST['status'] ?? 1;
    $is_delete   = $_POST['is_delete'] ?? 0;
    $print_type  = $_POST['print_type'] ?? null;

    // Master QR doesn't require user_id or attendee_id
    if (!$event_id || (!$user_id && $print_type !== 'Master') || (!$attendee_id && $print_type !== 'Master')) {
        throw new Exception("Missing required fields: event_id" .
            (($print_type !== 'Master' && !$user_id) ? ", user_id" : "") .
            (($print_type !== 'Master' && !$attendee_id) ? ", attendee_id" : "")
        );
    }

    // Determine scan_for based on time
    $scanTimestamp = strtotime($time);

    $lunchStart    = strtotime('11:00:00');
    $lunchEnd      = strtotime('15:59:59');
    $dinnerStart1  = strtotime('16:00:00');
    $dinnerEnd1    = strtotime('23:59:59');
    $dinnerStart2  = strtotime('00:00:00');
    $dinnerEnd2    = strtotime('02:59:59');

    if ($scanTimestamp >= $lunchStart && $scanTimestamp <= $lunchEnd) {
        $scan_for = 'lunch';
    } elseif (
        ($scanTimestamp >= $dinnerStart1 && $scanTimestamp <= $dinnerEnd1) ||
        ($scanTimestamp >= $dinnerStart2 && $scanTimestamp <= $dinnerEnd2)
    ) {
        $scan_for = 'dinner';
    } else {
        $scan_for = 'lunch'; // Default
    }

    // Fetch event DB
    $stmt = $mainPdo->prepare("SELECT short_name FROM events WHERE id = :event_id LIMIT 1");
    $stmt->execute([':event_id' => $event_id]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new Exception("Event not found");
    }

    $shortName = strtolower($event['short_name']);
    $eventDb   = "prop_propass_event_$shortName";
    $eventDsn  = "mysql:host=$mainHost;dbname=$eventDb;charset=$charset";
    $eventPdo  = new PDO($eventDsn, $user, $pass, $options);

    if ($print_type !== 'Master') {
        // Check for existing scan (not for Master)
        $checkStmt = $eventPdo->prepare("
            SELECT id FROM event_scan_logs_food 
            WHERE event_id = :event_id
              AND user_id = :user_id
              AND attendee_id = :attendee_id
              AND date = :date
              AND scan_for = :scan_for
              AND is_delete = 0
            LIMIT 1
        ");
        $checkStmt->execute([
            ':event_id'    => $event_id,
            ':user_id'     => $user_id,
            ':attendee_id' => $attendee_id,
            ':date'        => $date,
            ':scan_for'    => $scan_for,
        ]);

        $alreadyScanned = $checkStmt->fetch();

        if ($alreadyScanned && $print_type !== 'Manual') {
            echo json_encode([
                'success' => false,
                'require_permission' => true,
                'message' => "Already scanned for $scan_for today. Allow manual override?",
                'scan_for' => $scan_for,
            ]);
            exit;
        }
    }

    // Default print_type to Auto
    if (!$print_type) {
        $print_type = 'Auto';
    }

    // Insert scan
    $insert = $eventPdo->prepare("
        INSERT INTO event_scan_logs_food (
            event_id, user_id, attendee_id, date, time, print_type, status, is_delete, scan_for, created_at, updated_at
        ) VALUES (
            :event_id, :user_id, :attendee_id, :date, :time, :print_type, :status, :is_delete, :scan_for, NOW(), NOW()
        )
    ");

    $insert->execute([
        ':event_id'    => $event_id,
        ':user_id'     => $user_id,
        ':attendee_id' => $attendee_id,
        ':date'        => $date,
        ':time'        => $time,
        ':print_type'  => $print_type,
        ':status'      => $status,
        ':is_delete'   => $is_delete,
        ':scan_for'    => $scan_for,
    ]);

    echo json_encode([
        'success'     => true,
        'message'     => "Scan logged successfully as $print_type.",
        'print_type'  => $print_type,
        'scan_for'    => $scan_for,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
?>