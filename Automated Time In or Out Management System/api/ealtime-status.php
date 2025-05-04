<?php
require_once '../config/database.php';

header('Content-Type: application/json');

// Get current day of week
$current_day_id = date('N');

// Get real-time statistics
$stats = [
    'on_time' => $conn->query("
        SELECT COUNT(DISTINCT a.professor_id) 
        FROM attendance a
        JOIN professor_schedules ps ON a.professor_id = ps.professor_id 
            AND DAYOFWEEK(a.checkin_date) = ps.day_id
            AND (
                (TIME(a.am_check_in) <= ps.start_time AND TIME(a.am_check_in) IS NOT NULL) OR
                (TIME(a.pm_check_in) <= ps.start_time AND TIME(a.pm_check_in) IS NOT NULL)
            )
        WHERE a.checkin_date = CURDATE()
    ")->fetch_row()[0],

    'late_arrivals' => $conn->query("
        SELECT COUNT(DISTINCT a.professor_id) 
        FROM attendance a
        JOIN professor_schedules ps ON a.professor_id = ps.professor_id 
            AND DAYOFWEEK(a.checkin_date) = ps.day_id
        WHERE a.checkin_date = CURDATE()
        AND (
            (TIME(a.am_check_in) > ps.start_time AND TIME(a.am_check_in) IS NOT NULL) OR
            (TIME(a.pm_check_in) > ps.start_time AND TIME(a.pm_check_in) IS NOT NULL)
        )
    ")->fetch_row()[0],

    'absent' => $conn->query("
        SELECT COUNT(DISTINCT p.id) 
        FROM professors p
        JOIN professor_schedules ps ON p.id = ps.professor_id AND ps.day_id = $current_day_id
        WHERE p.status = 'active' 
        AND p.id NOT IN (
            SELECT professor_id FROM attendance WHERE checkin_date = CURDATE()
        )
    ")->fetch_row()[0],

    'active_now' => $conn->query("
        SELECT COUNT(DISTINCT p.id)
        FROM professors p
        JOIN professor_schedules ps ON p.id = ps.professor_id AND ps.day_id = $current_day_id
        WHERE TIME(NOW()) BETWEEN ps.start_time AND ps.end_time
        AND p.id IN (
            SELECT professor_id FROM attendance 
            WHERE checkin_date = CURDATE() 
            AND (pm_check_out IS NULL OR am_check_out IS NULL)
        )
    ")->fetch_row()[0]
];

echo json_encode($stats);
?>