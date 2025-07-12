<?php
// Database credentials
$host = "localhost";
$user = "root";
$password = "";
$mainDbName = "prop_propass";

// Connect to main database
$mainDb = new mysqli($host, $user, $password, $mainDbName);
if ($mainDb->connect_error) {
    die("Connection failed to main DB: " . $mainDb->connect_error);
}

// Get event_id from request
$eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
if ($eventId <= 0) {
    die("Invalid event ID provided.");
}

// Get event short_name
$eventQuery = $mainDb->prepare("SELECT short_name FROM events WHERE id = ?");
$eventQuery->bind_param("i", $eventId);
$eventQuery->execute();
$eventResult = $eventQuery->get_result();
if ($eventResult->num_rows === 0) {
    die("Event not found.");
}
$eventData = $eventResult->fetch_assoc();
$shortName = $eventData['short_name'];
$eventDbName = "prop_propass_event_" . $shortName;

// Connect to event database
$eventDb = new mysqli($host, $user, $password, $eventDbName);
if ($eventDb->connect_error) {
    die("Connection failed to event DB ($eventDbName): " . $eventDb->connect_error);
}

// Table names
$scanLogTable = "event_scan_logg";
$registrationTable = "event_registrations";

// Table check helper
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}
if (!tableExists($eventDb, $scanLogTable) || !tableExists($eventDb, $registrationTable)) {
    die("Required tables not found in DB: $eventDbName");
}

// ------------------------
// Kit Collected Users
// ------------------------
$collectedSql = "
    SELECT esl.attendee_id, esl.date, esl.time, esl.print_type, er.*, 
           a1.first_name, a1.last_name, a1.primary_email_address, a1.primary_phone_number
    FROM `$scanLogTable` esl
    JOIN `$registrationTable` er ON esl.attendee_id = er.user_id
    LEFT JOIN `prop_propass`.`attendees_1` a1 ON er.user_id = a1.id
    WHERE esl.scan_for = 'kit' AND esl.event_id = ?
    ORDER BY esl.attendee_id, esl.date, esl.time
";
$collectedStmt = $eventDb->prepare($collectedSql);
if (!$collectedStmt) {
    die("Prepare failed for collected: " . $eventDb->error);
}
$collectedStmt->bind_param("i", $eventId);
$collectedStmt->execute();
$collectedResult = $collectedStmt->get_result();

// ------------------------
// Kit Not Collected Users
// ------------------------
$notCollectedSql = "
    SELECT er.*, a1.first_name, a1.last_name, a1.primary_email_address, a1.primary_phone_number
    FROM `$registrationTable` er
    LEFT JOIN `prop_propass`.`attendees_1` a1 ON er.user_id = a1.id
    WHERE er.kit = 1 AND er.event_id = ? 
    AND NOT EXISTS (
        SELECT 1 FROM `$scanLogTable` esl
        WHERE esl.attendee_id = er.user_id AND esl.scan_for = 'kit' AND esl.event_id = ?
    )
";
$notCollectedStmt = $eventDb->prepare($notCollectedSql);
if (!$notCollectedStmt) {
    die("Prepare failed for not collected: " . $eventDb->error);
}
$notCollectedStmt->bind_param("ii", $eventId, $eventId);
$notCollectedStmt->execute();
$notCollectedResult = $notCollectedStmt->get_result();

// ------------------------
// Repeated Scans
// ------------------------
$repeatedSql = "
    SELECT esl.attendee_id, COUNT(*) as scan_count,
           GROUP_CONCAT(CONCAT(esl.date, ' ', esl.time, ' (', esl.print_type, ')') ORDER BY esl.date, esl.time SEPARATOR ', ') as scan_times,
           a1.first_name, a1.last_name, a1.primary_email_address, a1.primary_phone_number
    FROM `$scanLogTable` esl
    LEFT JOIN `prop_propass`.`attendees_1` a1 ON esl.attendee_id = a1.id
    WHERE esl.scan_for = 'kit' AND esl.event_id = ?
    GROUP BY esl.attendee_id
    HAVING scan_count > 1
";
$repeatedStmt = $eventDb->prepare($repeatedSql);
if (!$repeatedStmt) {
    die("Prepare failed for repeated scans: " . $eventDb->error);
}
$repeatedStmt->bind_param("i", $eventId);
$repeatedStmt->execute();
$repeatedResult = $repeatedStmt->get_result();

// ------------------------
// Output the data (example of how you might structure the output)
// ------------------------
$data = [
    'collected_users' => [],
    'not_collected_users' => [],
    'repeated_scans' => [],
];

// Fetch collected users data
while ($row = $collectedResult->fetch_assoc()) {
    $data['collected_users'][] = $row;
}

// Fetch not collected users data
while ($row = $notCollectedResult->fetch_assoc()) {
    $data['not_collected_users'][] = $row;
}

// Fetch repeated scans data
while ($row = $repeatedResult->fetch_assoc()) {
    $data['repeated_scans'][] = $row;
}

// Output data as JSON (you could use this for API response)
header('Content-Type: application/json');
echo json_encode($data);

// Close connections
$mainDb->close();
$eventDb->close();
?>
