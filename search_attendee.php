<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';
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

try {
    $pdoMain = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Main DB connection failed: ' . $e->getMessage()]);
    exit;
}

$where = [];
$params = [];

function esc($str) {
    return str_replace(['%', '_'], ['\%', '\_'], $str);
}

if ($name) {
    $where[] = "(first_name LIKE :name OR secondary_email LIKE :name)";
    $params[':name'] = '%' . esc($name) . '%';
}
if ($email) {
    $where[] = "(primary_email_address LIKE :email OR secondary_email LIKE :email)";
    $params[':email'] = '%' . esc($email) . '%';
}
if ($phone) {
    $where[] = "(primary_phone_number LIKE :phone OR secondary_mobile_number LIKE :phone)";
    $params[':phone'] = '%' . esc($phone) . '%';
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

$stmt = $pdoMain->prepare($sql);
$stmt->execute($params);
$attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$attendees) {
    echo json_encode(['success' => true, 'attendees' => []]);
    exit;
}

$eventDbs = $pdoMain->query("SHOW DATABASES LIKE 'prop_propass_event_%'")->fetchAll(PDO::FETCH_COLUMN);
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
