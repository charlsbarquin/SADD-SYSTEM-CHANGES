<?php
include '../config/database.php';

header('Content-Type: application/json');

// Get the limit from the URL parameters, default to 10
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

// Query to fetch recent attendance records with schedule data
$query = "SELECT p.name, a.check_in, a.check_out, DATE(a.check_in) AS date,
                 ps.start_time as scheduled_start, ps.end_time as scheduled_end,
                 ps.subject, ps.room
          FROM attendance a
          JOIN professors p ON a.professor_id = p.id
          LEFT JOIN professor_schedules ps ON a.schedule_id = ps.id
          ORDER BY a.check_in DESC
          LIMIT ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $limit);
$stmt->execute();
$result = $stmt->get_result();

// Initialize the attendance data array
$attendanceData = [];

while ($row = $result->fetch_assoc()) {
    // Format the check-in and check-out times
    $attendanceData[] = [
        "name" => $row["name"],
        "check_in" => date("h:i A", strtotime($row["check_in"])),
        "check_out" => $row["check_out"] ? date("h:i A", strtotime($row["check_out"])) : null,
        "date" => date("F d, Y", strtotime($row["date"])),
        "scheduled_start" => $row["scheduled_start"] ? date("h:i A", strtotime($row["scheduled_start"])) : null,
        "scheduled_end" => $row["scheduled_end"] ? date("h:i A", strtotime($row["scheduled_end"])) : null,
        "subject" => $row["subject"] ?? null,
        "room" => $row["room"] ?? null
    ];
}

// Return a JSON response with the attendance data
echo json_encode($attendanceData ?: ["message" => "No recent entries available."]);
?>