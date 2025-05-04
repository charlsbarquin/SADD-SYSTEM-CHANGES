<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

if (!isset($_SESSION['professor_id'])) {
    exit;
}

$professorId = $_SESSION['professor_id'];
$currentHour = date('H');
$isAM = ($currentHour < 12);
$session = $isAM ? 'AM' : 'PM';
$today = date('Y-m-d');
$day_of_week = date('N');

// Get professor's schedule for today
$scheduleQuery = $conn->prepare("
    SELECT id, start_time 
    FROM professor_schedules 
    WHERE professor_id = ? 
    AND day_id = ?
    ORDER BY start_time
");
$scheduleQuery->bind_param("ii", $professorId, $day_of_week);
$scheduleQuery->execute();
$schedules = $scheduleQuery->get_result()->fetch_all(MYSQLI_ASSOC);

$schedule_id = null;
$is_late = 0;

foreach ($schedules as $schedule) {
    $schedule_start = strtotime($schedule['start_time']);
    $current_time_compare = strtotime(date('H:i:s'));
    
    if (abs($current_time_compare - $schedule_start) <= 3600) {
        $schedule_id = $schedule['id'];
        
        if (($current_time_compare - $schedule_start) > 900) {
            $is_late = 1;
        }
        break;
    }
}

// Check if already timed in today
$check = $conn->prepare("SELECT id FROM attendance WHERE professor_id = ? AND date = ?");
$check->bind_param("is", $professorId, $today);
$check->execute();

if ($check->get_result()->num_rows === 0) {
    // Record time-in
    $time_field = $isAM ? 'am_check_in' : 'pm_check_in';
    $stmt = $conn->prepare("
        INSERT INTO attendance (
            professor_id, 
            date, 
            $time_field, 
            schedule_id,
            is_late,
            checkin_date
        ) VALUES (?, ?, NOW(), ?, ?, CURDATE())
    ");
    $stmt->bind_param("isii", $professorId, $today, $schedule_id, $is_late);
    $stmt->execute();
    
    // Log the action
    $professorName = $conn->query("SELECT name FROM professors WHERE id = $professorId")->fetch_assoc()['name'];
    $late_text = $is_late ? " (Late)" : "";
    $action = "Automatic time-in for $session session" . $late_text;
    
    $log = $conn->prepare("INSERT INTO logs (action, user, timestamp) VALUES (?, ?, NOW())");
    $log->bind_param("ss", $action, $professorName);
    $log->execute();
}
?>