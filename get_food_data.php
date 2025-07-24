<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'auth_api.php';
require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';

try {
    // Get event_id
    $inputEventId = null;
    if (isset($_GET['event_id'])) {
        $inputEventId = $_GET['event_id'];
    } else {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if ($data && isset($data['event_id'])) {
            $inputEventId = $data['event_id'];
        }
    }

    if (!$inputEventId || !is_numeric($inputEventId)) {
        throw new Exception('Missing or invalid event_id');
    }
    $event_id = (int)$inputEventId;

    // Fetch event short_name
    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) throw new Exception('Event not found');
    $short = strtolower($result->fetch_assoc()['short_name']);
    $stmt->close();

    // Connect to event DB
    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) throw new Exception($eventResult['message']);
    $eventConn = $eventResult['conn'];

    // Fetch food scan logs (lunch/dinner) including master QR counts
    $scanMap = [];
    $masterCount = ['lunch' => 0, 'dinner' => 0];
    $masterQrDaily = [];

    $scanStmt = $eventConn->prepare("
        SELECT user_id, registration_id, print_type, scan_for, date, time
        FROM event_scan_logs_food
        WHERE event_id = ? AND scan_for IN ('lunch', 'dinner') AND is_deleted = 0
        ORDER BY date ASC, time ASC
    ");
    $scanStmt->bind_param("i", $event_id);
    $scanStmt->execute();
    $scanResult = $scanStmt->get_result();

    while ($row = $scanResult->fetch_assoc()) {
        $key = $row['user_id'] . ':' . $row['registration_id'];
        $meal = strtolower($row['scan_for']);
        $ptype = strtolower(trim($row['print_type']));
        $date = $row['date'];
        $dt = $date . ' ' . $row['time'];

        if ($ptype === 'master qr') {
            $masterCount[$meal]++;
            if (!isset($masterQrDaily[$date])) {
                $masterQrDaily[$date] = ['lunch' => 0, 'dinner' => 0];
            }
            $masterQrDaily[$date][$meal]++;
            continue;
        }

        if (!isset($scanMap[$key])) {
            $scanMap[$key] = ['issued' => null, 'reissued' => []];
        }

        if ($ptype === 'issued') {
            if ($scanMap[$key]['issued'] === null || $dt < $scanMap[$key]['issued']['date_time']) {
                $scanMap[$key]['issued'] = ['status' => 'Meal Taken', 'date_time' => $dt, 'meal' => ucfirst($meal)];
            }
        } elseif ($ptype === 'reissued') {
            $scanMap[$key]['reissued'][] = ['status' => 'Meal Re-Taken', 'date_time' => $dt, 'meal' => ucfirst($meal)];
        }
    }
    $scanStmt->close();

    // Get attendee registrations
    $attendeeRegs = [];
    $userIds = [];
    $regStmt = $eventConn->prepare("
        SELECT er.user_id, er.id AS registration_id, ec.name AS category_name
        FROM event_registrations er
        LEFT JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.event_id = ? AND er.is_deleted = 0
    ");
    $regStmt->bind_param("i", $event_id);
    $regStmt->execute();
    $regResult = $regStmt->get_result();
    while ($reg = $regResult->fetch_assoc()) {
        $attendeeRegs[] = $reg;
        $userIds[] = $reg['user_id'];
    }
    $regStmt->close();

    // Get industry registrations (user_id=0)
    $industryRegs = [];
    $indStmt = $eventConn->prepare("
        SELECT id AS registration_id, name AS category_name
        FROM event_industries
        WHERE event_id = ? AND is_deleted = 0
    ");
    $indStmt->bind_param("i", $event_id);
    $indStmt->execute();
    $indResult = $indStmt->get_result();
    while ($ind = $indResult->fetch_assoc()) {
        $industryRegs[] = [
            'user_id' => 0,
            'registration_id' => $ind['registration_id'],
            'category_name' => $ind['category_name']
        ];
    }
    $indStmt->close();

    // Fetch attendee names
    $attendees = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $attStmt = $conn->prepare("
            SELECT id, prefix, short_name, first_name, last_name
            FROM " . TABLE_ATTENDEES . " WHERE id IN ($placeholders)
        ");
        // Bind params dynamically
        $bind_params = [];
        $bind_params[] = &$types;
        foreach ($userIds as $key => $id) {
            $bind_params[] = &$userIds[$key];
        }
        call_user_func_array([$attStmt, 'bind_param'], $bind_params);

        $attStmt->execute();
        $attResult = $attStmt->get_result();
        while ($row = $attResult->fetch_assoc()) {
            $attendees[$row['id']] = $row;
        }
        $attStmt->close();
    }

    // Function to build food meal report entries like kit script
    function buildFoodEntries($reg, $attendees, $scanMap, &$reportArray, &$statArray) {
        $key = "{$reg['user_id']}:{$reg['registration_id']}";
        $statArray['total']++;

        $entries = [];
        if (!empty($scanMap[$key]['issued'])) {
            $entries[] = $scanMap[$key]['issued'];
        }
        foreach ($scanMap[$key]['reissued'] ?? [] as $r) {
            $entries[] = $r;
        }
        if (empty($entries)) {
            $entries[] = ['status' => 'Not Taken', 'date_time' => null, 'meal' => null];
        }

        $name = 'Industry Participant';
        if ($reg['user_id'] != 0 && isset($attendees[$reg['user_id']])) {
            $a = $attendees[$reg['user_id']];
            $prefix = trim($a['prefix'] ?? '');
            $shortName = trim($a['short_name'] ?? '');
            $firstName = trim($a['first_name'] ?? '');
            $lastName = trim($a['last_name'] ?? '');
            $fullName = trim("$prefix $firstName $lastName");
            $name = ($shortName !== '') ? trim("$prefix $shortName") : $fullName;
            if ($name === '') $name = 'Unknown Attendee';
        }

        foreach ($entries as $entry) {
            $status = $entry['status'];
            if ($status === 'Meal Taken') $statArray['taken']++;
            if ($status === 'Meal Re-Taken') $statArray['retaken']++;
            if ($status === 'Not Taken') $statArray['not_taken']++;

            $reportArray[] = [
                'user_id'         => $reg['user_id'],
                'registration_id' => $reg['registration_id'],
                'attendee_name'   => $reg['user_id'] != 0 ? $name : '',
                'industry'        => $reg['user_id'] == 0 ? $name : '',
                'category_name'   => $reg['category_name'] ?? 'Unknown Category',
                'meal'            => $entry['meal'] ?? '',
                'status'          => $status,
                'taken_at'        => $entry['date_time']
            ];
        }
    }

    // Prepare reports and stats
    $attendeeReport = [];
    $industryReport = [];
    $stats = [
        'attendees' => ['total' => 0, 'taken' => 0, 'retaken' => 0, 'not_taken' => 0],
        'industries' => ['total' => 0, 'taken' => 0, 'retaken' => 0, 'not_taken' => 0]
    ];

    foreach ($attendeeRegs as $reg) {
        buildFoodEntries($reg, $attendees, $scanMap, $attendeeReport, $stats['attendees']);
    }
    foreach ($industryRegs as $reg) {
        buildFoodEntries($reg, [], $scanMap, $industryReport, $stats['industries']);
    }

    // Combine totals including master QR scans
    $combinedTotals = [
        'total' => $stats['attendees']['total'] + $stats['industries']['total'],
        'taken' => $stats['attendees']['taken'] + $stats['industries']['taken'] ,
        'retaken' => $stats['attendees']['retaken'] + $stats['industries']['retaken'],
        'not_taken' => $stats['attendees']['not_taken'] + $stats['industries']['not_taken'],
        'master_qr_lunch' => $masterCount['lunch'],
        'master_qr_dinner' => $masterCount['dinner'],
    ];

    echo json_encode([
        'event_id' => $event_id,
        'short_name' => $short,
        'stats' => $stats,
        'master_qr_daily' => $masterQrDaily,
        'combined_totals' => $combinedTotals,
        'attendee_report' => $attendeeReport,
        'industry_report' => $industryReport
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
