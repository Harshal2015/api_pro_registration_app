<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';
require_once 'auth_api.php';

$input = json_decode(file_get_contents('php://input'), true);
$name        = trim($input['name'] ?? '');
$email       = trim($input['email'] ?? '');
$phone       = trim($input['phone'] ?? '');
$eventId     = intval($input['eventId'] ?? 0);
$onlyRegs    = $input['only_event_registrations'] ?? false;

if ($eventId <= 0) {
    echo json_encode(['success'=>false,'message'=>'Valid event ID required']);
    exit;
}

// fetch event short name
$stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $eventId);
$stmt->execute();
$stmt->bind_result($eventShortName);
$stmt->fetch();
$stmt->close();

if (!$eventShortName) {
    echo json_encode(['success'=>false,'message'=>'Event not found']);
    exit;
}

function esc($s){ return str_replace(['%','_'], ['\%','\_'], $s); }

// build attendee search filters
$where=[]; $params=[]; $types='';
if ($name)   { $where[]="(first_name LIKE ? OR last_name LIKE ?)"; $params[]='%'.esc($name).'%'; $params[]='%'.esc($name).'%'; $types.='ss'; }
if ($email)  { $where[]="(primary_email_address LIKE ? OR secondary_email LIKE ?)"; $params[]='%'.esc($email).'%'; $params[]='%'.esc($email).'%'; $types.='ss'; }
if ($phone)  { $where[]="(primary_phone_number LIKE ? OR secondary_mobile_number LIKE ?)"; $params[]='%'.esc($phone).'%'; $params[]='%'.esc($phone).'%'; $types.='ss'; }
$whereSql = $where ? '(' . implode(' OR ', $where) . ') AND is_deleted=0' : 'is_deleted=0';

$sql = "SELECT id,prefix,first_name,last_name,primary_email_address,secondary_email,country_code,primary_phone_number,secondary_mobile_number,city,state,country,pincode,profession,workplace_name,designation
        FROM ". TABLE_ATTENDEES . "
        WHERE $whereSql LIMIT 50";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$attendees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($attendees as &$a) {
    $a['type'] = 'attendee';
    $a['registrations'] = [];
}
unset($a);

$res = connectEventDbByShortName($eventShortName);
if (!$res['success']) {
    echo json_encode(['success'=>false,'message'=>'Event DB connect failed']);
    exit;
}
$edb = $res['conn'];
$dbname = $res['db_name'];

