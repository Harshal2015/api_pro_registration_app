<?php
header('Content-Type: application/json');

require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';

$input = json_decode(file_get_contents("php://input"), true);
$event_id = isset($input['event_id']) ? intval($input['event_id']) : 0;
$page = isset($input['page']) ? max(1, intval($input['page'])) : 1;
$page_size = isset($input['page_size']) ? intval($input['page_size']) : 20;

if (!$event_id) {
    echo json_encode(['success' => false, 'message' => 'Event ID is required']);
    exit();
}

$connectionResult = connectEventDb($event_id);
if (!$connectionResult['success']) {
    echo json_encode($connectionResult);
    exit();
}

$eventConn = $connectionResult['conn'];
$eventDb = $connectionResult['db_name'];

$offset = ($page - 1) * $page_size;

$countQuery = "SELECT COUNT(*) as total_count FROM {$eventDb}.event_registrations WHERE is_deleted = 0 AND event_id = ?";
$countStmt = $eventConn->prepare($countQuery);
$countStmt->bind_param("i", $event_id);
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalCount = $countResult->fetch_assoc()['total_count'] ?? 0;
$countStmt->close();

if ($totalCount === 0) {
    echo json_encode(['success' => true, 'total_count' => 0, 'data' => []]);
    $eventConn->close();
    exit();
}

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
    LEFT JOIN {$mainDb}." . TABLE_ATTENDEES . " a ON er.user_id = a.id
    LEFT JOIN {$eventDb}.event_categories c ON er.category_id = c.id
    WHERE er.is_deleted = 0 AND er.event_id = ?
    $limitClause
";

$stmt = ($page_size === 0)
    ? $eventConn->prepare($query)
    : $eventConn->prepare($query);

if ($page_size === 0) {
    $stmt->bind_param("i", $event_id);
} else {
    $stmt->bind_param("iii", $event_id, $page_size, $offset);
}

$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$stmt->close();
$eventConn->close();

echo json_encode([
    'success' => true,
    'total_count' => $totalCount,
    'page' => $page,
    'page_size' => $page_size,
    'data' => $data
]);
