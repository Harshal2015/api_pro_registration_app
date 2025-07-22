<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';               // provides $conn connected to main DB
require_once 'connect_event_database.php'; // provides connectEventDb($event_id)
require_once 'tables.php'; 

try {
    $event_id    = $_POST['event_id'] ?? null;
    $user_id     = $_POST['user_id'] ?? null;
    $category_id = $_POST['category_id'] ?? null;

    // Attendee (main DB) update data
    $prefix            = $_POST['prefix'] ?? null;
    $first_name        = $_POST['first_name'] ?? '';
    $last_name         = $_POST['last_name'] ?? '';
    $phone             = $_POST['primary_phone_number'] ?? '';
    $email             = $_POST['primary_email_address'] ?? '';
    $city              = $_POST['city'] ?? '';
    $state             = $_POST['state'] ?? '';
    $country           = $_POST['country'] ?? '';
    $mci_number        = $_POST['professional_registration_number'] ?? '';
    $registration_type = $_POST['registration_type'] ?? '';
    $profession        = $_POST['profession'] ?? '';
    $added_by          = $_POST['added_by'] ?? '';

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
        $prefix            = isset($_POST['prefix']) ? $_POST['prefix'] : $attendee['prefix'];
        $first_name        = isset($_POST['first_name']) ? $_POST['first_name'] : $attendee['first_name'];
        $last_name         = isset($_POST['last_name']) ? $_POST['last_name'] : $attendee['last_name'];
        $phone             = isset($_POST['primary_phone_number']) ? $_POST['primary_phone_number'] : $attendee['primary_phone_number'];
        $email             = isset($_POST['primary_email_address']) ? $_POST['primary_email_address'] : $attendee['primary_email_address'];
        $city              = isset($_POST['city']) ? $_POST['city'] : $attendee['city'];
        $state             = isset($_POST['state']) ? $_POST['state'] : $attendee['state'];
        $country           = isset($_POST['country']) ? $_POST['country'] : $attendee['country'];
        $mci_number        = isset($_POST['professional_registration_number']) ? $_POST['professional_registration_number'] : $attendee['professional_registration_number'];
        $registration_type = isset($_POST['registration_type']) ? $_POST['registration_type'] : $attendee['registration_type'];
        $profession        = isset($_POST['profession']) ? $_POST['profession'] : $attendee['profession'];
        $added_by          = isset($_POST['added_by']) ? $_POST['added_by'] : $attendee['added_by'];

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
