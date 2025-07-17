<?php
// Configuration
$mainHost = 'localhost';
$mainDb   = 'prop_propass';
$user     = 'root';
$pass     = '';
$charset  = 'utf8mb4';

$mainDsn = "mysql:host=$mainHost;dbname=$mainDb;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $mainPdo = new PDO($mainDsn, $user, $pass, $options);

    $event_id = $_GET['event_id'] ?? $_POST['event_id'] ?? null;
    if (!$event_id) throw new Exception('Missing event_id');

    // Fetch event short name
    $stmt = $mainPdo->prepare("SELECT short_name FROM events WHERE id = :eid LIMIT 1");
    $stmt->execute([':eid' => $event_id]);
    $event = $stmt->fetch();
    if (!$event) throw new Exception('Event not found');
    $short = strtolower($event['short_name']);

    // Connect to event-specific DB
    $eventDb  = "prop_propass_event_$short";
    $eventDsn = "mysql:host=$mainHost;dbname=$eventDb;charset=$charset";
    $eventPdo = new PDO($eventDsn, $user, $pass, $options);

    // Fetch kit scans (kit scan, not deleted)
    $scanStmt = $eventPdo->prepare("
        SELECT user_id, attendee_id, print_type, date, time
        FROM event_scan_logg
        WHERE event_id = :eid
          AND scan_for = 'kit'
          AND is_delete = 0
        ORDER BY date ASC, time ASC
    ");
    $scanStmt->execute([':eid' => $event_id]);
    $scans = $scanStmt->fetchAll();

    // Build scan map structure
    $scanMap = [];
    foreach ($scans as $s) {
        $key = "{$s['user_id']}:{$s['attendee_id']}";
        $dt = "{$s['date']} {$s['time']}";
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

    // Fetch all registrations
    $regStmt = $eventPdo->prepare("
        SELECT er.user_id, er.id AS attendee_id, ec.name AS category_name
        FROM event_registrations er
        LEFT JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.event_id = :eid AND er.is_deleted = 0
    ");
    $regStmt->execute([':eid' => $event_id]);
    $registrations = $regStmt->fetchAll();

    // Fetch attendee details
    $userIds = array_unique(array_column($registrations, 'user_id'));
    $attendees = [];
    if ($userIds) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $attStmt = $mainPdo->prepare("SELECT id, prefix, short_name, first_name, last_name FROM attendees_1 WHERE id IN ($ph)");
        $attStmt->execute($userIds);
        foreach ($attStmt->fetchAll() as $att) {
            $attendees[$att['id']] = $att;
        }
    }

    // Build report
    $report = [];
    foreach ($registrations as $reg) {
        $key = "{$reg['user_id']}:{$reg['attendee_id']}";

        // Determine attendee name
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
        }

        // Safely get scan entries
        $entrySet = $scanMap[$key] ?? ['issued' => null, 'reissued' => []];
        $entries = [];

        if (!empty($entrySet['issued'])) {
            $entries[] = $entrySet['issued'];
        }

        if (!empty($entrySet['reissued']) && is_array($entrySet['reissued'])) {
            foreach ($entrySet['reissued'] as $manualEntry) {
                $entries[] = $manualEntry;
            }
        }

        if (empty($entries)) {
            $entries[] = ['status' => 'Kit Not Collected', 'date_time' => null];
        }

        foreach ($entries as $e) {
            $report[] = [
                'user_id'       => $reg['user_id'],
                'attendee_id'   => $reg['attendee_id'],
                'attendee_name' => $attName,
                'category_name' => $reg['category_name'] ?? 'Unknown Category',
                'status'        => $e['status'],
                'collected_at'  => $e['date_time'],
            ];
        }
    }

    // Totals
    $total = count($registrations);
    $collectedKeys = [];
    $reissued = 0;
    foreach ($report as $r) {
        $k = "{$r['user_id']}:{$r['attendee_id']}";
        if ($r['status'] !== 'Kit Not Collected') $collectedKeys[$k] = true;
        if (stripos($r['status'], 'Reissued') !== false) $reissued++;
    }
    $colCount = count($collectedKeys);
    $notCount = $total - $colCount;

    header('Content-Type: application/json');
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
