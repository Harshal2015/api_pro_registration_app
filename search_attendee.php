<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$mainDb = ['host' => 'localhost', 'db' => 'prop_propass', 'user' => 'root', 'pass' => ''];

$input = json_decode(file_get_contents('php://input'), true);
$name  = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');

if (!$name && !$email && !$phone) {
    echo json_encode(['success' => false, 'message' => 'Provide name, email, or phone']);
    exit;
}

try {
    $pdoMain = new PDO("mysql:host={$mainDb['host']};dbname={$mainDb['db']};charset=utf8mb4",
        $mainDb['user'], $mainDb['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
    // Match full short_name field (partial or exact)
    // Use LIKE '%full_name%'
    $where[] = "short_name LIKE :short_name";
    $params[':short_name'] = '%' . esc($name) . '%';
}

if ($email) {
    $where[] = "(primary_email_address LIKE :email OR secondary_email LIKE :email)";
    $params[':email'] = '%' . esc($email) . '%';
}

if ($phone) {
    $where[] = "(primary_phone_number LIKE :phone OR secondary_mobile_number LIKE :phone)";
    $params[':phone'] = '%' . esc($phone) . '%';
}

// Combine all with OR so any match will be found
$whereSql = count($where) > 0 ? '(' . implode(' OR ', $where) . ") AND is_deleted = 0" : 'is_deleted = 0';

$sql = "SELECT 
    id,
    unique_id,
    prefix,
    first_name,
    last_name,
    short_name,
    primary_email_address,
    primary_email_address_verified,
    secondary_email,
    country_code,
    primary_phone_number,
    primary_phone_number_verified,
    secondary_mobile_number,
    city,
    state,
    country,
    pincode,
    profession,
    workplace_name,
    designation,
    professional_registration_number,
    registration_state,
    registration_type,
    added_by,
    area_of_interest,
    is_verified,
    profile_photo,
    birth_date,
    bio,
    is_deleted,
    created_at,
    modified_at
FROM attendees_1 
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

foreach ($eventDbs as $eventDb) {
    try {
        $pdoEv = new PDO("mysql:host={$mainDb['host']};dbname=$eventDb;charset=utf8mb4",
            $mainDb['user'], $mainDb['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $shortName = str_replace('prop_propass_event_', '', $eventDb);
        $ids = array_column($attendees, 'id');
        $in = implode(',', array_map('intval', $ids));
        if (!$in) continue;

        $qr = $pdoEv->query("SELECT user_id FROM event_registrations WHERE user_id IN ($in) AND is_deleted = 0");
        foreach ($qr->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $registrationsMap[$uid][] = $shortName;
        }
    } catch (PDOException $e) {
        continue;
    }
}

foreach ($attendees as &$attendee) {
    $attendee['registered_events'] = $registrationsMap[$attendee['id']] ?? [];
}

echo json_encode(['success' => true, 'attendees' => $attendees]);
