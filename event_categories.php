<?php
header('Content-Type: application/json');

require_once 'config.php';                
require_once 'connect_event_database.php'; 

try {
    $eventId = $_GET['event_id'] ?? '';

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

    $sql = "SELECT id, name FROM event_categories WHERE parent_id IS NOT NULL";
    $result = $eventConn->query($sql);

    $categories = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $categories[] = [
                'id' => (int)$row['id'],
                'name' => $row['name']
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $categories
    ]);

    $eventConn->close();
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
