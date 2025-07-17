<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once 'config.php';
$sql = "SELECT id, country_name, dial_code FROM country_codes ORDER BY country_name ASC";
$result = $conn->query($sql);

if ($result === false) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to fetch country codes"
    ]);
    exit();
}

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
