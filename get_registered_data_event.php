<?php
header('Content-Type: application/json');
require_once 'auth_api.php';
require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';

// Read input
$input = json_decode(file_get_contents("php://input"), true);
$event_id = isset($input['event_id']) ? intval($input['event_id']) : 0;
$page = isset($input['page']) ? max(1, intval($input['page'])) : 1;
$page_size = isset($input['page_size']) ? intval($input['page_size']) : 20;

if (!$event_id) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit();
}

// Connect to event DB
$connectionResult = connectEventDb($event_id);
if (!$connectionResult['success']) {
    echo json_encode($connectionResult);
    exit();
}

$eventConn = $connectionResult['conn'];
$eventDb = $connectionResult['db_name'];

// Pagination setup
$offset = ($page - 1) * $page_size;

// Get count of registrations
$countQuery = "SELECT COUNT(*) as total_count FROM {$eventDb}.event_registrations WHERE is_deleted = 0 AND event_id = ?";
$countStmt = $eventConn->prepare($countQuery);
$countStmt->bind_param("i", $event_id);
$countStmt->execute();
$countResult = $countStmt->get_result();
$registeredCount = $countResult->fetch_assoc()['total_count'] ?? 0;
$countStmt->close();

// Get count of event industries
$industryCountQuery = "SELECT COUNT(*) as total_count FROM {$eventDb}.event_industries WHERE is_deleted = 0 AND event_id = ?";
$industryCountStmt = $eventConn->prepare($industryCountQuery);
$industryCountStmt->bind_param("i", $event_id);
$industryCountStmt->execute();
$industryCountResult = $industryCountStmt->get_result();
$industriesCount = $industryCountResult->fetch_assoc()['total_count'] ?? 0;
$industryCountStmt->close();

// If no registrations and industries, return empty
if ($registeredCount === 0 && $industriesCount === 0) {
    echo json_encode([
        'success' => true,
        'total_count' => 0,
        'registered_count' => 0,
        'industries_count' => 0,
        'page' => $page,
        'page_size' => $page_size,
        'data' => [],
        'event_industries' => []
    ]);
    $eventConn->close();
    exit();
}

// Prepare LIMIT clause (inject directly since bind_param does not support LIMIT/OFFSET)
$limitOffsetClause = $page_size > 0 ? "LIMIT $page_size OFFSET $offset" : "";

// Fetch registrations with pagination
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
    LEFT JOIN {$mainDb}." . TABLE_ATTENDEES . " a ON er.user_id = a.id
    LEFT JOIN {$eventDb}.event_categories c ON er.category_id = c.id
    WHERE er.is_deleted = 0 AND er.event_id = ?
    $limitOffsetClause
";

$stmt = $eventConn->prepare($query);
$stmt->bind_param("i", $event_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
$stmt->close();

// Fetch event industries (no pagination)
$industriesQuery = "
    SELECT 
        ei.id, ei.event_id, ei.name, ei.category_id, ec.name AS category_name,
        ei.printing_category, ei.unique_value, 
        ei.added_by, ei.status, ei.is_deleted, ei.created_at, ei.modified_at 
    FROM {$eventDb}.event_industries ei
    LEFT JOIN {$eventDb}.event_categories ec ON ei.category_id = ec.id
    WHERE ei.is_deleted = 0 AND ei.event_id = ?
";

$industriesStmt = $eventConn->prepare($industriesQuery);
$industriesStmt->bind_param("i", $event_id);
$industriesStmt->execute();
$industriesResult = $industriesStmt->get_result();

$eventIndustries = [];
while ($row = $industriesResult->fetch_assoc()) {
    $eventIndustries[] = $row;
}
$industriesStmt->close();

// Close DB connection
$eventConn->close();

// Return JSON response
echo json_encode([
    'success' => true,
    'total_count' => $registeredCount + $industriesCount,
    'registered_count' => $registeredCount,
    'industries_count' => $industriesCount,
    'page' => $page,
    'page_size' => $page_size,
    'data' => $data,
    'event_industries' => $eventIndustries
]);
