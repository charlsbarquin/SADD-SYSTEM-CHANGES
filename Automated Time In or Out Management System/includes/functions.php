<?php
function getAdminName($conn, $adminId) {
    if (!$adminId) return 'System';
    
    $stmt = $conn->prepare("SELECT username FROM admins WHERE id = ?");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0 ? $result->fetch_assoc()['username'] : 'Unknown';
}

function calculateWorkDuration($conn, $professorId, $date) {
    $stmt = $conn->prepare("
        SELECT 
            am_check_in, 
            am_check_out, 
            pm_check_in, 
            pm_check_out
        FROM attendance 
        WHERE professor_id = ? AND date = ?
    ");
    $stmt->bind_param("is", $professorId, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return '00:00:00';
    }
    
    $attendance = $result->fetch_assoc();
    $totalSeconds = 0;
    
    // Calculate AM duration
    if ($attendance['am_check_in'] && $attendance['am_check_out']) {
        $amStart = new DateTime($attendance['am_check_in']);
        $amEnd = new DateTime($attendance['am_check_out']);
        $totalSeconds += $amEnd->getTimestamp() - $amStart->getTimestamp();
    }
    
    // Calculate PM duration
    if ($attendance['pm_check_in'] && $attendance['pm_check_out']) {
        $pmStart = new DateTime($attendance['pm_check_in']);
        $pmEnd = new DateTime($attendance['pm_check_out']);
        $totalSeconds += $pmEnd->getTimestamp() - $pmStart->getTimestamp();
    }
    
    // Convert to H:i:s format
    $hours = floor($totalSeconds / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;
    
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

function getProfessorStatus($amCheckIn, $pmCheckIn) {
    if ($amCheckIn && $pmCheckIn) {
        return 'present';
    } elseif ($amCheckIn || $pmCheckIn) {
        return 'half-day';
    }
    return 'absent';
}

function logAction($conn, $action, $user, $targetId = null) {
    $stmt = $conn->prepare("INSERT INTO logs (action, user, target_id, timestamp) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("ssi", $action, $user, $targetId);
    $stmt->execute();
}

function createNotification($conn, $message, $type) {
    $stmt = $conn->prepare("INSERT INTO notifications (message, type, created_at) VALUES (?, ?, NOW())");
    $stmt->bind_param("ss", $message, $type);
    $stmt->execute();
    return $conn->insert_id;
}

// Calculate work duration between two times
function calculateDurationBetweenTimes($start, $end) {
    if (!$start || !$end) return '00:00:00';
    
    $startTime = new DateTime($start);
    $endTime = new DateTime($end);
    $interval = $startTime->diff($endTime);
    
    return $interval->format('%H:%I:%S');
}

// Get professor's schedule
function getProfessorSchedule($professorId, $conn) {
    $schedule = [];
    $result = $conn->query("SELECT * FROM professor_schedules WHERE professor_id = $professorId ORDER BY day_id, start_time");
    while ($row = $result->fetch_assoc()) {
        $schedule[$row['day_id']][] = $row;
    }
    return $schedule;
}

