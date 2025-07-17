<?php
$servername = "localhost";
$username = "root";
$password = "";
$mainDb = "prop_propass";
$conn = new mysqli($servername, $username, $password, $mainDb);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}
?>
