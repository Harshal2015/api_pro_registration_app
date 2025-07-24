<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'auth_api.php';
require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';

try {
    // Get event_id from GET or POST JSON body
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

    // Fetch short_name for event
    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) throw new Exception('Event not found');
    $short = strtolower($result->fetch_assoc()['short_name']);
    $stmt->close();

    // Connect event database
    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) throw new Exception($eventResult['message']);
    $eventConn = $eventResult['conn'];

    // Fetch scan logs
    $scanMap = [];
    $scanStmt = $eventConn->prepare("
        SELECT user_id, registration_id, print_type, date, time
        FROM event_scan_logg
        WHERE event_id = ? AND scan_for = 'kit' AND is_deleted = 0
        ORDER BY date ASC, time ASC
    ");
    $scanStmt->bind_param("i", $event_id);
    $scanStmt->execute();
    $scanResult = $scanStmt->get_result();
    while ($row = $scanResult->fetch_assoc()) {
        $key = "{$row['user_id']}:{$row['registration_id']}";
        $dt = "{$row['date']} {$row['time']}";
        $ptype = strtolower($row['print_type']);

        if (!isset($scanMap[$key])) {
            $scanMap[$key] = ['issued' => null, 'reissued' => []];
        }

        if ($ptype === 'issued') {
            if ($scanMap[$key]['issued'] === null || $dt < $scanMap[$key]['issued']['date_time']) {
                $scanMap[$key]['issued'] = ['status' => 'Collected', 'date_time' => $dt];
            }
        } elseif ($ptype === 'reissued') {
            $scanMap[$key]['reissued'][] = ['status' => 'Reissued', 'date_time' => $dt];
        }
    }
    $scanStmt->close();

    // Get attendees
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

    // Get industry entries
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

    // Get attendee names
    $attendees = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $attStmt = $conn->prepare("
            SELECT id, prefix, short_name, first_name, last_name
            FROM " . TABLE_ATTENDEES . " WHERE id IN ($placeholders)
        ");
        $attStmt->bind_param($types, ...$userIds);
        $attStmt->execute();
        $attResult = $attStmt->get_result();
        while ($row = $attResult->fetch_assoc()) {
            $attendees[$row['id']] = $row;
        }
        $attStmt->close();
    }

    $attendeeReport = [];
    $industryReport = [];
    $stats = [
        'attendees' => ['total' => 0, 'collected' => 0, 'reissued' => 0, 'not_collected' => 0],
        'industries' => ['total' => 0, 'collected' => 0, 'reissued' => 0, 'not_collected' => 0]
    ];

    function buildKitEntries($reg, $attendees, $scanMap, &$reportArray, &$statArray) {
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
            $entries[] = ['status' => 'Not Collected', 'date_time' => null];
        }

        $name = 'Industry Participant';
        if ($reg['user_id'] != 0 && isset($attendees[$reg['user_id']])) {
            $a = $attendees[$reg['user_id']];
            $name = trim(($a['prefix'] ?? '') . ' ' . ($a['short_name'] ?: ($a['first_name'] . ' ' . $a['last_name'])));
            if ($name === '') $name = 'Unknown Attendee';
        }

        foreach ($entries as $entry) {
            $status = $entry['status'];
            if ($status === 'Collected') $statArray['collected']++;
            if ($status === 'Reissued') $statArray['reissued']++;
            if ($status === 'Not Collected') $statArray['not_collected']++;

            $reportArray[] = [
                'user_id'         => $reg['user_id'],
                'registration_id' => $reg['registration_id'],
                'attendee_name'   => $reg['user_id'] != 0 ? $name : '',
                'industry'        => $reg['user_id'] == 0 ? $name : '',
                'category_name'   => $reg['category_name'] ?? 'Unknown Category',
                'status'          => $status,
                'collected_at'    => $entry['date_time']
            ];
        }
    }

    foreach ($attendeeRegs as $reg) {
        buildKitEntries($reg, $attendees, $scanMap, $attendeeReport, $stats['attendees']);
    }
    foreach ($industryRegs as $reg) {
        buildKitEntries($reg, [], $scanMap, $industryReport, $stats['industries']);
    }

    $combined = [
        'total' => $stats['attendees']['total'] + $stats['industries']['total'],
        'collected' => $stats['attendees']['collected'] + $stats['industries']['collected'],
        'reissued' => $stats['attendees']['reissued'] + $stats['industries']['reissued'],
        'not_collected' => $stats['attendees']['not_collected'] + $stats['industries']['not_collected']
    ];

    echo json_encode([
        'event_id' => $event_id,
        'short_name' => $short,
        'stats' => $stats,
        'combined_totals' => $combined,
        'attendee_report' => $attendeeReport,
        'industry_report' => $industryReport
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
