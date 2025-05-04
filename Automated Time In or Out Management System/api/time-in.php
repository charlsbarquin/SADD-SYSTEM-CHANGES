<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Verify the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(["status" => "error", "message" => "Method not allowed"]));
}

// Check if professor is logged in
if (!isset($_SESSION['professor_id'])) {
    http_response_code(401);
    die(json_encode(["status" => "error", "message" => "Not logged in"]));
}

$professor_id = $_SESSION['professor_id'];
$current_time = date("Y-m-d H:i:s");
$today = date("Y-m-d");
$day_of_week = date('N'); // 1-7 (Monday-Sunday)

// Determine current session (AM or PM)
$currentHour = date('H');
$isAM = ($currentHour < 12); // AM session is before noon
$session = $isAM ? 'AM' : 'PM';

// Start transaction
$conn->begin_transaction();

try {
    // 1. Get professor details
    $professorQuery = $conn->prepare("SELECT name FROM professors WHERE id = ?");
    $professorQuery->bind_param("i", $professor_id);
    $professorQuery->execute();
    $result = $professorQuery->get_result();

    if ($result->num_rows === 0) {
        throw new Exception("Professor not found");
    }

    $professor = $result->fetch_assoc();
    $professor_name = $professor['name'];

    // 2. Get professor's schedule for today
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
        $current_time_compare = strtotime(date('H:i:s'));
        
        // Check if current time is within 1 hour of scheduled time
        if (abs($current_time_compare - $schedule_start) <= 3600) {
            $schedule_id = $schedule['id'];
            $scheduled_time = $schedule['start_time'];
            
            // Check if late (more than 15 minutes after scheduled time)
            if (($current_time_compare - $schedule_start) > 900) {
                $is_late = 1;
            }
            break;
        }
    }

    // 3. Check if already checked in for this session today
    $checkQuery = $conn->prepare("SELECT id FROM attendance 
                                WHERE professor_id = ? AND date = ? AND " . 
                                ($isAM ? "am_check_in IS NOT NULL" : "pm_check_in IS NOT NULL"));
    $checkQuery->bind_param("is", $professor_id, $today);
    $checkQuery->execute();

    if ($checkQuery->get_result()->num_rows > 0) {
        throw new Exception("You have already timed in for $session session today");
    }

    // 4. Check if there's an existing record for today
    $existingQuery = $conn->prepare("SELECT id FROM attendance WHERE professor_id = ? AND date = ?");
    $existingQuery->bind_param("is", $professor_id, $today);
    $existingQuery->execute();
    $existingResult = $existingQuery->get_result();

    if ($existingResult->num_rows > 0) {
        // Update existing record
        $attendance = $existingResult->fetch_assoc();
        $attendance_id = $attendance['id'];
        
        if ($isAM) {
            $query = "UPDATE attendance SET 
                      am_check_in = ?,
                      schedule_id = ?,
                      is_late = ?,
                      status = CASE 
                        WHEN pm_check_in IS NOT NULL THEN 'present'
                        ELSE 'half-day'
                      END
                      WHERE id = ?";
        } else {
            $query = "UPDATE attendance SET 
                      pm_check_in = ?,
                      schedule_id = ?,
                      is_late = ?,
                      status = CASE 
                        WHEN am_check_in IS NOT NULL THEN 'present'
                        ELSE 'half-day'
                      END
                      WHERE id = ?";
        }
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("siii", $current_time, $schedule_id, $is_late, $attendance_id);
    } else {
        // Create new record
        if ($isAM) {
            $query = "INSERT INTO attendance (
                        professor_id, 
                        date,
                        am_check_in, 
                        schedule_id,
                        is_late,
                        status,
                        checkin_date
                      ) VALUES (?, ?, ?, ?, ?, 'half-day', CURDATE())";
        } else {
            $query = "INSERT INTO attendance (
                        professor_id, 
                        date,
                        pm_check_in, 
                        schedule_id,
                        is_late,
                        status,
                        checkin_date
                      ) VALUES (?, ?, ?, ?, ?, 'half-day', CURDATE())";
        }
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issii", $professor_id, $today, $current_time, $schedule_id, $is_late);
    }

    if (!$stmt->execute()) {
        throw new Exception("Failed to record time-in: " . $stmt->error);
    }

    // 5. Create notification
    $late_text = $is_late ? " (Late)" : "";
    $notif_message = "$professor_name has timed in for $session session at " . date('h:i A') . $late_text;
    $notif_type = "time-in";
    
    $notifQuery = $conn->prepare("INSERT INTO notifications (message, type, created_at) VALUES (?, ?, NOW())");
    $notifQuery->bind_param("ss", $notif_message, $notif_type);
    $notifQuery->execute();

    // 6. Log the action
    $late_log = $is_late ? " (Late)" : "";
    $logAction = "Professor timed in for $session session" . $late_log;
    $logQuery = $conn->prepare("INSERT INTO logs (action, user, timestamp) VALUES (?, ?, NOW())");
    $logQuery->bind_param("ss", $logAction, $professor_name);
    $logQuery->execute();

    // Commit transaction
    $conn->commit();

    // Success response
    echo json_encode([
        "status" => "success",
        "message" => "$session Time In recorded successfully",
        "professor_name" => $professor_name,
        "time_in" => $current_time,
        "session" => $session,
        "scheduled_time" => $scheduled_time,
        "is_late" => $is_late
    ]);
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}