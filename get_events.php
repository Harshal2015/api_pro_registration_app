<?php
header('Content-Type: application/json');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "prop_propass";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

session_start();

$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

$user_id = isset($input['user_id']) ? intval($input['user_id']) :
          (isset($_POST['user_id']) ? intval($_POST['user_id']) : 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ? AND is_deleted = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}

$user = $result->fetch_assoc();
$is_admin = intval($user['is_admin']);

if ($is_admin === 0) {
    $sql = "SELECT * FROM events 
            WHERE is_deleted = 0 
            AND CURDATE() BETWEEN start_date AND end_date 
            ORDER BY start_date DESC";
} else {
    $sql = "SELECT * FROM events 
            WHERE is_deleted = 0 
            ORDER BY start_date DESC";
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
