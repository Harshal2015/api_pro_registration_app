<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';
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

// get event short name
$stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $eventId);
$stmt->execute();
$stmt->bind_result($eventShortName);
$stmt->fetch();
$stmt->close();
if (!$eventShortName) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

function esc($str) { return str_replace(['%', '_'], ['\%', '\_'], $str); }

// build attendee search
$where = []; $params = []; $types = '';
if ($name) {
    $where[] = "(first_name LIKE ? OR last_name LIKE ?)";
    $like = '%' . esc($name) . '%';
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
if ($email) {
    $where[] = "(primary_email_address LIKE ? OR secondary_email LIKE ?)";
    $like = '%' . esc($email) . '%';
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
if ($phone) {
    $where[] = "(primary_phone_number LIKE ? OR secondary_mobile_number LIKE ?)";
    $like = '%' . esc($phone) . '%';
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
$whereSql = $where ? '(' . implode(' OR ', $where) . ') AND is_deleted = 0' : 'is_deleted = 0';

// fetch attendees from global db
$sql = "SELECT id, prefix, first_name, last_name, primary_email_address, secondary_email, country_code, primary_phone_number, secondary_mobile_number, city, state, country, pincode, profession, workplace_name, designation
        FROM " . TABLE_ATTENDEES . " WHERE $whereSql LIMIT 50";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$attendees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// initialize registrations field
foreach ($attendees as &$att) {
    $att['registrations'] = [];
}

// connect to event-specific database
$res = connectEventDbByShortName($eventShortName);
if (!$res['success']) {
    echo json_encode(['success' => false, 'message' => 'Could not connect to event database']);
    exit;
}
$edb = $res['conn'];

$userIds = array_column($attendees, 'id');
if (count($userIds) > 0) {
    $inClause = implode(',', array_map('intval', $userIds));
    $q = "
        SELECT er.user_id, er.id AS event_reg_id, ec.name AS category_name,
               ec.is_kit, ec.is_dinner, ec.is_lunch
        FROM event_registrations er
        JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.user_id IN ($inClause)
          AND er.event_id = ?
          AND er.is_deleted = 0
    ";
    $stmt2 = $edb->prepare($q);
    $stmt2->bind_param('i', $eventId);
    $stmt2->execute();
    $regs = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    $regMap = [];
    foreach ($regs as $r) {
        $uid = $r['user_id'];
        $regMap[$uid][] = [
            'event_registration_id' => $r['event_reg_id'],
            'category_name'         => $r['category_name'],
            'is_kit'                => intval($r['is_kit']),
                 'is_lunch'             => intval($r['is_lunch']),
            'is_dinner'             => intval($r['is_dinner']),
        ];
    }
    foreach ($attendees as &$att) {
        $att['registrations'] = $regMap[$att['id']] ?? [];
    }
}

// industry search
$industryTerm = $name ?: $email ?: $phone;
$industries = [];
if ($industryTerm) {
    $esc = esc($industryTerm);
    $q2 = "
        SELECT ei.id, ei.name, ei.unique_value,
               ec.name AS category_name, ec.is_kit, ec.is_dinner, ec.is_lunch
        FROM event_industries ei
        LEFT JOIN event_categories ec ON ei.category_id = ec.id
        WHERE (ei.name LIKE '%$esc%' OR ei.printing_category LIKE '%$esc%')
          AND ei.event_id = ?
          AND ei.is_deleted = 0
        LIMIT 50
    ";
    $stmt3 = $edb->prepare($q2);
    $stmt3->bind_param('i', $eventId);
    $stmt3->execute();
    $inds = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt3->close();

    foreach ($inds as $r) {
        $industries[] = [
            'type'              => 'industry',
            'industry'          => [
                'id'            => intval($r['id']),
                'name'          => $r['name'],
                'unique_value'  => $r['unique_value'],
                'category_name' => $r['category_name'],
                'is_kit'        => intval($r['is_kit']),
                  'is_lunch'     => intval($r['is_lunch']),
                'is_dinner'     => intval($r['is_dinner']),
              
            ],
            'event'             => $eventShortName,
        ];
    }
}

$edb->close();

if ($onlyEventRegistrations) {
    $attendees = array_filter($attendees, fn($a) => count($a['registrations']) > 0);
}

// tag final
foreach ($attendees as &$a) {
    $a['type'] = 'attendee';
}

$results = array_merge(array_values($attendees), $industries);
echo json_encode(['success' => true, 'results' => $results]);
exit;
