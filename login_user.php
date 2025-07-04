<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "prop_propass";
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_username = $_POST['username'];
    $input_password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, username, password, access_chips, is_admin FROM users WHERE username = ? AND is_deleted = FALSE");
    $stmt->bind_param("s", $input_username);
    $stmt->execute();

    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        if ($input_password === $row['password']) {
            $_SESSION['user_id'] = $row['id']; // ✅ Consistent session name
            $_SESSION['username'] = $row['username'];
            $_SESSION['access_chips'] = $row['access_chips'];
            $_SESSION['is_admin'] = $row['is_admin'];

            echo json_encode([
    'status' => 'success',
    'message' => 'Login successful',
    'user_id' => $row['id'],
    'username' => $row['username'],
    'access_chips' => $row['access_chips'],
    'is_admin' => $row['is_admin']
]);

        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid password']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
    }

    $stmt->close();
}

$conn->close();
?>
