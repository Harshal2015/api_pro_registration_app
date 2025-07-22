<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

// Include the auth API — it validates and either ends execution or allows continuation
require_once 'auth_api.php';

// Now your usual includes for DB connection and constants
require_once 'config.php';               // provides $conn connected to main DB
require_once 'connect_event_database.php'; // provides connectEventDb($event_id)
require_once 'tables.php';

try {
    // Since auth_api.php reads JSON from php://input, 
    // you should do the same here:
    $input = json_decode(file_get_contents("php://input"), true);

    $event_id    = $input['event_id'] ?? null;
    $user_id     = $input['user_id'] ?? null;
    $category_id = $input['category_id'] ?? null;

    // Attendee (main DB) update data
    $prefix            = $input['prefix'] ?? null;
    $first_name        = $input['first_name'] ?? '';
    $last_name         = $input['last_name'] ?? '';
    $phone             = $input['primary_phone_number'] ?? '';
    $email             = $input['primary_email_address'] ?? '';
    $city              = $input['city'] ?? '';
    $state             = $input['state'] ?? '';
    $country           = $input['country'] ?? '';
    $mci_number        = $input['professional_registration_number'] ?? '';
    $registration_type = $input['registration_type'] ?? '';
    $profession        = $input['profession'] ?? '';
    $added_by          = $input['added_by'] ?? '';

    if (!$event_id || !$user_id) {
        throw new Exception("Missing required fields: event_id or user_id");
    }

    // Step 1: Connect to event-specific database
    $connectionResult = connectEventDb($event_id);
    if (!$connectionResult['success']) {
        throw new Exception($connectionResult['message']);
    }
    $eventConn = $connectionResult['conn'];

    // Step 2: Use existing connection from config.php to main DB
    $mainConn = $conn;

    // Step 3: Check for changes in the event_registration table (event DB)
    $checkReg = $eventConn->prepare("SELECT category_id FROM event_registrations WHERE event_id = ? AND user_id = ?");
    $checkReg->bind_param("ii", $event_id, $user_id);
    $checkReg->execute();
    $resultReg = $checkReg->get_result();
    $regData = $resultReg->fetch_assoc();
    $checkReg->close();

    if ($regData && $regData['category_id'] != $category_id) {
        $updateReg = $eventConn->prepare("
            UPDATE event_registrations
            SET category_id = ?, modified_at = NOW()
            WHERE event_id = ? AND user_id = ?
        ");
        $updateReg->bind_param("iii", $category_id, $event_id, $user_id);
        $updateReg->execute();
        $updateReg->close();
    }

    // Step 4: Check and update attendees_1 table in main DB
    $checkAttendee = $mainConn->prepare("SELECT * FROM " . TABLE_ATTENDEES . " WHERE id = ?");
    $checkAttendee->bind_param("i", $user_id);
    $checkAttendee->execute();
    $attendeeResult = $checkAttendee->get_result();
    $attendee = $attendeeResult->fetch_assoc();
    $checkAttendee->close();

    if ($attendee) {
        // Use new values if provided, else keep old ones
        $prefix            = $prefix ?? $attendee['prefix'];
        $first_name        = $first_name ?: $attendee['first_name'];
        $last_name         = $last_name ?: $attendee['last_name'];
        $phone             = $phone ?: $attendee['primary_phone_number'];
        $email             = $email ?: $attendee['primary_email_address'];
        $city              = $city ?: $attendee['city'];
        $state             = $state ?: $attendee['state'];
        $country           = $country ?: $attendee['country'];
        $mci_number        = $mci_number ?: $attendee['professional_registration_number'];
        $registration_type = $registration_type ?: $attendee['registration_type'];
        $profession        = $profession ?: $attendee['profession'];
        $added_by          = $added_by ?: $attendee['added_by'];

        // Compose short_name (full name)
        $newShortname = trim($first_name . ' ' . $last_name);

        // Now check if anything changed
        $needsUpdate = (
            $attendee['prefix'] !== $prefix ||
            $attendee['first_name'] !== $first_name ||
            $attendee['last_name'] !== $last_name ||
            $attendee['primary_phone_number'] !== $phone ||
            $attendee['primary_email_address'] !== $email ||
            $attendee['city'] !== $city ||
            $attendee['state'] !== $state ||
            $attendee['country'] !== $country ||
            $attendee['professional_registration_number'] !== $mci_number ||
            $attendee['registration_type'] !== $registration_type ||
            $attendee['profession'] !== $profession ||
            $attendee['short_name'] !== $newShortname
        );

        if ($needsUpdate) {
            $updateAttendee = $mainConn->prepare("
                UPDATE " . TABLE_ATTENDEES . "
                SET prefix = ?, first_name = ?, last_name = ?, primary_phone_number = ?, 
                    primary_email_address = ?, city = ?, state = ?, country = ?, 
                    professional_registration_number = ?, registration_type = ?, 
                    profession = ?, added_by = ?, short_name = ?, modified_at = NOW()
                WHERE id = ?
            ");

            $updateAttendee->bind_param(
                "sssssssssssssi",
                $prefix,
                $first_name,
                $last_name,
                $phone,
                $email,
                $city,
                $state,
                $country,
                $mci_number,
                $registration_type,
                $profession,
                $added_by,
                $newShortname,
                $user_id
            );

            $updateAttendee->execute();
            $updateAttendee->close();
        }
    }

    // ✅ SUCCESS response
    echo json_encode([
        'success' => true,
        'message' => 'Updated Successfully.'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
