<?php
$mainHost = 'localhost';
$mainDb   = 'prop_propass';
$user     = 'root'; 
$pass     = '';    
$charset  = 'utf8mb4';

$mainDsn = "mysql:host=$mainHost;dbname=$mainDb;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $mainPdo = new PDO($mainDsn, $user, $pass, $options);

    $event_id = $_GET['event_id'] ?? $_POST['event_id'] ?? null;
    if (!$event_id) {
        throw new Exception('Missing event_id');
    }

    $stmt = $mainPdo->prepare("SELECT short_name FROM events WHERE id = :eid LIMIT 1");
    $stmt->execute([':eid' => $event_id]);
    $event = $stmt->fetch();
    if (!$event) {
        throw new Exception('Event not found');
    }
    $short = strtolower($event['short_name']);

    $eventDb  = "prop_propass_event_$short";
    $eventDsn = "mysql:host=$mainHost;dbname=$eventDb;charset=$charset";
    $eventPdo = new PDO($eventDsn, $user, $pass, $options);

    $scanStmt = $eventPdo->prepare("
        SELECT user_id, attendee_id, print_type, scan_for, date, time
        FROM event_scan_logs_food
        WHERE event_id = :eid
          AND scan_for IN ('lunch', 'dinner') -- Only 'lunch' and 'dinner'
          AND is_delete = 0
        ORDER BY date ASC, time ASC
    ");
    $scanStmt->execute([':eid' => $event_id]);
    $scans = $scanStmt->fetchAll();

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
            'type'      => $ptype,
            'date_time' => $dt,
        ];
    }

    $regStmt = $eventPdo->prepare("
        SELECT er.user_id, er.id AS attendee_id, ec.name AS category_name
        FROM event_registrations er
        LEFT JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.event_id = :eid AND er.is_deleted = 0
    ");
    $regStmt->execute([':eid' => $event_id]);
    $registrations = $regStmt->fetchAll();

    $userIds = array_unique(array_column($registrations, 'user_id'));
    $attendees = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $attStmt = $mainPdo->prepare("
            SELECT id, prefix, short_name, first_name, last_name
            FROM attendees_1
            WHERE id IN ($placeholders)
        ");
        $attStmt->execute($userIds);
        foreach ($attStmt->fetchAll() as $att) {
            $attendees[$att['id']] = $att;
        }
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
            $attendeeName = $shortName !== '' ? trim("$prefix $shortName") : $fullName;
        }

        foreach (['lunch', 'dinner'] as $meal) { 
            $entries = $scanMap[$key][$meal] ?? [];

            if (empty($entries)) {
                $report[] = [
                    'user_id'     => $reg['user_id'],
                    'attendee_id' => $reg['attendee_id'],
                    'attendee_name' => $attendeeName,
                    'category_name' => $reg['category_name'] ?? 'Unknown Category',
                    'meal'        => ucfirst($meal),
                    'status'      => 'Not Taken',
                    'taken_at'    => null,
                ];
                continue;
            }

            $hasAutoTaken = false;
            foreach ($entries as $entry) {
                if ($entry['type'] === 'issued') {
                    if (!$hasAutoTaken) {
                        $totals[$meal . '_taken']++;
                        $report[] = [
                            'user_id'     => $reg['user_id'],
                            'attendee_id' => $reg['attendee_id'],
                            'attendee_name' => $attendeeName,
                            'category_name' => $reg['category_name'] ?? 'Unknown Category',
                            'meal'        => ucfirst($meal),
                            'status'      => 'Meal Taken (Issued)',
                            'taken_at'    => $entry['date_time'],
                        ];
                        $hasAutoTaken = true;
                    }
                } elseif ($entry['type'] === 'reissued') {
                    $totals[$meal . '_retaken']++;
                    $report[] = [
                        'user_id'     => $reg['user_id'],
                        'attendee_id' => $reg['attendee_id'],
                        'attendee_name' => $attendeeName,
                        'category_name' => $reg['category_name'] ?? 'Unknown Category',
                        'meal'        => ucfirst($meal),
                        'status'      => 'Meal Re-Taken (Reissued)',
                        'taken_at'    => $entry['date_time'],
                    ];
                }
            }
        }
    }

    header('Content-Type: application/json');
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
?>