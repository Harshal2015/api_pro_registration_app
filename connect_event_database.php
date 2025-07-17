<?php
// connect_event_db.php
require_once 'config.php';

function connectEventDb($event_id) {
    global $conn, $servername, $username, $password;

    if (!$event_id) {
        return ['success' => false, 'message' => 'Event ID is required'];
    }

    // Get short_name from main DB
    $eventStmt = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
    $eventStmt->bind_param("i", $event_id);
    $eventStmt->execute();
    $eventResult = $eventStmt->get_result();

    if ($eventResult->num_rows === 0) {
        return ['success' => false, 'message' => 'Event not found'];
    }

    $event = $eventResult->fetch_assoc();
    $shortName = $event['short_name'];

    // 🛠 Correct full DB name
    $eventDb = "prop_propass_event_" . $shortName;

    // Connect to event-specific DB
    $eventConn = new mysqli($servername, $username, $password, $eventDb);
    if ($eventConn->connect_error) {
        return ['success' => false, 'message' => "Failed to connect to event DB: $eventDb"];
    }

    return ['success' => true, 'conn' => $eventConn, 'db_name' => $eventDb];
}
?>
