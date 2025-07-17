<?php
header('Content-Type: application/json');

session_start();

require_once 'config.php';

$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

$user_id = isset($input['user_id']) ? intval($input['user_id']) :
           (isset($_POST['user_id']) ? intval($_POST['user_id']) : 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

$stmt = $conn->prepare("SELECT is_admin, event_id FROM users WHERE id = ? AND is_deleted = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}

$user = $result->fetch_assoc();
$is_admin = intval($user['is_admin']);
$user_event_id = intval($user['event_id']); 

if ($is_admin === 1) {
    $sql = "SELECT * FROM events 
            WHERE is_deleted = 0 
            ORDER BY from_date DESC";
} else {
    $sql = "SELECT * FROM events 
            WHERE is_deleted = 0 
            AND id = $user_event_id
            ORDER BY from_date DESC";
}

$eventResult = $conn->query($sql);
$events = [];

if ($eventResult->num_rows > 0) {
    while ($row = $eventResult->fetch_assoc()) {
        $events[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $events]);
} else {
    echo json_encode(['success' => false, 'message' => 'No events found']);
}

$conn->close();
?>
