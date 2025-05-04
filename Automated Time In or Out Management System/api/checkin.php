<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session.php';
include '../config/database.php';

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    http_response_code(405);
    die(json_encode(["status" => "error", "message" => "Method Not Allowed"]));
}

$professor_id = $_POST['professor_id'] ?? null;
$image_data = $_POST['image_data'] ?? null;
$latitude = $_POST['latitude'] ?? null;
$longitude = $_POST['longitude'] ?? null;
$accuracy = $_POST['accuracy'] ?? null;
$session_type = $_POST['session_type'] ?? 'am';

if (!$professor_id) {
    http_response_code(400);
    die(json_encode(["status" => "error", "message" => "Professor ID is required"]));
}

// Get current day of week
$day_of_week = date('N');
$current_time = date('H:i:s');

// Get professor's schedule for today
$scheduleQuery = $conn->prepare("
    SELECT id, start_time 
    FROM professor_schedules 
    WHERE professor_id = ? 
    AND day_id = ?
    ORDER BY start_time
");
$scheduleQuery->bind_param("ii", $professor_id, $day_of_week);
$scheduleQuery->execute();
$schedules = $scheduleQuery->get_result()->fetch_all(MYSQLI_ASSOC);

$schedule_id = null;
$is_late = 0;
$scheduled_time = null;

foreach ($schedules as $schedule) {
    $schedule_start = strtotime($schedule['start_time']);
    $current_time_compare = strtotime($current_time);
    
    if (abs($current_time_compare - $schedule_start) <= 3600) {
        $schedule_id = $schedule['id'];
        $scheduled_time = $schedule['start_time'];
        
        if (($current_time_compare - $schedule_start) > 900) {
            $is_late = 1;
        }
        break;
    }
}

$professorQuery = $conn->prepare("SELECT name FROM professors WHERE id = ?");
$professorQuery->bind_param("i", $professor_id);
$professorQuery->execute();
$result = $professorQuery->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    die(json_encode(["status" => "error", "message" => "Professor not found"]));
}

$professor = $result->fetch_assoc();
$professorName = $professor['name'];

$existingQuery = $conn->prepare("SELECT id FROM attendance 
                               WHERE professor_id = ? 
                               AND DATE(checkin_date) = CURDATE()");
$existingQuery->bind_param("i", $professor_id);
$existingQuery->execute();

if ($existingQuery->get_result()->num_rows > 0) {
    http_response_code(409);
    die(json_encode(["status" => "error", "message" => "Already timed in today"]));
}

$photo_path = null;
if ($image_data) {
    $upload_dir = '../uploads/checkins/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $filename = 'checkin_' . $professor_id . '_' . time() . '.jpg';
    $photo_path = $upload_dir . $filename;
    
    if (!file_put_contents($photo_path, base64_decode($image_data))) {
        error_log("Failed to save image for professor $professor_id");
    }
}

$time_field = ($session_type === 'am') ? 'am_check_in' : 'pm_check_in';
$photo_field = ($session_type === 'am') ? 'am_face_scan_image' : 'pm_face_scan_image';

$sql = "INSERT INTO attendance 
        (professor_id, $time_field, $photo_field, latitude, longitude, checkin_date, schedule_id, is_late) 
        VALUES (?, NOW(), ?, ?, ?, CURDATE(), ?, ?)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("isddii", 
    $professor_id,
    $photo_path,
    $latitude,
    $longitude,
    $schedule_id,
    $is_late
);

if ($stmt->execute()) {
    // Create notification
    $session_display = strtoupper($session_type) . ' session';
    $current_time_display = date('h:i A');
    $late_text = $is_late ? " (Late)" : "";
    $scheduled_text = $scheduled_time ? " (Scheduled: " . date('h:i A', strtotime($scheduled_time)) . ")" : "";
    
    $notification_message = ucfirst($professorName) . ' has timed in for ' . $session_display . ' at ' . $current_time_display . $late_text . $scheduled_text;
    $notification_type = "time-in";
    
    $notif_stmt = $conn->prepare("INSERT INTO notifications 
                                (message, type, created_at) 
                                VALUES (?, ?, NOW())");
    $notif_stmt->bind_param("ss", $notification_message, $notification_type);
    $notif_stmt->execute();
    
    // Log action
    $action = "Time In - " . $session_display . $late_text;
    $log_stmt = $conn->prepare("INSERT INTO logs 
                               (action, user, timestamp)
                               VALUES (?, ?, NOW())");
    $log_stmt->bind_param("ss", $action, $professorName);
    $log_stmt->execute();
    
    echo json_encode([
        "status" => "success", 
        "message" => "Time in recorded successfully",
        "checkin_time" => date('Y-m-d H:i:s'),
        "session_type" => $session_type,
        "scheduled_time" => $scheduled_time,
        "is_late" => $is_late
    ]);
} else {
    http_response_code(500);
    error_log("Database error: " . $stmt->error);
    echo json_encode([
        "status" => "error", 
        "message" => "Failed to record time in"
    ]);
}

$conn->close();
?>