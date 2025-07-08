<?php

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "prop_propass";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing id']);
    exit();
}

$user_id = intval($input['user_id']); 

$sql = "
    SELECT f.id, f.name, f.count, f.created_at, f.modified_at, f.is_deleted
    FROM reg_functionalities f
    INNER JOIN reg_user_access ua ON ua.functionality_id = f.id
    WHERE ua.user_id = ? AND ua.is_deleted = 0 AND f.is_deleted = 0
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$functionalities = [];

while ($row = $result->fetch_assoc()) {
    $functionalities[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $functionalities,
]);

$stmt->close();
$conn->close();
