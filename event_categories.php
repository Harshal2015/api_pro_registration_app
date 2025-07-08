<?php
header('Content-Type: application/json');

// Main database connection
$mainHost = 'localhost';
$mainDb = 'prop_propass';
$user = 'root';
$pass = '';

$mainConn = new mysqli($mainHost, $user, $pass, $mainDb);
if ($mainConn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Main database connection failed']);
    exit;
}

$eventId = $_GET['event_id'] ?? '';

if (!$eventId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing event_id']);
    exit;
}

// Step 1: Get short_name from events table
$shortName = '';
$stmt = $mainConn->prepare("SELECT short_name FROM events WHERE id = ?");
$stmt->bind_param("s", $eventId);
$stmt->execute();
$stmt->bind_result($shortName);
$stmt->fetch();
$stmt->close();
$mainConn->close();

if (!$shortName) {
    http_response_code(404);
    echo json_encode(['error' => 'Invalid event_id or short_name not found']);
    exit;
}

// Step 2: Build the event-specific database name
$eventDbName = "prop_propass_event_" . $shortName;

// Step 3: Connect to the event-specific database
$eventConn = new mysqli($mainHost, $user, $pass, $eventDbName);
if ($eventConn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => "Connection to event database '$eventDbName' failed"]);
    exit;
}

// Step 4: Query event_categories table
$sql = "SELECT id, name FROM event_categories WHERE parent_id IS NOT NULL";
$result = $eventConn->query($sql);

$categories = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = [
            'id' => (int)$row['id'],
            'name' => $row['name']
        ];
    }
} else {
    // No data found
    $categories = [];
}

echo json_encode($categories);
$eventConn->close();
?>
