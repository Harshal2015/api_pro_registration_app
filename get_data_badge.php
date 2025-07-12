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

    // Get event short name
    $stmt = $mainPdo->prepare("SELECT short_name FROM events WHERE id = :eid LIMIT 1");
    $stmt->execute([':eid' => $event_id]);
    $event = $stmt->fetch();
    if (!$event) throw new Exception('Event not found');
    $short = strtolower($event['short_name']);

    // Connect to event-specific DB
    $eventDb  = "prop_propass_event_$short";
    $eventDsn = "mysql:host=$mainHost;dbname=$eventDb;charset=$charset";
    $eventPdo = new PDO($eventDsn, $user, $pass, $options);

    // Fetch all scans (badge scans only, non-deleted)
    $scanStmt = $eventPdo->prepare("
        SELECT user_id, attendee_id, print_type, date, time
        FROM event_scan_logg
        WHERE event_id = :eid
          AND scan_for = 'badge'
          AND is_delete = 0
        ORDER BY date ASC, time ASC
    ");
    $scanStmt->execute([':eid' => $event_id]);
    $scans = $scanStmt->fetchAll();

    /*
    We'll store scan data separately for Auto and Manual:
    scanMap[user_id:attendee_id] = [
       'auto' => ['date_time' => ..., 'status' => 'Collected (Auto)'] or null,
       'manual' => array of ['date_time' => ..., 'status' => 'Reissued (Manual)']
    ]
    */
    $scanMap = [];

    foreach ($scans as $s) {
        $key = $s['user_id'] . ':' . $s['attendee_id'];
        $dt = $s['date'] . ' ' . $s['time'];
        $ptype = strtolower($s['print_type']);

        if (!isset($scanMap[$key])) {
            $scanMap[$key] = [
                'auto' => null,
                'manual' => [],
            ];
        }

        // Keep earliest auto scan only
        if ($ptype === 'auto') {
            if ($scanMap[$key]['auto'] === null || $dt < $scanMap[$key]['auto']['date_time']) {
                $scanMap[$key]['auto'] = [
                    'status' => 'Collected (Auto)',
                    'date_time' => $dt,
                ];
            }
        }

        // Keep all manual scans
        if ($ptype === 'manual') {
            $scanMap[$key]['manual'][] = [
                'status' => 'Reissued (Manual)',
                'date_time' => $dt,
            ];
        }
    }

    // Get all event registrations
    $regStmt = $eventPdo->prepare("
        SELECT er.user_id, er.id AS attendee_id, ec.name AS category_name
        FROM event_registrations er
        LEFT JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.event_id = :eid
          AND er.is_deleted = 0
    ");
    $regStmt->execute([':eid' => $event_id]);
    $registrations = $regStmt->fetchAll();

    // Fetch attendee details from main DB including prefix
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

    // Build final report
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
                // Always include prefix before short_name if prefix exists
                $attendeeName = trim("$prefix $shortName");
            } else {
                $attendeeName = $fullName;
            }

            if ($attendeeName === '') {
                $attendeeName = 'Unknown Attendee';
            }
        }

        $statusEntries = [];

        // Add earliest auto collected scan if exists
        if (isset($scanMap[$key]['auto']) && $scanMap[$key]['auto'] !== null) {
            $statusEntries[] = $scanMap[$key]['auto'];
        }

        // Add all manual reissued scans
        if (isset($scanMap[$key]['manual']) && count($scanMap[$key]['manual']) > 0) {
            foreach ($scanMap[$key]['manual'] as $manualEntry) {
                $statusEntries[] = $manualEntry;
            }
        }

        // If no scans, add Not Collected
        if (empty($statusEntries)) {
            $statusEntries[] = [
                'status' => 'Not Collected',
                'date_time' => null,
            ];
        }

        // Add each status entry as separate report row
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

    // Count totals
    $total = count($registrations);

    // Unique user+attendee pairs who collected anything (auto or manual)
    $collectedUserAttendee = [];
    // Count of all manual scans (reissued badges)
    $totalReissued = 0;

    foreach ($report as $row) {
        $key = $row['user_id'] . ':' . $row['attendee_id'];

        if ($row['status'] !== 'Not Collected') {
            $collectedUserAttendee[$key] = true;
        }

        if ($row['status'] === 'Reissued (Manual)') {
            $totalReissued++;
        }
    }

    $totalCollected = count($collectedUserAttendee);
    $totalNotCollected = $total - $totalCollected;

    // Output JSON
    header('Content-Type: application/json');
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
