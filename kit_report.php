<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'auth_api.php';
require_once 'config.php';          
require_once 'connect_event_database.php';
require_once 'tables.php';


try {
    $event_id = $_GET['event_id'] ?? $input['event_id'] ?? null;
    if (!$event_id) throw new Exception('Missing event_id');

    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) throw new Exception('Event not found');
    $event = $result->fetch_assoc();
    $short = strtolower($event['short_name']);
    $stmt->close();

    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) throw new Exception($eventResult['message']);
    $eventConn = $eventResult['conn'];

    $scanStmt = $eventConn->prepare("
        SELECT user_id, registration_id, print_type, date, time
        FROM event_scan_logg
        WHERE event_id = ? AND scan_for = 'kit' AND is_deleted = 0
        ORDER BY date ASC, time ASC
    ");
    $scanStmt->bind_param("i", $event_id);
    $scanStmt->execute();
    $scanResult = $scanStmt->get_result();
    $scans = [];
    while ($row = $scanResult->fetch_assoc()) {
        $scans[] = $row;
    }
    $scanStmt->close();

    $scanMap = [];
    foreach ($scans as $s) {
        $key = $s['user_id'] . ':' . $s['registration_id'];
        $dt = $s['date'] . ' ' . $s['time'];
        $ptype = strtolower($s['print_type']);

        if (!isset($scanMap[$key])) {
            $scanMap[$key] = ['issued' => null, 'reissued' => []];
        }
        if ($ptype === 'issued') {
            if ($scanMap[$key]['issued'] === null || $dt < $scanMap[$key]['issued']['date_time']) {
                $scanMap[$key]['issued'] = ['status' => 'Kit Collected (Issued)', 'date_time' => $dt];
            }
        }
        if ($ptype === 'reissued') {
            $scanMap[$key]['reissued'][] = ['status' => 'Kit Reissued (Reissued)', 'date_time' => $dt];
        }
    }

    $regStmt = $eventConn->prepare("
        SELECT er.user_id, er.id AS registration_id, ec.name AS category_name
        FROM event_registrations er
        LEFT JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.event_id = ? AND er.is_deleted = 0
    ");
    $regStmt->bind_param("i", $event_id);
    $regStmt->execute();
    $regResult = $regStmt->get_result();
    $registrations = [];
    $userIds = [];

    while ($row = $regResult->fetch_assoc()) {
        $registrations[] = $row;
        $userIds[] = $row['user_id'];
    }
    $regStmt->close();
    $userIds = array_unique($userIds);

    $attendees = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $query = "SELECT id, prefix, short_name, first_name, last_name FROM " . TABLE_ATTENDEES . " WHERE id IN ($placeholders)";
        $attStmt = $conn->prepare($query);
        $attStmt->bind_param($types, ...$userIds);
        $attStmt->execute();
        $attResult = $attStmt->get_result();
        while ($att = $attResult->fetch_assoc()) {
            $attendees[$att['id']] = $att;
        }
        $attStmt->close();
    }

    $report = [];
    foreach ($registrations as $reg) {
        $key = $reg['user_id'] . ':' . $reg['registration_id'];
        $attName = 'Unknown Attendee';

        if (isset($attendees[$reg['user_id']])) {
            $a = $attendees[$reg['user_id']];
            $prefix = trim($a['prefix'] ?? '');
            $shortName = trim($a['short_name'] ?? '');
            $firstName = trim($a['first_name'] ?? '');
            $lastName = trim($a['last_name'] ?? '');

            if (!empty($shortName)) {
                $attName = trim("$prefix $shortName");
            } else {
                $attName = trim("$prefix $firstName $lastName");
            }

            if (empty($attName)) {
                $attName = 'Unknown Attendee';
            }
        }

        $entrySet = $scanMap[$key] ?? ['issued' => null, 'reissued' => []];
        $entries = [];

        if (!empty($entrySet['issued'])) {
            $entries[] = $entrySet['issued'];
        }

        foreach ($entrySet['reissued'] as $r) {
            $entries[] = $r;
        }

        if (empty($entries)) {
            $entries[] = ['status' => 'Kit Not Collected', 'date_time' => null];
        }

        foreach ($entries as $entry) {
            $report[] = [
                'user_id'       => $reg['user_id'],
                'registration_id'   => $reg['registration_id'],
                'attendee_name' => $attName,
                'category_name' => $reg['category_name'] ?? 'Unknown Category',
                'status'        => $entry['status'],
                'collected_at'  => $entry['date_time'],
            ];
        }
    }

    $total = count($registrations);
    $collectedKeys = [];
    $reissued = 0;

    foreach ($report as $r) {
        $key = $r['user_id'] . ':' . $r['registration_id'];
        if ($r['status'] !== 'Kit Not Collected') {
            $collectedKeys[$key] = true;
        }
        if (stripos($r['status'], 'Reissued') !== false) {
            $reissued++;
        }
    }

    $colCount = count($collectedKeys);
    $notCount = $total - $colCount;

    echo json_encode([
        'event_id'            => $event_id,
        'short_name'          => $short,
        'total'               => $total,
        'total_collected'     => $colCount,
        'total_reissued'      => $reissued,
        'total_not_collected' => $notCount,
        'report'              => $report,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
