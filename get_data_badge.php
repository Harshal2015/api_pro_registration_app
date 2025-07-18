<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';           
require_once 'connect_event_database.php'; 
require_once 'tables.php';

try {
    $event_id = $_GET['event_id'] ?? $_POST['event_id'] ?? null;
    if (!$event_id) throw new Exception("Missing event_id");

    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) throw new Exception("Event not found");

    $event = $result->fetch_assoc();
    $short = strtolower($event['short_name']);
    $stmt->close();

    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) throw new Exception($eventResult['message']);
    $eventConn = $eventResult['conn'];

    $scanStmt = $eventConn->prepare("
        SELECT user_id, attendee_id, print_type, date, time
        FROM event_scan_logg
        WHERE event_id = ? AND scan_for = 'badge' AND is_deleted = 0
        ORDER BY date ASC, time ASC
    ");
    $scanStmt->bind_param("i", $event_id);
    $scanStmt->execute();
    $scanResult = $scanStmt->get_result();

    $scanMap = [];
    while ($s = $scanResult->fetch_assoc()) {
        $key = $s['user_id'] . ':' . $s['attendee_id'];
        $dt = $s['date'] . ' ' . $s['time'];
        $ptype = strtolower($s['print_type']);

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

    $regStmt = $eventConn->prepare("
        SELECT er.user_id, er.id AS attendee_id, ec.name AS category_name
        FROM event_registrations er
        LEFT JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.event_id = ? AND er.is_deleted = 0
    ");
    $regStmt->bind_param("i", $event_id);
    $regStmt->execute();
    $regResult = $regStmt->get_result();

    $registrations = [];
    $userIds = [];
    while ($reg = $regResult->fetch_assoc()) {
        $registrations[] = $reg;
        $userIds[] = $reg['user_id'];
    }
    $regStmt->close();

    $userIds = array_unique($userIds);
    $attendees = [];

    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $attStmt = $conn->prepare("
            SELECT id, prefix, short_name, first_name, last_name
            FROM " . TABLE_ATTENDEES . "
            WHERE id IN ($placeholders)
        ");
        $attStmt->bind_param($types, ...$userIds);
        $attStmt->execute();
        $attResult = $attStmt->get_result();
        while ($row = $attResult->fetch_assoc()) {
            $attendees[$row['id']] = $row;
        }
        $attStmt->close();
    }

    $report = [];
    foreach ($registrations as $reg) {
        $key = $reg['user_id'] . ':' . $reg['attendee_id'];
        $attendeeName = 'Unknown Attendee';

        if (isset($attendees[$reg['user_id']])) {
            $att = $attendees[$reg['user_id']];
            $prefix = trim($att['prefix'] ?? '');
            $firstName = trim($att['first_name'] ?? '');
            $lastName = trim($att['last_name'] ?? '');
            $shortName = trim($att['short_name'] ?? '');

            $fullName = trim("$prefix $firstName $lastName");
            if ($shortName !== '') {
                $attendeeName = trim("$prefix $shortName");
            } else {
                $attendeeName = $fullName;
            }

            if ($attendeeName === '') {
                $attendeeName = 'Unknown Attendee';
            }
        }

        $statusEntries = [];

        if (!empty($scanMap[$key]['issued'])) {
            $statusEntries[] = $scanMap[$key]['issued'];
        }

        foreach ($scanMap[$key]['reissued'] ?? [] as $re) {
            $statusEntries[] = $re;
        }

        if (empty($statusEntries)) {
            $statusEntries[] = ['status' => 'Not Collected', 'date_time' => null];
        }

        foreach ($statusEntries as $entry) {
            $report[] = [
                'user_id'       => $reg['user_id'],
                'attendee_id'   => $reg['attendee_id'],
                'attendee_name' => $attendeeName,
                'category_name' => $reg['category_name'] ?? 'Unknown Category',
                'status'        => $entry['status'],
                'collected_at'  => $entry['date_time'],
            ];
        }
    }

    $total = count($registrations);
    $collectedKeys = [];
    $totalReissued = 0;

    foreach ($report as $row) {
        $key = $row['user_id'] . ':' . $row['attendee_id'];
        if ($row['status'] !== 'Not Collected') {
            $collectedKeys[$key] = true;
        }
        if ($row['status'] === 'Reissued') {
            $totalReissued++;
        }
    }

    $totalCollected = count($collectedKeys);
    $totalNotCollected = $total - $totalCollected;

    echo json_encode([
        'event_id'            => $event_id,
        'short_name'          => $short,
        'total'               => $total,
        'total_collected'     => $totalCollected,
        'total_reissued'      => $totalReissued,
        'total_not_collected' => $totalNotCollected,
        'report'              => $report,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
