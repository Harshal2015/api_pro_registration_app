<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');
require_once 'auth_api.php';
require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';

try {
    // Get event_id
    $input = $_GET['event_id'] ?? json_decode(file_get_contents('php://input'), true)['event_id'] ?? null;
    if (!$input || !is_numeric($input)) throw new Exception('Missing or invalid event_id');
    $event_id = (int)$input;

    // Fetch event info
    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) throw new Exception('Event not found');
    $short = strtolower($res->fetch_assoc()['short_name']);
    $stmt->close();

    // Connect to event DB
    $ev = connectEventDb($event_id);
    if (!$ev['success']) throw new Exception($ev['message']);
    $eventConn = $ev['conn'];

    // Data holders
    $scanMap = [];
    $masterQrDaily = [];

    // Fetch scan logs
    $scanStmt = $eventConn->prepare("
        SELECT user_id, registration_id, print_type, scan_for, date, time
        FROM event_scan_logs_food
        WHERE event_id = ? AND scan_for IN ('lunch','dinner') AND is_deleted = 0
        ORDER BY date, time
    ");
    $scanStmt->bind_param("i", $event_id);
    $scanStmt->execute();
    $sres = $scanStmt->get_result();

    while ($row = $sres->fetch_assoc()) {
        $meal = strtolower($row['scan_for']);
        $ptype = strtolower(trim($row['print_type']));
        $date = $row['date'];
        $dt = $row['time'];
        $key = "{$row['user_id']}:{$row['registration_id']}:$meal";

        if (!isset($masterQrDaily[$date])) {
            $masterQrDaily[$date] = ['lunch' => 0, 'dinner' => 0];
        }

        if ($ptype === 'master qr') {
            $masterQrDaily[$date][$meal]++;
        } else {
            if (!isset($scanMap[$key])) {
                $scanMap[$key] = ['issued' => null, 'reissued' => []];
            }
            $when = "$date $dt";
            if ($ptype === 'issued') {
                if (!$scanMap[$key]['issued'] || $when < $scanMap[$key]['issued']['date_time']) {
                    $scanMap[$key]['issued'] = ['status' => 'Meal Taken', 'date_time' => $when, 'meal' => ucfirst($meal)];
                }
            } elseif ($ptype === 'reissued') {
                $scanMap[$key]['reissued'][] = ['status' => 'Meal Re-Taken', 'date_time' => $when, 'meal' => ucfirst($meal)];
            }
        }
    }
    $scanStmt->close();

    // Fetch registrations (attendees)
    $attRegs = [];
    $userIds = [];
    $regStmt = $eventConn->prepare("
        SELECT er.user_id, er.id AS reg_id, ec.name AS category_name
        FROM event_registrations er
        LEFT JOIN event_categories ec ON er.category_id = ec.id
        WHERE er.event_id = ? AND er.is_deleted = 0
    ");
    $regStmt->bind_param("i", $event_id);
    $regStmt->execute();
    $rres = $regStmt->get_result();
    while ($r = $rres->fetch_assoc()) {
        $attRegs[] = ['user_id' => $r['user_id'], 'reg_id' => $r['reg_id'], 'category' => $r['category_name']];
        $userIds[] = $r['user_id'];
    }
    $regStmt->close();

    // Fetch industries registrations
    $indRegs = [];
    $indStmt = $eventConn->prepare("
        SELECT id AS reg_id, name AS category_name
        FROM event_industries
        WHERE event_id = ? AND is_deleted = 0
    ");
    $indStmt->bind_param("i", $event_id);
    $indStmt->execute();
    $ii = $indStmt->get_result();
    while ($i = $ii->fetch_assoc()) {
        // Industries do not have user_id, set 0
        $indRegs[] = ['user_id' => 0, 'reg_id' => $i['reg_id'], 'category' => $i['category_name']];
    }
    $indStmt->close();

    // Fetch attendee names
    $attendees = [];
    if (!empty($userIds)) {
        // Prepare placeholders for IN query
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $attStmt = $conn->prepare("SELECT id, prefix, short_name, first_name, last_name FROM " . TABLE_ATTENDEES . " WHERE id IN ($ph)");
        $bind = [&$types];
        foreach ($userIds as $k => $uid) $bind[] = &$userIds[$k];
        call_user_func_array([$attStmt, 'bind_param'], $bind);
        $attStmt->execute();
        $ares = $attStmt->get_result();
        while ($a = $ares->fetch_assoc()) {
            $attendees[$a['id']] = $a;
        }
        $attStmt->close();
    }

    // Separate meal wise stats for attendees and industries
    $mealWiseStatsAttendees = ['lunch' => [], 'dinner' => []];
    $mealWiseStatsIndustries = ['lunch' => [], 'dinner' => []];

    // Function to update meal wise stats
    function updateMealWiseStats(&$stats, $meal, $date, $status) {
        if (!isset($stats[$meal][$date])) {
            $stats[$meal][$date] = ['taken' => 0, 'retaken' => 0, 'not_taken' => 0, 'master_qr' => 0, 'total' => 0];
        }
        if ($status === 'Meal Taken') {
            $stats[$meal][$date]['taken']++;
        } elseif ($status === 'Meal Re-Taken') {
            $stats[$meal][$date]['retaken']++;
        } elseif ($status === 'Not Taken') {
            $stats[$meal][$date]['not_taken']++;
        }
    }

    // Function to build report entries for either attendees or industries
    function buildEntries($reg, $attendees, $scanMap, &$report, &$mealWiseStats, $isIndustry) {
        foreach (['lunch', 'dinner'] as $meal) {
            $key = "{$reg['user_id']}:{$reg['reg_id']}:$meal";
            $entries = [];

            if (!empty($scanMap[$key]['issued'])) {
                $entries[] = $scanMap[$key]['issued'];
            }
            foreach ($scanMap[$key]['reissued'] ?? [] as $r) {
                $entries[] = $r;
            }

            if (empty($entries)) {
                $entries[] = ['status' => 'Not Taken', 'meal' => ucfirst($meal), 'date_time' => null];
                // Use today's date for 'not taken' when no scan
                $date = date('Y-m-d');
                updateMealWiseStats($mealWiseStats, $meal, $date, 'Not Taken');
            } else {
                foreach ($entries as $e) {
                    $date = substr($e['date_time'] ?? date('Y-m-d'), 0, 10);
                    updateMealWiseStats($mealWiseStats, $meal, $date, $e['status']);
                }
            }

            $name = 'Industry Participant';
            if (!$isIndustry && isset($attendees[$reg['user_id']])) {
                $a = $attendees[$reg['user_id']];
                $nm = trim(($a['short_name'] ?? '') ?: trim($a['prefix'] . ' ' . $a['first_name'] . ' ' . $a['last_name']));
                $name = $nm ?: 'Unknown Attendee';
            }

            foreach ($entries as $e) {
                $report[] = [
                    'user_id' => $reg['user_id'],
                    'registration_id' => $reg['reg_id'],
                    'attendee_name' => $isIndustry ? '' : $name,
                    'industry' => $isIndustry ? $name : '',
                    'category_name' => $reg['category'] ?? '',
                    'meal' => $e['meal'],
                    'status' => $e['status'],
                    'taken_at' => $e['date_time']
                ];
            }
        }
    }

    // Build reports
    $attReport = [];
    $indReport = [];

    foreach ($attRegs as $r) {
        buildEntries($r, $attendees, $scanMap, $attReport, $mealWiseStatsAttendees, false);
    }
    foreach ($indRegs as $r) {
        buildEntries($r, $attendees, $scanMap, $indReport, $mealWiseStatsIndustries, true);
    }

    // Now update the master_qr and total counts in meal wise stats for both attendee and industry stats
    foreach (['lunch', 'dinner'] as $meal) {
        foreach ($masterQrDaily as $date => $counts) {
            $mqrCount = $counts[$meal] ?? 0;

            // Attendee stats
            if (!isset($mealWiseStatsAttendees[$meal][$date])) {
                $mealWiseStatsAttendees[$meal][$date] = ['taken' => 0, 'retaken' => 0, 'not_taken' => 0, 'master_qr' => 0, 'total' => 0];
            }
            $mealWiseStatsAttendees[$meal][$date]['master_qr'] = $mqrCount;
            $mealWiseStatsAttendees[$meal][$date]['total'] = 
                $mealWiseStatsAttendees[$meal][$date]['taken'] + 
                $mealWiseStatsAttendees[$meal][$date]['retaken'] + 
                $mealWiseStatsAttendees[$meal][$date]['master_qr'];

            // Industry stats
            if (!isset($mealWiseStatsIndustries[$meal][$date])) {
                $mealWiseStatsIndustries[$meal][$date] = ['taken' => 0, 'retaken' => 0, 'not_taken' => 0, 'master_qr' => 0, 'total' => 0];
            }
            $mealWiseStatsIndustries[$meal][$date]['master_qr'] = $mqrCount;
            $mealWiseStatsIndustries[$meal][$date]['total'] = 
                $mealWiseStatsIndustries[$meal][$date]['taken'] + 
                $mealWiseStatsIndustries[$meal][$date]['retaken'] + 
                $mealWiseStatsIndustries[$meal][$date]['master_qr'];
        }
    }

    // Output the JSON without stats and combined totals, with split meal-wise stats including totals
    echo json_encode([
        'event_id' => $event_id,
        'short_name' => $short,
        'master_qr_daily' => $masterQrDaily,
        'attendee_meal_wise_stats' => $mealWiseStatsAttendees,
        'industry_meal_wise_stats' => $mealWiseStatsIndustries,
        'attendee_report' => $attReport,
        'industry_report' => $indReport
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
