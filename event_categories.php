<?php
header('Content-Type: application/json');

require_once 'config.php';                
require_once 'connect_event_database.php'; 
require_once 'auth_api.php';

try {
    $input = json_decode(file_get_contents("php://input"), true);
    $eventId = $input['event_id'] ?? '';

    if (!$eventId) {
        throw new Exception('Missing event_id');
    }

    $stmt = $conn->prepare("SELECT short_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $stmt->bind_result($shortName);
    $stmt->fetch();
    $stmt->close();

    if (empty($shortName)) {
        throw new Exception('Invalid event_id or short_name not found');
    }

    $eventResult = connectEventDb($eventId);
    if (!$eventResult['success']) {
        throw new Exception($eventResult['message']);
    }

    $eventConn = $eventResult['conn'];

    $categorySql = "SELECT id, name FROM event_categories 
                    WHERE parent_id IS NOT NULL AND is_deleted = 0";
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

    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);

    $eventConn->close();

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
