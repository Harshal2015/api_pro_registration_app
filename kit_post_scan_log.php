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
    
    $scan_for    = $_POST['scan_for'] ?? 'kit'; 

    if (!$event_id || !$user_id || !$attendee_id) {
        throw new Exception("Missing required fields: event_id, user_id, or attendee_id");
    }

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

    $checkStmt = $eventPdo->prepare("
        SELECT id FROM event_scan_logg 
        WHERE event_id = :event_id
          AND user_id = :user_id
          AND attendee_id = :attendee_id
          AND is_delete = 0
          AND scan_for = :scan_for
        LIMIT 1
    ");
    $checkStmt->execute([
        ':event_id'    => $event_id,
        ':user_id'     => $user_id,
        ':attendee_id' => $attendee_id,
        ':scan_for'    => $scan_for,
    ]);

    $alreadyScanned = $checkStmt->fetch();

    if ($alreadyScanned && $print_type !== 'Manual') {
        echo json_encode([
            'success' => false,
            'require_permission' => true,
            'message' => "Already scanned $scan_for before. Allow manual override?",
        ]);
        exit;
    }

    if (!$print_type) {
        $print_type = 'Auto';
    }

    $insert = $eventPdo->prepare("
        INSERT INTO event_scan_logg (
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
        'success' => true,
        'message' => ucfirst($scan_for) . " scan logged successfully as $print_type.",
        'print_type' => $print_type,
        'scan_for' => $scan_for,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