$userIds = array_column($attendees,'id');
if (count($userIds)) {
    $in = implode(',', array_map('intval', $userIds));
    $regSql = "
      SELECT er.user_id, er.id AS event_reg_id, ec.name AS category_name,
             ec.is_kit, ec.is_lunch, ec.is_dinner,
             EXISTS(
               SELECT 1 FROM {$dbname}.event_scan_logg l
               WHERE l.registration_id = er.id
                 AND l.scan_for = 'badge'
                 AND l.status = 1
                 AND l.is_deleted = 0 LIMIT 1
             ) AS badge_issued,
             EXISTS(
               SELECT 1 FROM {$dbname}.event_scan_logg l
               WHERE l.registration_id = er.id
                 AND l.scan_for = 'kit'
                 AND l.status = 1
                 AND l.is_deleted = 0 LIMIT 1
             ) AS kit_issued
      FROM {$dbname}.event_registrations er
      JOIN {$dbname}.event_categories ec ON er.category_id = ec.id
      WHERE er.user_id IN ($in) AND er.event_id = ? AND er.is_deleted = 0";
    $stmt2 = $edb->prepare($regSql);
    $stmt2->bind_param('i',$eventId);
    $stmt2->execute();
    $regs = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt2->close();

    $regMap = [];
    foreach($regs as $r) {
        $r['badge_issued'] = intval($r['badge_issued']);
        $r['kit_issued']   = intval($r['kit_issued']);

        // check today's lunch/dinner issuance
        $regId = $r['event_reg_id'];
        $today = date('Y-m-d');

        // Lunch
        $stmtL = $edb->prepare("
          SELECT COUNT(*) AS cnt FROM event_scan_logs_food
          WHERE registration_id = ? AND scan_for = 'lunch' AND DATE(date)=? AND status=1 AND is_deleted=0
        ");
        $stmtL->bind_param('is',$regId,$today);
        $stmtL->execute();
        $lRes = $stmtL->get_result()->fetch_assoc()['cnt'];
        $stmtL->close();

        // Dinner
        $stmtD = $edb->prepare("
          SELECT COUNT(*) AS cnt FROM event_scan_logs_food
          WHERE registration_id = ? AND scan_for = 'dinner' AND DATE(date)=? AND status=1 AND is_deleted=0
        ");
        $stmtD->bind_param('is',$regId,$today);
        $stmtD->execute();
        $dRes = $stmtD->get_result()->fetch_assoc()['cnt'];
        $stmtD->close();

        $r['lunch_issued'] = $lRes > 0 ? 1 : 0;
        $r['dinner_issued'] = $dRes > 0 ? 1 : 0;

        $regMap[$r['user_id']][] = $r;
    }

    foreach ($attendees as &$a) {
        $a['registrations'] = $regMap[$a['id']] ?? [];
    }
    unset($a);
}

$industries = [];
$searchTerm = $name ?: $email ?: $phone;
if ($searchTerm) {
    $esc = esc($searchTerm);
    $q2 = "
    SELECT ei.id, ei.name, ei.unique_value, ei.printing_category,
           ec.name AS category_name, ec.is_kit, ec.is_lunch, ec.is_dinner
    FROM {$dbname}.event_industries ei
    LEFT JOIN {$dbname}.event_categories ec ON ei.category_id = ec.id
    WHERE (ei.name LIKE '%{$esc}%' OR ei.printing_category LIKE '%{$esc}%')
      AND ei.event_id = ? AND ei.is_deleted = 0
    LIMIT 50";
    $stmt3 = $edb->prepare($q2);
    $stmt3->bind_param('i',$eventId);
    $stmt3->execute();
    $inds = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt3->close();

    foreach ($inds as $r) {
        $regId = intval($r['unique_value']);
        $today = date('Y-m-d');

        // Lunch
        $stmtL = $edb->prepare("
          SELECT COUNT(*) AS cnt FROM event_scan_logs_food
          WHERE user_id = 0 AND registration_id = ? AND scan_for = 'lunch' AND DATE(date)=? AND status=1 AND is_deleted=0
        ");
        $stmtL->bind_param('is', $regId, $today);
        $stmtL->execute();
        $lunchCnt = intval($stmtL->get_result()->fetch_assoc()['cnt']);
        $stmtL->close();

        // Dinner
        $stmtD = $edb->prepare("
          SELECT COUNT(*) AS cnt FROM event_scan_logs_food
          WHERE user_id = 0 AND registration_id = ? AND scan_for = 'dinner' AND DATE(date)=? AND status=1 AND is_deleted=0
        ");
        $stmtD->bind_param('is', $regId, $today);
        $stmtD->execute();
        $dinnerCnt = intval($stmtD->get_result()->fetch_assoc()['cnt']);
        $stmtD->close();

        // Badge issued for industry (user_id=0)
        $stmtB = $edb->prepare("
          SELECT COUNT(*) AS cnt FROM event_scan_logg
          WHERE user_id = 0 AND registration_id = ? AND scan_for = 'badge' AND status = 1 AND is_deleted = 0
        ");
        $stmtB->bind_param('i', $regId);
        $stmtB->execute();
        $badgeIssuedCnt = intval($stmtB->get_result()->fetch_assoc()['cnt']);
        $stmtB->close();

        // Kit issued for industry (user_id=0)
        $stmtK = $edb->prepare("
          SELECT COUNT(*) AS cnt FROM event_scan_logg
          WHERE user_id = 0 AND registration_id = ? AND scan_for = 'kit' AND status = 1 AND is_deleted = 0
        ");
        $stmtK->bind_param('i', $regId);
        $stmtK->execute();
        $kitIssuedCnt = intval($stmtK->get_result()->fetch_assoc()['cnt']);
        $stmtK->close();

        $industries[] = [
            'type' => 'industry',
            'industry' => [
                'id'=> intval($r['id']),
                'name'=> $r['name'],
                'unique_value'=>$r['unique_value'],
                'printing_category'=>$r['printing_category'],
                'category_name'=>$r['category_name'],
                'is_kit'=>intval($r['is_kit']),
                'is_lunch'=>intval($r['is_lunch']),
                'is_dinner'=>intval($r['is_dinner']),
                'badge_issued'=> $badgeIssuedCnt > 0 ? 1 : 0,
                'kit_issued'=> $kitIssuedCnt > 0 ? 1 : 0,
                'lunch_issued'=> $lunchCnt > 0 ? 1 : 0,
                'dinner_issued'=> $dinnerCnt > 0 ? 1 : 0
            ]
        ];
    }
}

if ($onlyRegs) {
    $attendees = array_filter($attendees, fn($a)=>count($a['registrations'])>0);
}

$results = array_merge(array_values($attendees), $industries);
echo json_encode(['success'=>true,'results'=>$results]);