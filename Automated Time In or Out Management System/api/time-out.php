<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(["status" => "error", "message" => "Method not allowed"]));
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Invalid JSON input"]));
}

if (!isset($_SESSION['professor_id'])) {
    http_response_code(401);
    die(json_encode(["status" => "error", "message" => "Not logged in"]));
}

if (!isset($input['professor_id']) || !isset($input['session'])) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Missing required parameters"]));
}

$professorId = $_SESSION['professor_id'];
$session = strtoupper($input['session']);
$today = date('Y-m-d');

// Validate session
if (!in_array($session, ['AM', 'PM'])) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Invalid session value"]));
}

try {
    $conn->begin_transaction();
    
    // 1. Get professor name and schedule info
    $professorStmt = $conn->prepare("
        SELECT p.name, a.schedule_id, ps.start_time as scheduled_time
        FROM professors p
        LEFT JOIN attendance a ON a.professor_id = p.id AND a.date = ?
        LEFT JOIN professor_schedules ps ON a.schedule_id = ps.id
        WHERE p.id = ?
    ");
    $professorStmt->bind_param("si", $today, $professorId);
    $professorStmt->execute();
    $professorResult = $professorStmt->get_result();
    
    if ($professorResult->num_rows === 0) {
        throw new Exception("Professor not found");
    }
    
    $professorData = $professorResult->fetch_assoc();
    $professorName = $professorData['name'];
    $scheduled_time = $professorData['scheduled_time'];
    
    // 2. Update time-out
    $column = strtolower($session) . '_check_out';
    $stmt = $conn->prepare("
        UPDATE attendance 
        SET $column = NOW(),
            status = 'present'
        WHERE professor_id = ? 
        AND date = ? 
        AND $column IS NULL
    ");
    $stmt->bind_param("is", $professorId, $today);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        throw new Exception("No active time-in found to time out from");
    }

    // 3. Calculate work duration if both sessions are complete
    $durationStmt = $conn->prepare("
        UPDATE attendance 
        SET work_duration = TIMEDIFF(
            COALESCE(pm_check_out, am_check_out),
            COALESCE(am_check_in, pm_check_in)
        )
        WHERE professor_id = ? AND date = ?
        AND am_check_in IS NOT NULL AND pm_check_out IS NOT NULL
    ");
    $durationStmt->bind_param("is", $professorId, $today);
    $durationStmt->execute();
    
    // 4. Log the action
    $logAction = "Professor timed out from $session session";
    $log = $conn->prepare("INSERT INTO logs (action, user, timestamp) VALUES (?, ?, NOW())");
    $log->bind_param("ss", $logAction, $professorName);
    $log->execute();
    
    // 5. Create notification
    $notifMessage = "$professorName has timed out from $session session at " . date('h:i A');
    if ($scheduled_time) {
        $notifMessage .= " (Scheduled: " . date('h:i A', strtotime($scheduled_time)) . ")";
    }
    $notifType = "time-out";
    $notif = $conn->prepare("INSERT INTO notifications (message, type, created_at) VALUES (?, ?, NOW())");
    $notif->bind_param("ss", $notifMessage, $notifType);
    $notif->execute();
    
    $conn->commit();
    
    echo json_encode([
        "status" => "success",
        "message" => "Time out recorded successfully",
        "session" => $session,
        "professor_name" => $professorName,
        "time_out" => date('Y-m-d H:i:s'),
        "scheduled_time" => $scheduled_time
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}