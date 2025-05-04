<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$professorId = $_GET['professor_id'] ?? null;
if (!$professorId) {
    http_response_code(400);
    echo json_encode(['error' => 'Professor ID is required']);
    exit;
}

$currentTime = date('H:i:s');
$currentDay = date('N'); // 1 (Monday) through 7 (Sunday)

// Get professor's schedule for today
$scheduleStmt = $conn->prepare("
    SELECT id, start_time, end_time, subject, room 
    FROM professor_schedules 
    WHERE professor_id = ? AND day_id = ?
    ORDER BY start_time
");
$scheduleStmt->bind_param("ii", $professorId, $currentDay);
$scheduleStmt->execute();
$schedules = $scheduleStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$currentSchedule = null;
$isLate = false;

foreach ($schedules as $schedule) {
    if ($currentTime <= $schedule['end_time']) {
        $lateTime = date('H:i:s', strtotime($schedule['start_time']) + 900); // 15 minutes grace period
        $isLate = ($currentTime > $lateTime);
        $currentSchedule = $schedule;
        break;
    }
}

echo json_encode([
    'has_schedule' => !empty($currentSchedule),
    'is_late' => $isLate,
    'schedule' => $currentSchedule
]);