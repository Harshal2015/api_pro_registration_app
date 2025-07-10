<?php
file_put_contents("/tmp/scan_debug.log", print_r($_POST, true), FILE_APPEND);

$mainHost = 'localhost';
$mainDb   = 'prop_propass';
$user     = 'root';
$pass     = '';
$charset  = 'utf8mb4';

$mainDsn = "mysql:host=$mainHost;dbname=$mainDb;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {

    $mainPdo = new PDO($mainDsn, $user, $pass, $options);

    $event_id     = $_POST['event_id'] ?? null;
    $user_id      = $_POST['user_id'] ?? null;
    $attendee_id  = $_POST['attendee_id'] ?? null;
    $date         = $_POST['date'] ?? date('Y-m-d');
    $time         = $_POST['time'] ?? date('H:i:s');
    $print_type   = $_POST['print_type'] ?? 'Manual';
    $status       = $_POST['status'] ?? 1;
    $is_delete    = $_POST['is_delete'] ?? 0;

    if (!$event_id) {
        throw new Exception("Missing required event_id");
    }

    $scanTimestamp = strtotime($time);

    $lunchStart = strtotime('11:00:00');
    $lunchEnd = strtotime('17:00:00');

    $dinnerStart1 = strtotime('18:00:00');
    $dinnerEnd1 = strtotime('23:59:59');

    $dinnerStart2 = strtotime('00:00:00');
    $dinnerEnd2 = strtotime('02:00:00');

    if ($scanTimestamp >= $lunchStart && $scanTimestamp <= $lunchEnd) {
        $scan_for = 'lunch';
    } elseif (
        ($scanTimestamp >= $dinnerStart1 && $scanTimestamp <= $dinnerEnd1) ||
        ($scanTimestamp >= $dinnerStart2 && $scanTimestamp <= $dinnerEnd2)
    ) {
        $scan_for = 'dinner';
    } else {
        $scan_for = 'lunch';
    }

    $stmt = $mainPdo->prepare("SELECT short_name FROM events WHERE id = :event_id LIMIT 1");
    $stmt->execute([':event_id' => $event_id]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new Exception("Event not found in main database");
    }

    $shortName = $event['short_name'];
    $dynamicDbName = "prop_propass_event_" . strtolower($shortName);

    $dynamicDsn = "mysql:host=$mainHost;dbname=$dynamicDbName;charset=$charset";
    $dynamicPdo = new PDO($dynamicDsn, $user, $pass, $options);

    $insert = $dynamicPdo->prepare("
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
        'success' => true,
        'message' => 'Scan log inserted successfully into ' . $dynamicDbName,
        'scan_for' => $scan_for,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
