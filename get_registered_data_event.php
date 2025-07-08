<?php
header('Content-Type: application/json');

$mainDb = "prop_propass";
$servername = "localhost";
$username = "root";
$password = "";

// Step 1: Connect to main DB
$conn = new mysqli($servername, $username, $password, $mainDb);
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Main DB connection failed']));
}

// Step 2: Get input data
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

$event_id = isset($input['event_id']) ? intval($input['event_id']) : 0;
$page = isset($input['page']) ? max(1, intval($input['page'])) : 1;
$page_size = isset($input['page_size']) ? intval($input['page_size']) : 20;

if (!$event_id) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit();
}

$offset = ($page - 1) * $page_size;

// Step 3: Get event short_name
$eventQuery = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
$eventQuery->bind_param("i", $event_id);
$eventQuery->execute();
$eventResult = $eventQuery->get_result();

if ($eventResult->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit();
}

$event = $eventResult->fetch_assoc();
$shortName = $event['short_name'];
$eventDb = $mainDb . "_event_" . $shortName;

// Step 4: Connect to event-specific DB
$eventConn = new mysqli($servername, $username, $password, $eventDb);
if ($eventConn->connect_error) {
    echo json_encode(['success' => false, 'message' => "Failed to connect to event DB: $eventDb"]);
    exit();
}

// Step 5a: Get total registration count
$countQuery = "
    SELECT COUNT(*) as total_count
    FROM {$eventDb}.event_registrations er
    WHERE er.is_deleted = 0 AND er.event_id = ?
";

$countStmt = $eventConn->prepare($countQuery);
$countStmt->bind_param("i", $event_id);
$countStmt->execute();
$countResult = $countStmt->get_result();

$totalCount = 0;
if ($countRow = $countResult->fetch_assoc()) {
    $totalCount = intval($countRow['total_count']);
}
$countStmt->close();

if ($totalCount === 0) {
    echo json_encode([
        'success' => true,
        'total_count' => 0,
        'data' => []
    ]);
    $conn->close();
    $eventConn->close();
    exit();
}

// Step 5b: Fetch registrations
$limitClause = ($page_size === 0) ? "" : "LIMIT ? OFFSET ?";

$query = "
    SELECT 
        er.*, 
        a.unique_id, a.prefix, a.first_name, a.last_name, a.short_name AS attendee_short_name,
        a.primary_email_address, a.secondary_email, a.country_code, a.primary_phone_number,
        a.secondary_mobile_number, a.city, a.state, a.country, a.pincode, a.profession, a.workplace_name,
        a.designation, a.professional_registration_number, a.registration_state, a.registration_type,
        a.area_of_interest, a.profile_photo, a.birth_date, a.bio,
        c.name AS category_name, c.is_lunch, c.is_dinner, c.is_kit, c.is_certificate, c.is_travel
    FROM {$eventDb}.event_registrations er
    LEFT JOIN {$mainDb}.attendees_1 a ON er.user_id = a.id
    LEFT JOIN {$eventDb}.event_categories c ON er.category_id = c.id
    WHERE er.is_deleted = 0 AND er.event_id = ?
    $limitClause
";

if ($page_size === 0) {
    // No pagination
    $stmt = $eventConn->prepare($query);
    $stmt->bind_param("i", $event_id);
} else {
    // With pagination
    $stmt = $eventConn->prepare($query);
    $stmt->bind_param("iii", $event_id, $page_size, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

$registrations = [];
while ($row = $result->fetch_assoc()) {
    $registrations[] = $row;
}

// Clean up
$stmt->close();
$conn->close();
$eventConn->close();

// Output
echo json_encode([
    'success' => true,
    'total_count' => $totalCount,
    'page' => $page,
    'page_size' => $page_size,
    'data' => $registrations
]);
