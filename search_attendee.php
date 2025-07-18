<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';  // uses $conn
require_once 'connect_event_database.php';
require_once 'tables.php';

$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');

if (!$email && !$phone) {
    echo json_encode(['success' => false, 'message' => 'Provide email or phone']);
    exit;
}

$where = [];
$params = [];
$types = '';

function esc($str) {
    return str_replace(['%', '_'], ['\%', '\_'], $str);
}

if ($name) {
    $where[] = "(first_name LIKE ? OR secondary_email LIKE ?)";
    $likeName = '%' . esc($name) . '%';
    $params[] = $likeName;
    $params[] = $likeName;
    $types .= 'ss';
}
if ($email) {
    $where[] = "(primary_email_address LIKE ? OR secondary_email LIKE ?)";
    $likeEmail = '%' . esc($email) . '%';
    $params[] = $likeEmail;
    $params[] = $likeEmail;
    $types .= 'ss';
}
if ($phone) {
    $where[] = "(primary_phone_number LIKE ? OR secondary_mobile_number LIKE ?)";
    $likePhone = '%' . esc($phone) . '%';
    $params[] = $likePhone;
    $params[] = $likePhone;
    $types .= 'ss';
}

$whereSql = count($where) > 0 ? '(' . implode(' OR ', $where) . ") AND is_deleted = 0" : 'is_deleted = 0';

$sql = "SELECT 
    id, unique_id, prefix, first_name, last_name, short_name,
    primary_email_address, primary_email_address_verified, secondary_email,
    country_code, primary_phone_number, primary_phone_number_verified,
    secondary_mobile_number, city, state, country, pincode,
    profession, workplace_name, designation, professional_registration_number,
    registration_state, registration_type, added_by, area_of_interest,
    is_verified, profile_photo, birth_date, bio,
    is_deleted, created_at, modified_at
FROM " . TABLE_ATTENDEES . "
WHERE $whereSql
LIMIT 50";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$attendees = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$attendees) {
    echo json_encode(['success' => true, 'attendees' => []]);
    exit;
}

$eventDbsResult = $conn->query("SHOW DATABASES LIKE 'prop_propass_event_%'");
$eventDbs = [];
while ($row = $eventDbsResult->fetch_row()) {
    $eventDbs[] = $row[0];
}

$registrationsMap = [];
$ids = array_column($attendees, 'id');
$in = implode(',', array_map('intval', $ids));

if (!$in) {
    echo json_encode(['success' => true, 'attendees' => $attendees]);
    exit;
}

foreach ($eventDbs as $eventDbName) {
    $shortName = str_replace('prop_propass_event_', '', $eventDbName);
    $eventConnResult = connectEventDbByShortName($shortName);
    if (!$eventConnResult['success']) continue;

    $eventConn = $eventConnResult['conn'];
    $sqlCheck = "SELECT user_id FROM event_registrations WHERE user_id IN ($in) AND is_deleted = 0";
    $result = $eventConn->query($sqlCheck);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $registrationsMap[$row['user_id']][] = $shortName;
        }
    }

    $eventConn->close();
}

foreach ($attendees as &$attendee) {
    $attendee['registered_events'] = $registrationsMap[$attendee['id']] ?? [];
}

echo json_encode(['success' => true, 'attendees' => $attendees]);
