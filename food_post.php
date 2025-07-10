<?php
$mainHost = 'localhost';
$mainDb   = 'prop_propass';
$user     = 'root';
$pass     = '';
$charset  = 'utf8mb4';

// DSN for main DB
$mainDsn = "mysql:host=$mainHost;dbname=$mainDb;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
  
    $mainPdo = new PDO($mainDsn, $user, $pass, $options);

    // Step 2: Get POST data
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

    // Step 3: Fetch event short name from the main DB
    $stmt = $mainPdo->prepare("SELECT short_name FROM events WHERE id = :event_id LIMIT 1");
    $stmt->execute([':event_id' => $event_id]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new Exception("Event not found in main database");
    }

    $shortName = $event['short_name'];
    $dynamicDbName = "prop_propass_event_" . strtolower($shortName); // e.g., prop_propass_eventshort

    // Step 4: Connect to the dynamic database
    $dynamicDsn = "mysql:host=$mainHost;dbname=$dynamicDbName;charset=$charset";
    $dynamicPdo = new PDO($dynamicDsn, $user, $pass, $options);

    // Step 5: Insert into event_scan_logs_food in the dynamic DB
    $insert = $dynamicPdo->prepare("
        INSERT INTO event_scan_logs_food (
            event_id, user_id, attendee_id, date, time, print_type, status, is_delete, created_at, updated_at
        ) VALUES (
            :event_id, :user_id, :attendee_id, :date, :time, :print_type, :status, :is_delete, NOW(), NOW()
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
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Scan log inserted successfully into ' . $dynamicDbName
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
