<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$host = "localhost";
$user = "root";
$password = "";
$dbname = "prop_propass";

// Create connection
$conn = new mysqli($host, $user, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit();
}

// Query the country_codes table
$sql = "SELECT id, country_name, dial_code FROM country_codes ORDER BY country_name ASC";
$result = $conn->query($sql);

// Check for results
if ($result->num_rows > 0) {
    $countries = [];

    while ($row = $result->fetch_assoc()) {
        $countries[] = [
            "id" => $row["id"],
            "country_name" => $row["country_name"],
            "dial_code" => $row["dial_code"]
        ];
    }

    echo json_encode([
        "status" => "success",
        "data" => $countries
    ]);
} else {
    echo json_encode([
        "status" => "success",
        "data" => []
    ]);
}

$conn->close();
?>
