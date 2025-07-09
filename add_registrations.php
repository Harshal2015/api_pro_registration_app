<?php
header('Content-Type: application/json');

try {
    // Connect to main database
    $attendeeDb = new PDO('mysql:host=localhost;dbname=prop_propass;charset=utf8mb4', 'root', '');
    $attendeeDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['user']) || !isset($input['event_id']) || !isset($input['category_id'])) {
        throw new Exception('Invalid input data');
    }

    $user = $input['user'];
    $eventId = (int)$input['event_id'];
    $categoryId = (int)$input['category_id'];
    $regDetails = $input['registration_details'] ?? [];

    // Step 1: Get event short_name for dynamic DB name
    $stmt = $attendeeDb->prepare("SELECT short_name FROM events WHERE id = :event_id LIMIT 1");
    $stmt->execute([':event_id' => $eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Event not found');
    }
    $eventShortName = $row['short_name'];
    $eventDbName = 'prop_propass_event_' . $eventShortName;

    // Step 2: Connect to event-specific database
    $eventDb = new PDO("mysql:host=localhost;dbname={$eventDbName};charset=utf8mb4", 'root', '');
    $eventDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Step 3: Check if user exists in attendees_1 (main DB)
    $stmtUser = $attendeeDb->prepare("
        SELECT id, is_deleted FROM attendees_1 
        WHERE (first_name = :first_name AND last_name = :last_name)
           OR primary_email_address = :email
           OR primary_phone_number = :phone
        LIMIT 1
    ");
    $stmtUser->execute([
        ':first_name' => $user['first_name'] ?? '',
        ':last_name' => $user['last_name'] ?? '',
        ':email' => $user['primary_email_address'] ?? '',
        ':phone' => $user['primary_phone_number'] ?? '',
    ]);
    $attendee = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // Step 4: Check if user is already registered for the event by matching name or email in event registrations
    // Join event_registrations with attendees_1 on user_id and check matching first_name, last_name, or email
    $stmtExistingReg = $eventDb->prepare("
        SELECT er.id, er.is_deleted, a.id AS attendee_id
        FROM event_registrations er
        LEFT JOIN prop_propass.attendees_1 a ON er.user_id = a.id
        WHERE er.event_id = :event_id
          AND (
              (a.primary_email_address = :email AND :email != '') OR
              (a.primary_phone_number = :phone AND :phone != '') OR
              (a.first_name = :first_name AND a.last_name = :last_name)
          )
        LIMIT 1
    ");

    $stmtExistingReg->execute([
        ':event_id' => $eventId,
        ':email' => $user['primary_email_address'] ?? '',
        ':phone' => $user['primary_phone_number'] ?? '',
        ':first_name' => $user['first_name'] ?? '',
        ':last_name' => $user['last_name'] ?? '',
    ]);

    $existingReg = $stmtExistingReg->fetch(PDO::FETCH_ASSOC);

    if ($existingReg) {
        // If registration exists
        if ($existingReg['is_deleted'] == 1) {
            // Reactivate registration if deleted
            $updateReg = $eventDb->prepare("UPDATE event_registrations SET is_deleted = 0, modified_at = NOW() WHERE id = :id");
            $updateReg->execute([':id' => $existingReg['id']]);
            echo json_encode(['success' => true, 'message' => 'User registration reactivated successfully']);
            exit;
        } else {
            // Active registration exists
            echo json_encode(['success' => false, 'message' => 'User already registered for this event']);
            exit;
        }
    }

    // Step 5: Insert user into attendees_1 if not exists
    if (!$attendee) {
        $insertUser = $attendeeDb->prepare("
            INSERT INTO attendees_1 (
                prefix, first_name, last_name, short_name, primary_email_address, primary_email_address_verified,
                secondary_email, country_code, primary_phone_number, primary_phone_number_verified,
                secondary_mobile_number, city, state, country, pincode, profession, workplace_name,
                designation, professional_registration_number, registration_state, registration_type,
                added_by, area_of_interest, is_verified, profile_photo, birth_date, bio, is_deleted,
                created_at, modified_at
            ) VALUES (
                :prefix, :first_name, :last_name, :short_name, :primary_email_address, :primary_email_address_verified,
                :secondary_email, :country_code, :primary_phone_number, :primary_phone_number_verified,
                :secondary_mobile_number, :city, :state, :country, :pincode, :profession, :workplace_name,
                :designation, :professional_registration_number, :registration_state, :registration_type,
                :added_by, :area_of_interest, :is_verified, :profile_photo, :birth_date, :bio, 0,
                NOW(), NOW()
            )
        ");

        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        $insertUser->execute([
            ':prefix' => $user['prefix'] ?? null,
            ':first_name' => $user['first_name'] ?? null,
            ':last_name' => $user['last_name'] ?? null,
            ':short_name' => $fullName,
            ':primary_email_address' => $user['primary_email_address'] ?? null,
            ':primary_email_address_verified' => $user['primary_email_address_verified'] ?? 0,
            ':secondary_email' => $user['secondary_email'] ?? null,
            ':country_code' => $user['country_code'] ?? null,
            ':primary_phone_number' => $user['primary_phone_number'] ?? null,
            ':primary_phone_number_verified' => $user['primary_phone_number_verified'] ?? 0,
            ':secondary_mobile_number' => $user['secondary_mobile_number'] ?? null,
            ':city' => $user['city'] ?? null,
            ':state' => $user['state'] ?? null,
            ':country' => $user['country'] ?? null,
            ':pincode' => $user['pincode'] ?? null,
            ':profession' => $user['profession'] ?? null,
            ':workplace_name' => $user['workplace_name'] ?? null,
            ':designation' => $user['designation'] ?? null,
            ':professional_registration_number' => $user['professional_registration_number'] ?? null,
            ':registration_state' => $user['registration_state'] ?? null,
            ':registration_type' => $user['registration_type'] ?? null,
            ':added_by' => $user['added_by'] ?? null,
            ':area_of_interest' => $user['area_of_interest'] ?? null,
            ':is_verified' => $user['is_verified'] ?? 0,
            ':profile_photo' => $user['profile_photo'] ?? null,
            ':birth_date' => $user['birth_date'] ?? null,
            ':bio' => $user['bio'] ?? null,
        ]);

        $userId = $attendeeDb->lastInsertId();
    } else {
        $userId = $attendee['id'];

        // Reactivate attendee if deleted
        if ($attendee['is_deleted'] == 1) {
            $updateAttendee = $attendeeDb->prepare("UPDATE attendees_1 SET is_deleted = 0, modified_at = NOW() WHERE id = :id");
            $updateAttendee->execute([':id' => $userId]);
        }
    }

    // Step 6: Insert registration record into event_registrations table
    $insertReg = $eventDb->prepare("
        INSERT INTO event_registrations (
            event_id, user_id, category_id, all_day_registration, travel, accommodation, taxi, kit,
            certificate, lunch, dinner, amount, transaction_id, order_no,
            order_id, payment_id, payment_mode, status, added_by, is_deleted, created_at, modified_at
        ) VALUES (
            :event_id, :user_id, :category_id, :all_day_registration, :travel, :accommodation, :taxi, :kit,
            :certificate, :lunch, :dinner, :amount, :transaction_id, :order_no,
            :order_id, :payment_id, :payment_mode, :status, :added_by, 0, NOW(), NOW()
        )
    ");

    $insertReg->execute([
        ':event_id' => $eventId,
        ':user_id' => $userId,
        ':category_id' => $categoryId,
        ':all_day_registration' => $regDetails['all_day_registration'] ?? 0,
        ':travel' => $regDetails['travel'] ?? 0,
        ':accommodation' => $regDetails['accommodation'] ?? 0,
        ':taxi' => $regDetails['taxi'] ?? 0,
        ':kit' => $regDetails['kit'] ?? 0,
        ':certificate' => $regDetails['certificate'] ?? 0,
        ':lunch' => $regDetails['lunch'] ?? 0,
        ':dinner' => $regDetails['dinner'] ?? 0,
        ':amount' => $regDetails['amount'] ?? 0,
        ':transaction_id' => $regDetails['transaction_id'] ?? null,
        ':order_no' => $regDetails['order_no'] ?? null,
        ':order_id' => $regDetails['order_id'] ?? null,
        ':payment_id' => $regDetails['payment_id'] ?? null,
        ':payment_mode' => $regDetails['payment_mode'] ?? null,
        ':status' => $regDetails['status'] ?? 0,
        ':added_by' => $regDetails['added_by'] ?? null,
    ]);

    echo json_encode(['success' => true, 'message' => 'User registered successfully']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
