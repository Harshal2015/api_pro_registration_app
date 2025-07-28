<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';          // $conn = main DB connection
require_once 'connect_event_database.php'; 
require_once 'tables.php';
require_once 'auth_api.php';

$input = json_decode(file_get_contents('php://input'), true);

$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');
$onlyEventRegistrations = $input['only_event_registrations'] ?? false;
$eventId = intval($input['eventId'] ?? 0);

if ($eventId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid event ID is required']);
    exit;
}

if (!$name && !$email && !$phone) {
    echo json_encode(['success' => false, 'message' => 'Provide name, email, or phone']);
    exit;
}

// Map event ID to event short name from main DB
$eventShortName = '';
$stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $eventId);
$stmt->execute();
$stmt->bind_result($shortNameResult);
if ($stmt->fetch()) {
    $eventShortName = $shortNameResult;
}
$stmt->close();

if (!$eventShortName) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

// Escape LIKE wildcards for safe LIKE queries
function esc($str) {
    return str_replace(['%', '_'], ['\%', '\_'], $str);
}

// --- Step 1: Query attendees FROM MAIN DATABASE (attendees table is here) ---

$where = [];
$params = [];
$types = '';

if ($name) {
    $where[] = "(first_name LIKE ? OR last_name LIKE ?)";
    $like = '%' . esc($name) . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
if ($email) {
    $where[] = "(primary_email_address LIKE ? OR secondary_email LIKE ?)";
    $like = '%' . esc($email) . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}
if ($phone) {
    $where[] = "(primary_phone_number LIKE ? OR secondary_mobile_number LIKE ?)";
    $like = '%' . esc($phone) . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

$whereSql = $where ? '(' . implode(' OR ', $where) . ') AND is_deleted = 0' : 'is_deleted = 0';

$sql = "SELECT
    id, prefix, first_name, last_name,
    primary_email_address, secondary_email,
    country_code, primary_phone_number, secondary_mobile_number,
    city, state, country, pincode, profession,
    workplace_name, designation
FROM " . TABLE_ATTENDEES . "
WHERE $whereSql
LIMIT 50";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$attendees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Initialize registrations for attendees
foreach ($attendees as &$att) {
    $att['registrations'] = [];
}

// --- Step 2: Connect to EVENT-SPECIFIC database using short name ---
$res = connectEventDbByShortName($eventShortName);
if (!$res['success']) {
    echo json_encode(['success' => false, 'message' => 'Could not connect to event database']);
    exit;
}
$edb = $res['conn'];

// --- Step 3: Fetch registrations for attendee IDs from event DB ---
$ids = array_column($attendees, 'id');
if (count($ids) > 0) {
    $inClause = implode(',', array_map('intval', $ids));

    $q1 = "
        SELECT er.user_id, er.id AS event_reg_id, ec.name AS category_name
        FROM event_registrations er
        JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.user_id IN ($inClause) AND er.is_deleted = 0";

    $r1 = $edb->query($q1);
    $registrations = [];
    while ($r = $r1->fetch_assoc()) {
        $registrations[$r['user_id']][] = [
            'event' => $eventShortName,
            'category_name' => $r['category_name'],
            'event_registration_id' => $r['event_reg_id'],
        ];
    }

    foreach ($attendees as &$att) {
        $uid = $att['id'];
        $att['registrations'] = $registrations[$uid] ?? [];
    }
}

// --- Step 4: Fetch industries matching search term in event DB ---
$industrySearchTerm = $name ?: $email ?: $phone;
$industries = [];

if ($industrySearchTerm) {
    $searchEsc = esc($industrySearchTerm);
    $q2 = "
        SELECT 
            ei.id,
            ei.name,ei.unique_value,
            ec.name AS category_name,
            ei.printing_category
        FROM event_industries ei
        LEFT JOIN event_categories ec ON ei.category_id = ec.id
        WHERE (ei.name LIKE '%$searchEsc%' OR ei.printing_category LIKE '%$searchEsc%')
          AND ei.is_deleted = 0
        LIMIT 50";

    $r2 = $edb->query($q2);
    while ($r = $r2->fetch_assoc()) {
        $industries[] = [
            'type' => 'industry',
            'event' => $eventShortName,
            'industry' => [
                'id' => $r['id'],
                'name' => $r['name'],
                'category_name' => $r['category_name'],
                'unique_value' => $r['unique_value'],
                'printing_category' => $r['printing_category'],
            ],
        ];
    }
}

$edb->close();

// --- Step 5: Filter attendees by registration if requested ---
if ($onlyEventRegistrations) {
    $attendees = array_filter($attendees, fn($a) => count($a['registrations']) > 0);
}

// --- Step 6: Add type to attendees and clean data ---
foreach ($attendees as &$att) {
    $att['type'] = 'attendee';
    $att['registered_events'] = $att['registrations'];
    unset($att['registrations']);
}

// --- Step 7: Merge attendees + industries and output JSON ---
$finalResults = array_merge($attendees, $industries);

echo json_encode([
    'success' => true,
    'results' => array_values($finalResults),
]);
exit;
