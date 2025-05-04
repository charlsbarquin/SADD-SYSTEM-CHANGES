<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(["status" => "error", "message" => "Method not allowed"]));
}

// Get input data
$data = json_decode(file_get_contents('php://input'), true);
$professorId = $data['professor_id'] ?? null;
$date = $data['date'] ?? date('Y-m-d');

if (!$professorId) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Professor ID is required"]));
}

try {
    $conn->begin_transaction();
    
    // Calculate and update work duration
    $stmt = $conn->prepare("
        UPDATE attendance 
        SET work_duration = TIMEDIFF(
            COALESCE(pm_check_out, am_check_out),
            COALESCE(am_check_in, pm_check_in)
        )
        WHERE professor_id = ? AND date = ?
    ");
    $stmt->bind_param("is", $professorId, $date);
    $stmt->execute();
    
    // Get the updated record
    $result = $conn->prepare("
        SELECT work_duration 
        FROM attendance 
        WHERE professor_id = ? AND date = ?
    ");
    $result->bind_param("is", $professorId, $date);
    $result->execute();
    $attendance = $result->get_result()->fetch_assoc();
    
    $conn->commit();
    
    echo json_encode([
        "status" => "success",
        "work_duration" => $attendance['work_duration'] ?? null
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to calculate work duration: " . $e->getMessage()
    ]);
}