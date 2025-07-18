<?php
header('Content-Type: application/json');

require_once 'config.php';                
require_once 'connect_event_database.php'; 

try {
    $eventId = $_GET['event_id'] ?? '';

    if (!$eventId) {
        throw new Exception('Missing event_id');
    }

    // Step 1: Get short_name from main DB
    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $stmt->bind_result($shortName);
    $stmt->fetch();
    $stmt->close();

    if (empty($shortName)) {
        throw new Exception('Invalid event_id or short_name not found');
    }

    // Step 2: Connect to event-specific database
    $eventResult = connectEventDb($eventId);
    if (!$eventResult['success']) {
        throw new Exception($eventResult['message']);
    }

    $eventConn = $eventResult['conn'];

    // Step 3: Fetch categories (not halls)
    $categorySql = "SELECT id, name FROM event_categories 
                    WHERE parent_id IS NOT NULL AND is_hall = 0 AND is_deleted = 0";
    $categoryResult = $eventConn->query($categorySql);

    $categories = [];
    if ($categoryResult && $categoryResult->num_rows > 0) {
        while ($row = $categoryResult->fetch_assoc()) {
            $categories[] = [
                'id' => (int)$row['id'],
                'name' => $row['name']
            ];
        }
    }

    // Step 4: Fetch halls
    $hallSql = "SELECT id, name FROM event_categories 
                WHERE is_hall = 1 AND is_deleted = 0";
    $hallResult = $eventConn->query($hallSql);

    $halls = [];
    if ($hallResult && $hallResult->num_rows > 0) {
        while ($row = $hallResult->fetch_assoc()) {
            $halls[] = [
                'id' => (int)$row['id'],
                'name' => $row['name']
            ];
        }
    }

    // Step 5: Return response
    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'halls' => $halls
    ]);

    $eventConn->close();

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
