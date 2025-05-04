<?php
include '../config/database.php';
header('Content-Type: application/json');

try {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

    // Get recent attendance records with professor names
    $query = "
        SELECT 
            p.name as professor_name,
            a.date,
            CASE 
                WHEN a.am_check_in IS NOT NULL AND a.am_check_out IS NULL THEN 'AM Time In'
                WHEN a.am_check_out IS NOT NULL THEN 'AM Time Out'
                WHEN a.pm_check_in IS NOT NULL AND a.pm_check_out IS NULL THEN 'PM Time In'
                WHEN a.pm_check_out IS NOT NULL THEN 'PM Time Out'
                ELSE 'Unknown'
            END as action,
            CASE 
                WHEN a.am_check_in IS NOT NULL THEN TIME_FORMAT(a.am_check_in, '%h:%i %p')
                WHEN a.pm_check_in IS NOT NULL THEN TIME_FORMAT(a.pm_check_in, '%h:%i %p')
                WHEN a.am_check_out IS NOT NULL THEN TIME_FORMAT(a.am_check_out, '%h:%i %p')
                WHEN a.pm_check_out IS NOT NULL THEN TIME_FORMAT(a.pm_check_out, '%h:%i %p')
                ELSE 'Unknown'
            END as time,
            CASE 
                WHEN a.am_check_in IS NOT NULL OR a.am_check_out IS NOT NULL THEN 'AM'
                WHEN a.pm_check_in IS NOT NULL OR a.pm_check_out IS NOT NULL THEN 'PM'
                ELSE 'Unknown'
            END as session_type
        FROM attendance a
        JOIN professors p ON a.professor_id = p.id
        WHERE a.checkin_date = CURDATE()
        ORDER BY 
            CASE 
                WHEN a.am_check_in IS NOT NULL THEN a.am_check_in
                WHEN a.pm_check_in IS NOT NULL THEN a.pm_check_in
                WHEN a.am_check_out IS NOT NULL THEN a.am_check_out
                WHEN a.pm_check_out IS NOT NULL THEN a.pm_check_out
                ELSE '0000-00-00 00:00:00'
            END DESC
        LIMIT ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = [
            'professor_name' => $row['professor_name'],
            'action' => $row['action'],
            'time' => $row['time'],
            'session_type' => $row['session_type'],
            'date' => date("M j, Y", strtotime($row['date']))
        ];
    }

    echo json_encode($history);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}