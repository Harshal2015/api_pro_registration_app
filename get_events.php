<?php
header('Content-Type: application/json');

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "prop_propass";

// Create DB connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

// Start session (if needed)
session_start();

// Get input data
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

// Get user_id from POST or JSON input
$user_id = isset($input['user_id']) ? intval($input['user_id']) :
           (isset($_POST['user_id']) ? intval($_POST['user_id']) : 0);

// Validate user_id
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

// Check if user exists and get is_admin status
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

// Build query based on admin status
if ($is_admin === 0) {
    // Non-admin: show only events active today
    $sql = "SELECT * FROM events 
            WHERE is_deleted = 0 
            AND CURDATE() BETWEEN from_date AND to_date 
            ORDER BY from_date DESC";
} else {
    // Admin: show all active events
    $sql = "SELECT * FROM events 
            WHERE is_deleted = 0 
            ORDER BY from_date DESC";
}

// Fetch events
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

// Close connection
$conn->close();
?>
