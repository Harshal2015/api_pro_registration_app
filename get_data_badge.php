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
    // 1. Connect to main DB (for events and attendees)
    $mainPdo = new PDO($mainDsn, $user, $pass, $options);

    // 2. Get event_id from GET/POST
    $event_id = $_GET['event_id'] ?? $_POST['event_id'] ?? null;
    if (!$event_id) throw new Exception('Missing event_id');

    // 3. Fetch short_name for DSN (event table in main DB)
    $stmt = $mainPdo->prepare("SELECT short_name FROM events WHERE id = :eid LIMIT 1");
    $stmt->execute([':eid' => $event_id]);
    $event = $stmt->fetch();
    if (!$event) throw new Exception('Event not found');
    $short = strtolower($event['short_name']);

    // 4. Connect to event-specific DB (event_registrations, event_scan_logg, event_categories)
    $eventDb  = "prop_propass_event_$short";
    $eventDsn = "mysql:host=$mainHost;dbname=$eventDb;charset=$charset";
    $eventPdo = new PDO($eventDsn, $user, $pass, $options);

    // 5. Fetch scan logs with badge scans (grouped by user & attendee)
    $scanStmt = $eventPdo->prepare("
        SELECT user_id, attendee_id, MIN(print_type) AS print_type,
               MIN(date) AS first_date, MIN(time) AS first_time
        FROM event_scan_logg
        WHERE event_id = :eid
          AND scan_for = 'badge'
          AND is_delete = 0
        GROUP BY user_id, attendee_id
    ");
    $scanStmt->execute([':eid' => $event_id]);
    $scans = $scanStmt->fetchAll();

    // Index scan logs by user_id and attendee_id
    $scanMap = [];
    foreach ($scans as $s) {
        $key = $s['user_id'] . ':' . $s['attendee_id'];
        $status = ($s['print_type'] === 'Auto') ? 'Collected (Auto)' : 'Reissued (Manual)';
        $scanMap[$key] = [
            'status'      => $status,
            'collected_at'=> $s['first_date'] . ' ' . $s['first_time'],
        ];
    }

    // 6. Fetch registrations and join with event_categories to get category_name
    $regStmt = $eventPdo->prepare("
        SELECT er.user_id, er.id AS attendee_id, ec.name AS category_name
        FROM event_registrations er
        LEFT JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.event_id = :eid
          AND er.is_deleted = 0
    ");
    $regStmt->execute([':eid' => $event_id]);
    $registrations = $regStmt->fetchAll();

    // 7. Fetch attendee details from main DB in one go
    $userIds = array_unique(array_column($registrations, 'user_id'));
    $attendees = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $attStmt = $mainPdo->prepare("
            SELECT id, short_name, first_name, last_name
            FROM attendees_1
            WHERE id IN ($placeholders)
        ");
        $attStmt->execute($userIds);
        foreach ($attStmt->fetchAll() as $att) {
            $attendees[$att['id']] = $att;
        }
    }

    // 8. Build final report
    $report = [];
    foreach ($registrations as $reg) {
        $key = $reg['user_id'] . ':' . $reg['attendee_id'];

        $status = isset($scanMap[$key]) ? $scanMap[$key]['status'] : 'Not Collected';
        $collectedAt = $scanMap[$key]['collected_at'] ?? null;

        $attendeeName = 'Unknown Attendee';
        if (isset($attendees[$reg['user_id']])) {
            $att = $attendees[$reg['user_id']];
            if (!empty($att['short_name'])) {
                $attendeeName = $att['short_name'];
            } else {
                $attendeeName = trim(($att['first_name'] ?? '') . ' ' . ($att['last_name'] ?? ''));
                if ($attendeeName === '') {
                    $attendeeName = 'Unknown Attendee';
                }
            }
        }

        $report[] = [
            'user_id'       => $reg['user_id'],
            'attendee_id'   => $reg['attendee_id'],
            'attendee_name' => $attendeeName,
            'category_name' => $reg['category_name'] ?? 'Unknown Category',
            'status'        => $status,
            'collected_at'  => $collectedAt,
        ];
    }

    // 9. Output JSON
    header('Content-Type: application/json');
    echo json_encode([
        'event_id'   => $event_id,
        'short_name' => $short,
        'report'     => $report,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
