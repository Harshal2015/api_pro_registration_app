<?php
header('Content-Type: application/json');

// ✅ STEP 1: Read input ONCE
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// ✅ STEP 2: Make input globally reusable for auth_api.php
$GLOBALS['input_data'] = $input;

// ✅ STEP 3: Include dependencies
require_once 'auth_api.php';
require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    if (!$input || !isset($input['user'], $input['event_id'], $input['category_id'])) {
        throw new Exception('Invalid input data');
    }

    $user = $input['user'];
    $eventId = (int)$input['event_id'];
    $categoryId = (int)$input['category_id'];
    $regDetails = $input['registration_details'] ?? [];

    $connectionResult = connectEventDb($eventId);
    if (!$connectionResult['success']) {
        throw new Exception($connectionResult['message']);
    }

    /** @var mysqli $eventDb */
    $eventDb = $connectionResult['conn'];

    $stmt = $eventDb->prepare("SELECT * FROM " . TABLE_EVENT_CATEGORIES . " WHERE id = ? AND event_id = ? LIMIT 1");
    $stmt->bind_param("ii", $categoryId, $eventId);
    $stmt->execute();
    $category = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$category) {
        throw new Exception('Category not found for this event');
    }

    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    $email = $user['primary_email_address'] ?? '';
    $phone = $user['primary_phone_number'] ?? '';

    $stmt = $conn->prepare("
        SELECT id, is_deleted FROM " . TABLE_ATTENDEES . "
        WHERE (first_name = ? AND last_name = ?)
           OR primary_email_address = ?
           OR primary_phone_number = ?
        LIMIT 1
    ");
    $stmt->bind_param("ssss", $firstName, $lastName, $email, $phone);
    $stmt->execute();
    $attendee = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $eventDb->prepare("
        SELECT er.id, er.is_deleted, a.id AS attendee_id
        FROM " . TABLE_EVENT_REGISTRATIONS . " er
        LEFT JOIN prop_propass." . TABLE_ATTENDEES . " a ON er.user_id = a.id
        WHERE er.event_id = ?
          AND (
              (a.primary_email_address = ? AND ? != '') OR
              (a.primary_phone_number = ? AND ? != '') OR
              (a.first_name = ? AND a.last_name = ?)
          )
        LIMIT 1
    ");
    $stmt->bind_param("issssss", $eventId, $email, $email, $phone, $phone, $firstName, $lastName);
    $stmt->execute();
    $existingReg = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existingReg) {
        if ($existingReg['is_deleted'] == 1) {
            $stmt = $eventDb->prepare("UPDATE " . TABLE_EVENT_REGISTRATIONS . " SET is_deleted = 0, modified_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $existingReg['id']);
            $stmt->execute();
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'User registration reactivated successfully']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'User already registered for this event']);
            exit;
        }
    }

    if (!$attendee) {
        $uniqueId = uniqid('att_', true);
        $shortName = trim($firstName . ' ' . $lastName);

        $prefix = $user['prefix'] ?? null;
        $primary_email_address_verified = $user['primary_email_address_verified'] ?? 0;
        $secondary_email = $user['secondary_email'] ?? null;
        $country_code = $user['country_code'] ?? null;
        $primary_phone_number_verified = $user['primary_phone_number_verified'] ?? 0;
        $secondary_mobile_number = $user['secondary_mobile_number'] ?? null;
        $city = $user['city'] ?? null;
        $state = $user['state'] ?? null;
        $country = $user['country'] ?? null;
        $pincode = $user['pincode'] ?? null;
        $profession = $user['profession'] ?? null;
        $workplace_name = $user['workplace_name'] ?? null;
        $designation = $user['designation'] ?? null;
        $professional_registration_number = $user['professional_registration_number'] ?? null;
        $registration_state = $user['registration_state'] ?? null;
        $registration_type = $user['registration_type'] ?? null;
        $added_by = $user['added_by'] ?? null;
        $area_of_interest = $user['area_of_interest'] ?? null;
        $is_verified = $user['is_verified'] ?? 0;
        $profile_photo = $user['profile_photo'] ?? null;
        $birth_date = $user['birth_date'] ?? null;
        $bio = $user['bio'] ?? null;

        $stmt = $conn->prepare("
            INSERT INTO " . TABLE_ATTENDEES . " (
                unique_id, prefix, first_name, last_name, short_name, primary_email_address, primary_email_address_verified,
                secondary_email, country_code, primary_phone_number, primary_phone_number_verified,
                secondary_mobile_number, city, state, country, pincode, profession, workplace_name,
                designation, professional_registration_number, registration_state, registration_type,
                added_by, area_of_interest, is_verified, profile_photo, birth_date, bio, is_deleted,
                created_at, modified_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, 0,
                NOW(), NOW()
            )
        ");
        $stmt->bind_param(
            "ssssssisssisssssssssssssssss",
            $uniqueId, $prefix, $firstName, $lastName, $shortName, $email, $primary_email_address_verified,
            $secondary_email, $country_code, $phone, $primary_phone_number_verified,
            $secondary_mobile_number, $city, $state, $country, $pincode, $profession, $workplace_name,
            $designation, $professional_registration_number, $registration_state, $registration_type,
            $added_by, $area_of_interest, $is_verified, $profile_photo, $birth_date, $bio
        );
        $stmt->execute();
        $userId = $stmt->insert_id;
        $stmt->close();
    } else {
        $userId = $attendee['id'];
        if ($attendee['is_deleted'] == 1) {
            $stmt = $conn->prepare("UPDATE " . TABLE_ATTENDEES . " SET is_deleted = 0, modified_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $travel = $category['is_travel'] ?? 0;
    $accommodation = $category['is_accomodation'] ?? 0;
    $taxi = $category['is_travel'] ?? 0;
    $kit = $category['is_kit'] ?? 0;
    $certificate = $category['is_certificate'] ?? 0;
    $lunch = $category['is_lunch'] ?? 0;
    $dinner = $category['is_dinner'] ?? 0;
    $amount = $category['free_registration'] ? 0 : ($regDetails['amount'] ?? 0.00);
    $transaction_id = $regDetails['transaction_id'] ?? null;
    $order_no = $regDetails['order_no'] ?? null;
    $order_id = $regDetails['order_id'] ?? null;
    $payment_id = $regDetails['payment_id'] ?? null;
    $payment_mode = $regDetails['payment_mode'] ?? 'Free';
    $status = $regDetails['status'] ?? 1;
    $added_by = $regDetails['added_by'] ?? null;

    $stmt = $eventDb->prepare("
        INSERT INTO " . TABLE_EVENT_REGISTRATIONS . " (
            event_id, user_id, category_id, all_day_registration, travel, accommodation, taxi, kit,
            certificate, lunch, dinner, amount, transaction_id, order_no,
            order_id, payment_id, payment_mode, status, added_by, is_deleted, created_at, modified_at
        ) VALUES (
            ?, ?, ?, 1, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, 0, NOW(), NOW()
        )
    ");

    $stmt->bind_param(
        "iiiiiiiiiidsssssis",
        $eventId, $userId, $categoryId,
        $travel, $accommodation, $taxi, $kit,
        $certificate, $lunch, $dinner, $amount,
        $transaction_id, $order_no, $order_id,
        $payment_id, $payment_mode, $status, $added_by
    );

    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'User Registered Successfully']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
