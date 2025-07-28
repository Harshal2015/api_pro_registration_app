<?php
header("Content-Type: application/json");
date_default_timezone_set('Asia/Kolkata');

require_once 'auth_api.php';
require_once 'config.php';
require_once 'connect_event_database.php';
require_once 'tables.php';

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $event_id    = $input['event_id'] ?? null;
    $user_id     = $input['user_id'] ?? null;  
    $category_id = $input['category_id'] ?? null;

    $industry_name = trim($input['name'] ?? '');
    $industry_id_input = $input['id'] ?? ($input['id'] ?? null);

    $prefix            = $input['prefix'] ?? null;
    $first_name        = trim($input['first_name'] ?? '');
    $last_name         = trim($input['last_name'] ?? '');
    $phone             = trim($input['primary_phone_number'] ?? '');
    $email             = trim($input['primary_email_address'] ?? '');
    $city              = trim($input['city'] ?? '');
    $state             = trim($input['state'] ?? '');
    $country           = trim($input['country'] ?? '');
    $mci_number        = trim($input['professional_registration_number'] ?? '');
    $registration_type = trim($input['registration_type'] ?? '');
    $profession        = trim($input['profession'] ?? '');
    $added_by          = trim($input['added_by'] ?? '');

    if (!$event_id || !$category_id) {
        throw new Exception("Missing required fields: event_id or category_id");
    }

    $eventResult = connectEventDb($event_id);
    if (!$eventResult['success']) {
        throw new Exception($eventResult['message']);
    }
    $eventConn = $eventResult['conn'];
    $mainConn = $conn;

    $catTypeStmt = $eventConn->prepare("
        SELECT id FROM event_industries 
        WHERE event_id = ? AND category_id = ? 
        LIMIT 1
    ");
    $catTypeStmt->bind_param("ii", $event_id, $category_id);
    $catTypeStmt->execute();
    $catRes = $catTypeStmt->get_result();
    $isIndustry = $catRes->num_rows > 0;
    $catTypeStmt->close();

    if ($isIndustry) {
        if (empty($industry_name)) {
            throw new Exception("Industry name is required.");
        }

        if ($industry_id_input) {
            $id = intval($industry_id_input);

            $verifyStmt = $eventConn->prepare("
                SELECT id FROM event_industries 
                WHERE id = ? AND event_id = ? AND category_id = ? LIMIT 1
            ");
            $verifyStmt->bind_param("iii", $id, $event_id, $category_id);
            $verifyStmt->execute();
            $verifyRes = $verifyStmt->get_result();
            $industry = $verifyRes->fetch_assoc();
            $verifyStmt->close();

            if (!$industry) {
                throw new Exception("Invalid id for this event and category.");
            }
        } else {
            $getIndustry = $eventConn->prepare("
                SELECT id FROM event_industries 
                WHERE event_id = ? AND category_id = ? 
                LIMIT 1
            ");
            $getIndustry->bind_param("ii", $event_id, $category_id);
            $getIndustry->execute();
            $resIndustry = $getIndustry->get_result();
            $industry = $resIndustry->fetch_assoc();
            $getIndustry->close();

            if (!$industry) {
                throw new Exception("Industry record not found for this event and category.");
            }
            $id = $industry['id'];
        }
        error_log("Updating industry ID: " . $id);

        $updInd = $eventConn->prepare("
            UPDATE event_industries 
            SET name = ?, modified_at = NOW()
            WHERE id = ?
        ");
        $updInd->bind_param("si", $industry_name, $id);
        $updInd->execute();
        $updInd->close();

        echo json_encode([
            'success' => true,
            'message' => 'Industry name updated successfully.',
            'industry_id_updated' => $id
        ]);
        exit;
    }

    if (!$user_id) {
        throw new Exception("Missing user_id for attendee update.");
    }

    $checkReg = $eventConn->prepare("
        SELECT category_id FROM event_registrations WHERE event_id = ? AND user_id = ?
    ");
    $checkReg->bind_param("ii", $event_id, $user_id);
    $checkReg->execute();
    $regRes = $checkReg->get_result();
    $reg = $regRes->fetch_assoc();
    $checkReg->close();

    if ($reg && $reg['category_id'] != $category_id) {
        $updReg = $eventConn->prepare("
            UPDATE event_registrations 
            SET category_id = ?, modified_at = NOW()
            WHERE event_id = ? AND user_id = ?
        ");
        $updReg->bind_param("iii", $category_id, $event_id, $user_id);
        $updReg->execute();
        $updReg->close();
    }

    $chkAtt = $mainConn->prepare("SELECT * FROM " . TABLE_ATTENDEES . " WHERE id = ?");
    $chkAtt->bind_param("i", $user_id);
    $chkAtt->execute();
    $attRes = $chkAtt->get_result();
    $att = $attRes->fetch_assoc();
    $chkAtt->close();

    if ($att) {
        $newShort = trim($first_name . ' ' . $last_name);

        $fields = [
            'prefix' => $prefix ?? $att['prefix'],
            'first_name' => $first_name ?: $att['first_name'],
            'last_name' => $last_name ?: $att['last_name'],
            'primary_phone_number' => $phone ?: $att['primary_phone_number'],
            'primary_email_address' => $email ?: $att['primary_email_address'],
            'city' => $city ?: $att['city'],
            'state' => $state ?: $att['state'],
            'country' => $country ?: $att['country'],
            'professional_registration_number' => $mci_number ?: $att['professional_registration_number'],
            'registration_type' => $registration_type ?: $att['registration_type'],
            'profession' => $profession ?: $att['profession'],
            'added_by' => $added_by ?: $att['added_by'],
            'short_name' => $newShort ?: $att['short_name']
        ];

        $needsUpdate = false;
        foreach ($fields as $k => $v) {
            if ($att[$k] !== $v) {
                $needsUpdate = true;
                break;
            }
        }

        if ($needsUpdate) {
            $updAtt = $mainConn->prepare("
                UPDATE " . TABLE_ATTENDEES . "
                SET prefix = ?, first_name = ?, last_name = ?, primary_phone_number = ?, 
                    primary_email_address = ?, city = ?, state = ?, country = ?, 
                    professional_registration_number = ?, registration_type = ?, 
                    profession = ?, added_by = ?, short_name = ?, modified_at = NOW()
                WHERE id = ?
            ");
            $updAtt->bind_param(
                "sssssssssssssi",
                $fields['prefix'],
                $fields['first_name'],
                $fields['last_name'],
                $fields['primary_phone_number'],
                $fields['primary_email_address'],
                $fields['city'],
                $fields['state'],
                $fields['country'],
                $fields['professional_registration_number'],
                $fields['registration_type'],
                $fields['profession'],
                $fields['added_by'],
                $fields['short_name'],
                $user_id
            );
            $updAtt->execute();
            $updAtt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Attendee updated successfully.'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
