<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';           
require_once 'connect_event_database.php'; 
require_once 'tables.php';


try {
    $event_id = $_GET['event_id'] ?? $_POST['event_id'] ?? null;
    if (!$event_id) throw new Exception('Missing event_id');

    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) throw new Exception('Event not found');
    $event = $res->fetch_assoc();
    $short = strtolower($event['short_name']);
    $stmt->close();

    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) throw new Exception($eventResult['message']);
    $eventConn = $eventResult['conn'];

    $scanStmt = $eventConn->prepare("
        SELECT user_id, attendee_id, print_type, scan_for, date, time
        FROM event_scan_logs_food
        WHERE event_id = ? AND scan_for IN ('lunch', 'dinner') AND is_deleted = 0
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
    $masterCount = ['lunch' => 0, 'dinner' => 0];
    $totals = [
        'lunch_taken' => 0,
        'dinner_taken' => 0,
        'lunch_retaken' => 0,
        'dinner_retaken' => 0,
    ];

    foreach ($scans as $s) {
        $key = $s['user_id'] . ':' . $s['attendee_id'];
        $dt = $s['date'] . ' ' . $s['time'];
        $ptype = strtolower(trim($s['print_type']));
        $meal = strtolower(trim($s['scan_for']));

        if (!in_array($meal, ['lunch', 'dinner'])) continue;

        if ($ptype === 'master qr') {
            $masterCount[$meal]++;
            continue;
        }

        if (!isset($scanMap[$key])) {
            $scanMap[$key] = ['lunch' => [], 'dinner' => []];
        }
        $scanMap[$key][$meal][] = [
            'type' => $ptype,
            'date_time' => $dt,
        ];
    }

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

        $bind_names[] = $types;
        foreach ($userIds as $key => $id) {
            $bind_name = 'bind' . $key;
            $$bind_name = $id;
            $bind_names[] = &$$bind_name;
        }
        call_user_func_array([$attStmt, 'bind_param'], $bind_names);

        $attStmt->execute();
        $attResult = $attStmt->get_result();
        while ($att = $attResult->fetch_assoc()) {
            $attendees[$att['id']] = $att;
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
            $shortName = trim($att['short_name'] ?? '');
            $firstName = trim($att['first_name'] ?? '');
            $lastName = trim($att['last_name'] ?? '');
            $fullName = trim("$prefix $firstName $lastName");
            $attendeeName = ($shortName !== '') ? trim("$prefix $shortName") : $fullName;
        }

        foreach (['lunch', 'dinner'] as $meal) {
            $entries = $scanMap[$key][$meal] ?? [];

            if (empty($entries)) {
                $report[] = [
                    'user_id'       => $reg['user_id'],
                    'attendee_id'   => $reg['attendee_id'],
                    'attendee_name' => $attendeeName,
                    'category_name' => $reg['category_name'] ?? 'Unknown Category',
                    'meal'          => ucfirst($meal),
                    'status'        => 'Not Taken',
                    'taken_at'      => null,
                ];
                continue;
            }

            $hasAutoTaken = false;
            foreach ($entries as $entry) {
                if ($entry['type'] === 'issued') {
                    if (!$hasAutoTaken) {
                        $totals[$meal . '_taken']++;
                        $report[] = [
                            'user_id'       => $reg['user_id'],
                            'attendee_id'   => $reg['attendee_id'],
                            'attendee_name' => $attendeeName,
                            'category_name' => $reg['category_name'] ?? 'Unknown Category',
                            'meal'          => ucfirst($meal),
                            'status'        => 'Meal Taken (Issued)',
                            'taken_at'      => $entry['date_time'],
                        ];
                        $hasAutoTaken = true;
                    }
                } elseif ($entry['type'] === 'reissued') {
                    $totals[$meal . '_retaken']++;
                    $report[] = [
                        'user_id'       => $reg['user_id'],
                        'attendee_id'   => $reg['attendee_id'],
                        'attendee_name' => $attendeeName,
                        'category_name' => $reg['category_name'] ?? 'Unknown Category',
                        'meal'          => ucfirst($meal),
                        'status'        => 'Meal Re-Taken (Reissued)',
                        'taken_at'      => $entry['date_time'],
                    ];
                }
            }
        }
    }

    echo json_encode([
        'event_id'         => $event_id,
        'short_name'       => $short,
        'total_lunch'      => $totals['lunch_taken'] + $totals['lunch_retaken'] + $masterCount['lunch'],
        'total_dinner'     => $totals['dinner_taken'] + $totals['dinner_retaken'] + $masterCount['dinner'],
        'master_qr_lunch'  => $masterCount['lunch'],
        'master_qr_dinner' => $masterCount['dinner'],
        'lunch_retaken'    => $totals['lunch_retaken'],
        'dinner_retaken'   => $totals['dinner_retaken'],
        'lunch_taken'      => $totals['lunch_taken'],
        'dinner_taken'     => $totals['dinner_taken'],
        'report'           => $report,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
