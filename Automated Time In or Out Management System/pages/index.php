<?php
require_once __DIR__ . '/../includes/session.php';
include '../config/database.php';

// Initialize settings array
$settings = [
    'am_cutoff' => '09:00:00',
    'pm_cutoff' => '17:00:00',
    'pm_late_cutoff' => '13:00:00'
];

// Try to get settings from database
try {
    $settingsResult = $conn->query("SELECT * FROM settings LIMIT 1");
    if ($settingsResult && $settingsResult->num_rows > 0) {
        $dbSettings = $settingsResult->fetch_assoc();
        $settings['am_cutoff'] = $dbSettings['am_cutoff'] ?? $settings['am_cutoff'];
        $settings['pm_cutoff'] = $dbSettings['pm_cutoff'] ?? $settings['pm_cutoff'];
        $settings['pm_late_cutoff'] = $dbSettings['pm_late_cutoff'] ?? $settings['pm_late_cutoff'];
    }
} catch (Exception $e) {
    error_log("Error fetching settings: " . $e->getMessage());
}

// Check if professor is logged in
$professorName = '';
$professorId = null;
if (isset($_SESSION['professor_id'])) {
    $professorId = $_SESSION['professor_id'];
    $stmt = $conn->prepare("SELECT name, department FROM professors WHERE id = ?");
    $stmt->bind_param("i", $professorId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $professor = $result->fetch_assoc();
        $professorName = $professor['name'] ?? '';
    }
    $stmt->close();

    // Automatically record time-in when professor logs in
    $currentHour = date('H');
    $isAM = ($currentHour < 12);
    $session = $isAM ? 'AM' : 'PM';
    $today = date('Y-m-d');
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

    $isScheduled = !empty($schedules);
    $isLate = false;
    $scheduleId = null;

    if ($isScheduled) {
        foreach ($schedules as $schedule) {
            $scheduleStart = strtotime($schedule['start_time']);
            $currentTime = strtotime(date('H:i:s'));

            if ($currentTime <= ($scheduleStart + 900)) {
                $scheduleId = $schedule['id'];
                $isLate = false;
                break;
            } elseif ($currentTime > ($scheduleStart + 900)) {
                $scheduleId = $schedule['id'];
                $isLate = true;
                break;
            }
        }
    }

    // Check if already timed in today for current session
    $check = $conn->prepare("SELECT id FROM attendance WHERE professor_id = ? AND date = ? AND " . ($isAM ? 'am_check_in' : 'pm_check_in') . " IS NOT NULL");
    $check->bind_param("is", $professorId, $today);
    $check->execute();

    if ($check->get_result()->num_rows === 0) {
        $status = $isScheduled ? ($isLate ? 'late' : 'on-time') : 'unscheduled';
        $stmt = $conn->prepare("INSERT INTO attendance (professor_id, date, " . ($isAM ? 'am_check_in' : 'pm_check_in') . ", status, is_late, schedule_id) VALUES (?, ?, NOW(), ?, ?, ?)");
        $stmt->bind_param("issii", $professorId, $today, $status, $isLate, $scheduleId);
        $stmt->execute();

        // Log the action
        $log = $conn->prepare("INSERT INTO logs (action, user, timestamp) VALUES (?, ?, NOW())");
        $action = "Professor timed in for $session session";
        $log->bind_param("ss", $action, $professorName);
        $log->execute();

        // Create notification
        $notif = $conn->prepare("INSERT INTO notifications (message, type, created_at) VALUES (?, ?, NOW())");
        $message = "$professorName has timed in for $session session at " . date('h:i A');
        $type = "time-in";
        $notif->bind_param("ss", $message, $type);
        $notif->execute();
    }
}

// Determine current session
$currentHour = date('H');
$isAM = ($currentHour < 12);
$currentSession = $isAM ? 'AM' : 'PM';

// Check if current time is late
$isLate = false;
if ($isAM) {
    $amLateCutoff = isset($settings['am_cutoff']) ? strtotime($settings['am_cutoff']) : strtotime('9:00 AM');
    $currentTime = strtotime(date('H:i:s'));
    $isLate = $currentTime > $amLateCutoff;
} else {
    $pmLateCutoff = isset($settings['pm_late_cutoff']) ? strtotime($settings['pm_late_cutoff']) : strtotime('1:00 PM');
    $currentTime = strtotime(date('H:i:s'));
    $isLate = $currentTime > $pmLateCutoff;
}

// ==================== ENHANCED CHATBOT FUNCTIONALITY ==================== //
$chatbotResponse = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chat_message'])) {
    $userMessage = strtolower(trim($_POST['chat_message']));
    $response = "";

    // Get professor's current schedule
    $currentSchedule = [];
    $nextClass = null;
    $currentTime = time();
    $currentDay = date('N'); // 1-7 (Monday-Sunday)
    $currentDate = date('Y-m-d');

    if ($professorId) {
        // Get today's schedule
        $scheduleQuery = $conn->prepare("
            SELECT subject, room, start_time, end_time 
            FROM professor_schedules 
            WHERE professor_id = ? AND day_id = ?
            ORDER BY start_time
        ");
        $scheduleQuery->bind_param("ii", $professorId, $currentDay);
        $scheduleQuery->execute();
        $currentSchedule = $scheduleQuery->get_result()->fetch_all(MYSQLI_ASSOC);

        // Find next class
        foreach ($currentSchedule as $class) {
            $classTime = strtotime($class['start_time']);
            if ($classTime > $currentTime) {
                $nextClass = $class;
                break;
            }
        }

        // If no more classes today, find next class in the week
        if (!$nextClass) {
            for ($i = 1; $i <= 7; $i++) {
                $checkDay = ($currentDay + $i - 1) % 7 + 1;
                $scheduleQuery->bind_param("ii", $professorId, $checkDay);
                $scheduleQuery->execute();
                $daySchedule = $scheduleQuery->get_result()->fetch_all(MYSQLI_ASSOC);

                if (!empty($daySchedule)) {
                    $nextClass = $daySchedule[0];
                    $nextClass['day_name'] = date('l', strtotime("Sunday +{$checkDay} days"));
                    break;
                }
            }
        }
    }

    // Get today's attendance status
    $todayStatus = [];
    $today = date('Y-m-d');
    $statusQuery = $conn->prepare("
    SELECT 
        status, 
        is_late,
        am_check_in, 
        pm_check_in, 
        am_check_out, 
        pm_check_out,
        date,
        work_duration,
        TIMESTAMPDIFF(MINUTE, am_check_in, am_check_out) as am_duration,
        TIMESTAMPDIFF(MINUTE, pm_check_in, pm_check_out) as pm_duration
    FROM attendance 
    WHERE professor_id = ? AND date = ?
    ORDER BY id DESC
    LIMIT 1
");
    $statusQuery->bind_param("is", $professorId, $today);
    $statusQuery->execute();
    $todayStatus = $statusQuery->get_result()->fetch_assoc();

    // Get department statistics
    $deptStats = [];
    $statsQuery = $conn->prepare("
        SELECT 
            COUNT(*) as total_professors,
            SUM(CASE WHEN a.am_check_in IS NOT NULL THEN 1 ELSE 0 END) as am_present,
            SUM(CASE WHEN a.pm_check_in IS NOT NULL THEN 1 ELSE 0 END) as pm_present,
            SUM(CASE WHEN a.am_check_in IS NOT NULL AND a.is_late = 1 THEN 1 ELSE 0 END) as am_late,
            SUM(CASE WHEN a.pm_check_in IS NOT NULL AND a.is_late = 1 THEN 1 ELSE 0 END) as pm_late
        FROM professors p
        LEFT JOIN attendance a ON a.professor_id = p.id AND a.date = ?
        WHERE p.department = ?
    ");
    $deptName = $professor['department'] ?? 'Computer Studies Department';
    $statsQuery->bind_param("ss", $today, $deptName);
    $statsQuery->execute();
    $deptStats = $statsQuery->get_result()->fetch_assoc();

    // Get system settings
    $settingsQuery = $conn->query("SELECT * FROM settings LIMIT 1");
    $systemSettings = $settingsQuery->fetch_assoc();
    $amCutoff = $systemSettings['am_cutoff'] ?? '09:00:00';
    $pmCutoff = $systemSettings['pm_cutoff'] ?? '17:00:00';
    $pmLateCutoff = $systemSettings['pm_late_cutoff'] ?? '13:00:00';

    // Get professor's recent attendance history (last 5 days)
    $historyQuery = $conn->prepare("
        SELECT date, status, work_duration 
        FROM attendance 
        WHERE professor_id = ? 
        ORDER BY date DESC 
        LIMIT 5
    ");
    $historyQuery->bind_param("i", $professorId);
    $historyQuery->execute();
    $attendanceHistory = $historyQuery->get_result()->fetch_all(MYSQLI_ASSOC);

    // Enhanced intent recognition with synonyms
    $intents = [
        // Time-related queries
        'time in|check in|clock in|punch in|sign in|how to time in|how do i time in' => function () use ($todayStatus, $currentSession, $isLate, $amCutoff, $pmLateCutoff) {
            if ($currentSession === 'AM' && $todayStatus['am_check_in']) {
                $time = date('h:i A', strtotime($todayStatus['am_check_in']));
                $status = $todayStatus['is_late'] ? "You were <span class='badge bg-warning'>Late</span>" : "You were <span class='badge bg-success'>On Time</span>";
                return "You already timed in for the morning session at {$time}. {$status}";
            } elseif ($currentSession === 'PM' && $todayStatus['pm_check_in']) {
                $time = date('h:i A', strtotime($todayStatus['pm_check_in']));
                $status = $todayStatus['is_late'] ? "You were <span class='badge bg-warning'>Late</span>" : "You were <span class='badge bg-success'>On Time</span>";
                return "You already timed in for the afternoon session at {$time}. {$status}";
            }

            $cutoffTime = $currentSession === 'AM' ? date('h:i A', strtotime($amCutoff)) : date('h:i A', strtotime($pmLateCutoff));
            $instructions = "To time in, simply use the system interface. The cutoff time for being on time is {$cutoffTime}.";

            if ($isLate) {
                $instructions .= " <span class='badge bg-warning'>Note: You're currently marked as late</span>";
            }

            return $instructions;
        },

        'time out|check out|clock out|punch out|sign out|how to time out|how do i time out' => function () use ($todayStatus, $currentSession) {
            if ($currentSession === 'AM' && $todayStatus['am_check_out']) {
                $time = date('h:i A', strtotime($todayStatus['am_check_out']));
                return "You already timed out from the morning session at {$time}.";
            } elseif ($currentSession === 'PM' && $todayStatus['pm_check_out']) {
                $time = date('h:i A', strtotime($todayStatus['pm_check_out']));
                return "You already timed out from the afternoon session at {$time}.";
            }

            $instructions = "To time out, click the 'Time Out' button on your dashboard or in the navigation menu.";

            // Check if they've timed in first
            if ($currentSession === 'AM' && !$todayStatus['am_check_in']) {
                $instructions .= "<br><span class='badge bg-danger'>Warning: You haven't timed in for the morning session yet!</span>";
            } elseif ($currentSession === 'PM' && !$todayStatus['pm_check_in']) {
                $instructions .= "<br><span class='badge bg-danger'>Warning: You haven't timed in for the afternoon session yet!</span>";
            }

            return $instructions;
        },

        // Schedule queries
        'schedule|classes|timetable|teaching hours|my schedule|today\'?s? schedule' => function () use ($currentSchedule, $professor, $currentDay) {
            if (empty($currentSchedule)) {
                return "You have no classes scheduled for today.";
            }

            $dayName = date('l', strtotime("Sunday +{$currentDay} days"));
            $output = "<strong>{$dayName}'s Schedule for {$professor['name']}:</strong><br><ul class='list-unstyled'>";

            foreach ($currentSchedule as $class) {
                $start = date('h:i A', strtotime($class['start_time']));
                $end = date('h:i A', strtotime($class['end_time']));
                $output .= "<li><i class='fas fa-calendar-check me-2'></i> <strong>{$class['subject']}</strong> in {$class['room']} from {$start} to {$end}</li>";
            }
            $output .= "</ul>";

            // Add current time indicator
            $currentTime = date('h:i A');
            $output .= "<div class='mt-2 small text-muted'><i class='fas fa-clock me-1'></i> Current time: {$currentTime}</div>";

            return $output;
        },

        'next class|upcoming class|when is my next class|what\'?s? my next class|do i have class next' => function () use ($nextClass, $currentTime, $currentSchedule) {
            if (!$nextClass) {
                if (empty($currentSchedule)) {
                    return "You have no more classes scheduled today and no upcoming classes in your schedule.";
                } else {
                    return "You have no more classes scheduled today. Your next class will be at the start of your next scheduled teaching day.";
                }
            }

            if (isset($nextClass['day_name'])) {
                // Next class is on another day
                $day = $nextClass['day_name'];
                $time = date('h:i A', strtotime($nextClass['start_time']));
                $room = $nextClass['room'] ?? 'unspecified room';

                return "Your next class is <strong>{$nextClass['subject']}</strong> in {$room} on {$day} at {$time}.";
            } else {
                // Next class is today
                $time = date('h:i A', strtotime($nextClass['start_time']));
                $minutes = ceil((strtotime($nextClass['start_time']) - $currentTime) / 60);
                $room = $nextClass['room'] ?? 'unspecified room';

                if ($minutes <= 0) {
                    return "Your next class <strong>{$nextClass['subject']}</strong> in {$room} should have started at {$time}.";
                } elseif ($minutes < 60) {
                    return "Your next class <strong>{$nextClass['subject']}</strong> in {$room} starts at {$time} (in {$minutes} minutes).";
                } else {
                    $hours = floor($minutes / 60);
                    $remaining = $minutes % 60;
                    return "Your next class <strong>{$nextClass['subject']}</strong> in {$room} starts at {$time} (in {$hours} hours and {$remaining} minutes).";
                }
            }
        },

        // Classroom/location queries
        'where is my next class|what room is my next class|location of next class|where\'?s? my next class' => function () use ($nextClass) {
            if (!$nextClass) {
                return "You don't have any upcoming classes scheduled.";
            }

            $room = $nextClass['room'] ?? 'an unspecified room';
            $time = date('h:i A', strtotime($nextClass['start_time']));

            if (isset($nextClass['day_name'])) {
                return "Your next class <strong>{$nextClass['subject']}</strong> is in {$room} on {$nextClass['day_name']} at {$time}.";
            } else {
                return "Your next class <strong>{$nextClass['subject']}</strong> is in {$room} today at {$time}.";
            }
        },

        'where is my class|what room is my class|location of class|where\'?s? my class' => function () use ($currentSchedule) {
            if (empty($currentSchedule)) {
                return "You don't have any classes scheduled today.";
            }

            $output = "<strong>Today's Class Locations:</strong><br><ul class='list-unstyled'>";
            foreach ($currentSchedule as $class) {
                $room = $class['room'] ?? 'unspecified room';
                $time = date('h:i A', strtotime($class['start_time']));
                $output .= "<li><i class='fas fa-door-open me-2'></i> <strong>{$class['subject']}</strong> at {$time} is in {$room}</li>";
            }
            $output .= "</ul>";
            return $output;
        },

        // Late status queries
        'am i late|am i late for my next class|am i late for any classes|will i be late' => function () use ($nextClass, $currentTime, $isLate, $todayStatus, $currentSession) {
            // Check if currently late for session
            if ($isLate) {
                $session = $currentSession === 'AM' ? 'morning' : 'afternoon';
                return "<span class='badge bg-warning'>Yes</span>, you're currently marked as late for the {$session} session.";
            }

            // Check next class
            if ($nextClass) {
                $classTime = strtotime($nextClass['start_time']);
                $minutes = ceil(($classTime - $currentTime) / 60);

                if ($minutes <= 0) {
                    return "<span class='badge bg-danger'>Yes</span>, your next class should have already started at " . date('h:i A', $classTime) . ".";
                } elseif ($minutes < 15) {
                    return "<span class='badge bg-warning'>You might be late</span> for your next class at " . date('h:i A', $classTime) . " (in {$minutes} minutes).";
                } else {
                    return "<span class='badge bg-success'>No</span>, you're not late for your next class at " . date('h:i A', $classTime) . ".";
                }
            }

            return "You don't have any upcoming classes to be late for.";
        },

        // Department queries
        'department|colleagues|who is present|attendance stats|department stats|department attendance' => function () use ($deptStats, $deptName, $today) {
            $total = $deptStats['total_professors'] ?? 0;
            $amPresent = $deptStats['am_present'] ?? 0;
            $pmPresent = $deptStats['pm_present'] ?? 0;
            $amLate = $deptStats['am_late'] ?? 0;
            $pmLate = $deptStats['pm_late'] ?? 0;
            $amOnTime = $amPresent - $amLate;
            $pmOnTime = $pmPresent - $pmLate;

            $date = date('F j, Y', strtotime($today));

            return "<strong>{$deptName} Attendance for {$date}:</strong><br>
                   <i class='fas fa-users me-2'></i> Total Professors: {$total}<br>
                   <div class='ms-3 mt-1'>
                       <i class='fas fa-sun me-2'></i> Morning Present: {$amPresent}<br>
                       <div class='ms-3'>
                           <i class='fas fa-check-circle text-success me-2'></i> On Time: {$amOnTime}<br>
                           <i class='fas fa-clock text-warning me-2'></i> Late: {$amLate}<br>
                       </div>
                       <i class='fas fa-moon me-2'></i> Afternoon Present: {$pmPresent}<br>
                       <div class='ms-3'>
                           <i class='fas fa-check-circle text-success me-2'></i> On Time: {$pmOnTime}<br>
                           <i class='fas fa-clock text-warning me-2'></i> Late: {$pmLate}<br>
                       </div>
                   </div>";
        },

        // Attendance history
        'my history|attendance history|my attendance history|past attendance|my records' => function () use ($attendanceHistory) {
            if (empty($attendanceHistory)) {
                return "You don't have any attendance records yet.";
            }

            $output = "<strong>Your Recent Attendance History:</strong><br><ul class='list-unstyled'>";

            foreach ($attendanceHistory as $record) {
                $date = date('M j, Y', strtotime($record['date']));
                $statusClass = '';
                switch ($record['status']) {
                    case 'present':
                        $statusClass = 'bg-success';
                        break;
                    case 'half-day':
                        $statusClass = 'bg-warning';
                        break;
                    case 'absent':
                        $statusClass = 'bg-danger';
                        break;
                    default:
                        $statusClass = 'bg-secondary';
                }

                $duration = $record['work_duration'] ?? '00:00:00';
                list($hours, $minutes, $seconds) = explode(':', $duration);

                $output .= "<li class='mb-2'>
                    <i class='fas fa-calendar-day me-2'></i> {$date} 
                    <span class='badge {$statusClass}'>" . ucfirst($record['status']) . "</span>
                    <div class='ms-4 small'>Worked: {$hours} hrs {$minutes} mins</div>
                </li>";
            }

            $output .= "</ul>";
            $output .= "<div class='small text-muted'>Showing last " . count($attendanceHistory) . " records</div>";

            return $output;
        },

        // System queries
        'cutoff|deadline|late policy|when is cutoff|late time|cutoff time' => function () use ($amCutoff, $pmCutoff, $pmLateCutoff) {
            $am = date('h:i A', strtotime($amCutoff));
            $pm = date('h:i A', strtotime($pmCutoff));
            $pmLate = date('h:i A', strtotime($pmLateCutoff));

            return "<strong>System Cutoff Times:</strong><br>
                   <i class='fas fa-sun me-2'></i> Morning session on-time cutoff: {$am}<br>
                   <i class='fas fa-moon me-2'></i> Afternoon session on-time cutoff: {$pmLate}<br>
                   <i class='fas fa-clock me-2'></i> Afternoon session final cutoff: {$pm}<br>
                   Arriving after the on-time cutoff will be marked as late.";
        },

        // System information
        'what is this system|about this system|system info|system information|what\'?s? this system for' => function () {
            return "<strong>About the Attendance System:</strong><br>
                   This system tracks professor attendance and schedules for the university. Key features include:
                   <ul>
                       <li>Time in/out recording for AM and PM sessions</li>
                       <li>Class schedule management</li>
                       <li>Attendance reporting and statistics</li>
                       <li>Department-wide attendance tracking</li>
                   </ul>
                   You can ask me about your schedule, attendance status, or department statistics.";
        },

        // Help queries
        'help|support|what can you do|assistance|how to use|commands' => function () {
            return "<strong>I can help with:</strong><br>
           <div class='row'>
               <div class='col-md-6'>
                   <strong><i class='fas fa-calendar me-2'></i> Schedule:</strong>
                   <ul>
                       <li>What's my schedule today?</li>
                       <li>When is my next class?</li>
                       <li>What room is my class in?</li>
                   </ul>
               </div>
               <div class='col-md-6'>
                   <strong><i class='fas fa-user-clock me-2'></i> Attendance:</strong>
                   <ul>
                       <li>How do I time in/out?</li>
                       <li>Am I late for my next class?</li>
                   </ul>
               </div>
               <div class='col-md-6'>
                   <strong><i class='fas fa-info-circle me-2'></i> System:</strong>
                   <ul>
                       <li>What are the cutoff times?</li>
                       <li>What is this system for?</li>
                   </ul>
               </div>
           </div>";
        },

        // Greetings
        'hello|hi|hey|greetings|good morning|good afternoon|good evening' => function () use ($professorName, $currentSession) {
            $greeting = '';
            $hour = date('G');

            if ($hour < 12) {
                $greeting = 'Good morning';
            } elseif ($hour < 17) {
                $greeting = 'Good afternoon';
            } else {
                $greeting = 'Good evening';
            }

            return "{$greeting}, {$professorName}! I'm your attendance assistant. How can I help you with your {$currentSession} session today?";
        },

        // Default response
        'default' => function () {
            return "I'm not sure I understand. Try asking about:<br>
                   - Your schedule ('What's my schedule today?')<br>
                   - Today's attendance ('What's my current status?')<br>
                   - Next class ('When is my next class?')<br>
                   - Department stats ('Department attendance')<br>
                   Or type 'help' to see all available commands.";
        }
    ];

    // Check for matching intent (with synonyms)
    $matched = false;
    foreach ($intents as $keywords => $handler) {
        $keywords = explode('|', $keywords);
        foreach ($keywords as $keyword) {
            if (preg_match("/\b" . preg_quote($keyword, '/') . "\b/i", $userMessage)) {
                $response = $handler();
                $matched = true;
                break 2;
            }
        }
    }

    if (!$matched) {
        $response = $intents['default']();
    }

    // Log the interaction
    if ($professorId) {
        $log = $conn->prepare("
            INSERT INTO logs (action, user, timestamp) 
            VALUES (?, ?, NOW())
        ");
        $logMessage = "Chatbot interaction: " . substr($userMessage, 0, 100);
        $log->bind_param("ss", $logMessage, $professorName);
        $log->execute();
    }

    echo json_encode(['response' => $response]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Automated Time In/Out</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Chatbot Styles */
        #chatbot-container {
            position: fixed;
            bottom: 80px;
            right: 20px;
            width: 380px;
            max-width: 90%;
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
            flex-direction: column;
            max-height: 70vh;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }

        #chatbot-header {
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            color: white;
            padding: 15px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        #chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            background: #f8f9fa;
        }

        .message {
            margin-bottom: 15px;
            max-width: 85%;
            padding: 12px 16px;
            border-radius: 18px;
            line-height: 1.5;
            position: relative;
            font-size: 0.95rem;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .user-message {
            background: #4361ee;
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 5px;
        }

        .bot-message {
            background: #ffffff;
            color: #333;
            margin-right: auto;
            border-bottom-left-radius: 5px;
            border: 1px solid #e0e0e0;
        }

        .bot-message strong {
            color: #4361ee;
        }

        .bot-message .badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            margin-left: 5px;
        }

        #chat-input-container {
            padding: 12px;
            border-top: 1px solid #e0e0e0;
            background: white;
            border-radius: 0 0 15px 15px;
        }

        #chat-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            color: white;
            border: none;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
            cursor: pointer;
            z-index: 1001;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            transition: all 0.3s ease;
        }

        #chat-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 15px rgba(67, 97, 238, 0.4);
        }

        .typing-indicator {
            display: inline-block;
            padding: 12px 16px;
            background: #ffffff;
            border-radius: 18px;
            margin-bottom: 15px;
            border: 1px solid #e0e0e0;
        }

        .typing-indicator span {
            height: 8px;
            width: 8px;
            background: #6c757d;
            border-radius: 50%;
            display: inline-block;
            margin: 0 3px;
            animation: typing 1s infinite ease-in-out;
        }

        .timestamp {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 8px;
            text-align: right;
        }

        .quick-reply {
            display: inline-block;
            margin: 5px;
            padding: 8px 12px;
            background: #e9ecef;
            border-radius: 15px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            border: 1px solid #dee2e6;
        }

        .quick-reply:hover {
            background: #dee2e6;
            transform: translateY(-2px);
        }

        /* Animation for new messages */
        @keyframes messageIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message {
            animation: messageIn 0.3s ease-out;
        }

        /* Scrollbar styling */
        #chat-messages::-webkit-scrollbar {
            width: 6px;
        }

        #chat-messages::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        #chat-messages::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        #chat-messages::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
    </style>
</head>

<body>

    <?php include '../includes/navbar.php'; ?>

    <!-- Time Out Modal -->
    <div class="modal fade" id="timeOutModal" tabindex="-1" aria-labelledby="timeOutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header text-white" style="background-color: #FF6600;">
                    <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2"></i> <span id="timeOutTitle"><?php echo $currentSession; ?> Time Out</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($professorName)): ?>
                        <div class="professor-info mb-4 text-center">
                            <h4>Professor:</h4>
                            <h3 class="fw-bold"><?php echo htmlspecialchars($professorName); ?></h3>
                            <h5 class="text-muted"><?php echo $currentSession; ?> Session</h5>
                        </div>

                        <div class="confirmation-message text-center mb-3">
                            <p>Are you sure you want to time out from the <?php echo $currentSession; ?> session?</p>
                        </div>

                        <div class="d-grid gap-2">
                            <button id="confirm-timeout" class="btn btn-danger">
                                <i class="fas fa-sign-out-alt me-2"></i> Confirm Time Out
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true" data-refresh-delay="3000">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <div class="mb-3">
                        <i class="fas fa-check-circle text-success" style="font-size: 5rem;"></i>
                    </div>
                    <h3 class="mb-3" id="success-message">Success!</h3>
                    <p id="success-details" class="mb-0"></p>
                    <button class="btn btn-success mt-3" id="success-ok-btn">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <div class="mb-3">
                        <i class="fas fa-times-circle text-danger" style="font-size: 5rem;"></i>
                    </div>
                    <h3 class="mb-3" id="error-title">Error</h3>
                    <p id="error-message" class="mb-0"></p>
                    <button class="btn btn-danger mt-3" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Welcome Message -->
    <?php if (!empty($professorName)): ?>
        <div class="welcome-message">
            <i class="fas fa-user-tie"></i> Welcome, <?php echo htmlspecialchars($professorName); ?>
            <span class="session-badge"><?php echo $currentSession; ?> Session</span>
        </div>
    <?php endif; ?>

    <!-- Sidebar Recent History -->
    <aside class="history-panel">
        <div class="history-header d-flex justify-content-between align-items-center p-3 text-white" style="background-color: #0077b6;">
            <h5 class="mb-0"><i class="fas fa-clock"></i> Recent History</h5>
        </div>
        <div class="history-content">
            <ul id="recent-history-list" class="list-group"></ul>
        </div>
        <button id="view-more-btn" class="btn btn-outline-secondary w-100 rounded-0">
            <span id="view-more-text">View More</span>
            <span id="refresh-spinner" class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
        </button>
    </aside>

    <!-- Main Dashboard -->
    <main class="dashboard" id="landing-page">
        <div class="date-container">
            <i class="fas fa-calendar-day"></i> <span id="current-date"></span>
            <span class="session-indicator"><?php echo $currentSession; ?> Session</span>
        </div>

        <div class="clock-container text-center">
            <h1 id="clock" class="fw-bold"></h1>
            <p class="text-muted">Current Time</p>
        </div>

        <div class="button-container text-center mt-4">
            <button id="time-out-btn" class="btn btn-lg text-white time-action-btn" style="background-color: #FF6600; border: none;" data-bs-toggle="modal" data-bs-target="#timeOutModal">
                <i class="fas fa-sign-out-alt"></i> <?php echo $currentSession; ?> Time Out
            </button>
        </div>

        <hr class="dashboard-divider">

        <!-- Attendance Statistics -->
        <section class="stats-section mt-4">
            <h3 class="fw-bold text-center"><i class="fas fa-chart-bar"></i> Attendance Overview</h3>
            <div class="stats-container d-flex justify-content-center flex-wrap mt-3">
                <div class="stat-card total-professors">
                    <h4><i class="fas fa-user-tie"></i> Total Professors</h4>
                    <h2>
                        <?php
                        $result = $conn->query("SELECT COUNT(*) AS total FROM professors WHERE status = 'active'");
                        echo $result ? $result->fetch_assoc()['total'] : 0;
                        ?>
                    </h2>
                </div>

                <div class="stat-card total-attendance">
                    <h4><i class="fas fa-user-check"></i> Today's Attendance</h4>
                    <h2>
                        <?php
                        $result = $conn->query("SELECT COUNT(DISTINCT professor_id) AS total FROM attendance WHERE checkin_date = CURDATE()");
                        echo $result ? $result->fetch_assoc()['total'] : 0;
                        ?>
                    </h2>
                </div>

                <div class="stat-card pending-checkouts">
                    <h4><i class="fas fa-clock"></i> Pending Time-Outs</h4>
                    <h2>
                        <?php
                        $result = $conn->query("SELECT COUNT(*) AS total FROM attendance WHERE checkin_date = CURDATE() AND 
                                              ((am_check_in IS NOT NULL AND am_check_out IS NULL) OR 
                                               (pm_check_in IS NOT NULL AND pm_check_out IS NULL))");
                        echo $result ? $result->fetch_assoc()['total'] : 0;
                        ?>
                    </h2>
                </div>
            </div>
        </section>
    </main>

    <!-- Advanced Chatbot Widget -->
    <div id="chatbot-container">
        <div id="chatbot-header">
            <h5 class="mb-0">Attendance Assistant</h5>
            <button id="close-chat" class="btn btn-sm btn-light">×</button>
        </div>
        <div id="chat-messages">
            <div class="bot-message message">
                Hello <?php echo htmlspecialchars($professorName ?? 'there'); ?>! I'm your attendance assistant. How can I help you today?
                <div class="timestamp"><?php echo date('h:i A'); ?></div>
                <div style="margin-top: 10px;">
                    <div class="quick-reply" data-message="What's my schedule today?">My schedule</div>
                    <div class="quick-reply" data-message="What's my next class?">Next class</div>
                    <div class="quick-reply" data-message="What's my current status?">My status</div>
                    <div class="quick-reply" data-message="How do I time out?">Time Out Help</div>
                    <div class="quick-reply" data-message="What's my schedule today?">Today's Schedule</div>
                </div>
            </div>
        </div>
        <div id="chat-input-container">
            <div class="input-group">
                <input type="text" id="user-input" class="form-control" placeholder="Type your question..." aria-label="Type your question">
                <button id="send-btn" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
            <div id="quick-replies" style="margin-top: 10px;">
                <!-- Quick replies will be added here dynamically -->
            </div>
        </div>
    </div>
    <button id="chat-toggle" title="Chat with Attendance Assistant">
        <i class="fas fa-robot"></i>
    </button>

    <!-- JavaScript at the bottom of the page -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="../assets/js/index.js"></script>


    <!-- JavaScript for Chatbot -->
    <script>
        // Global variables
        const currentSession = '<?php echo $currentSession; ?>';
        const professorId = <?php echo json_encode($professorId); ?>;
        const professorName = <?php echo json_encode($professorName); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize modals
            const timeOutModal = new bootstrap.Modal(document.getElementById('timeOutModal'));
            const successModal = new bootstrap.Modal(document.getElementById('successModal'));
            const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));

            // Time Out Confirmation
            document.getElementById('confirm-timeout')?.addEventListener('click', handleTimeOut);

            // View More button click handler
            document.getElementById('view-more-btn')?.addEventListener('click', function(e) {
                e.preventDefault();
                handleViewMore();
            });

            // Initialize clock and date
            initClockAndDate();

            // Load recent history
            loadRecentHistory();

            // Success modal OK button
            document.getElementById('success-ok-btn')?.addEventListener('click', function() {
                successModal.hide();
                setTimeout(() => location.reload(), 300);
            });

            // Initialize dropdowns
            initDropdowns();
        });

        // ========== CORE FUNCTIONS ========== //

        function initClockAndDate() {
            function update() {
                const now = new Date();

                // Update clock
                document.getElementById('clock').textContent = now.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true
                });

                // Update date
                document.getElementById('current-date').textContent = now.toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            }
            update();
            setInterval(update, 1000);
        }

        async function loadRecentHistory() {
            try {
                const response = await fetch('../api/get-recent-history.php');
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                const data = await response.json();
                const historyList = document.getElementById('recent-history-list');
                historyList.innerHTML = ''; // Clear existing content

                if (data && Array.isArray(data)) {
                    if (data.length > 0) {
                        data.forEach(item => {
                            if (!item || typeof item !== 'object') return;

                            const professorName = item.professor_name || 'Unknown Professor';
                            const sessionType = item.session_type || 'Unknown Session';
                            const action = item.action || 'Unknown Action';

                            const time = item.timestamp ? formatTime(item.timestamp) :
                                (item.time ? formatTime(item.time) : '');
                            const date = item.timestamp ? formatDate(item.timestamp) :
                                (item.date ? formatDate(item.date) : '');

                            if (professorName || sessionType || action) {
                                const li = document.createElement('li');
                                li.className = 'list-group-item';
                                li.innerHTML = `
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong>${professorName}</strong>
                                        <div class="text-muted small">
                                            ${sessionType} - ${action}
                                        </div>
                                    </div>
                                    ${time || date ? `
                                    <div class="text-end">
                                        ${time ? `<small class="text-muted">${time}</small>` : ''}
                                        ${date ? `<br><small class="text-muted">${date}</small>` : ''}
                                    </div>
                                    ` : ''}
                                </div>
                            `;
                                historyList.appendChild(li);
                            }
                        });

                        if (historyList.children.length === 0) {
                            historyList.innerHTML = '<li class="list-group-item text-center text-muted">No valid records found</li>';
                        }
                    } else {
                        historyList.innerHTML = '<li class="list-group-item text-center text-muted">No records found</li>';
                    }
                } else {
                    throw new Error('Invalid data format received from server');
                }
            } catch (error) {
                console.error('Error loading history:', error);
                const historyList = document.getElementById('recent-history-list');
                historyList.innerHTML = '<li class="list-group-item text-center text-muted">Error loading history</li>';
            }
        }

        async function handleViewMore() {
            const btn = document.getElementById('view-more-btn');
            const spinner = document.getElementById('refresh-spinner');
            const viewMoreText = document.getElementById('view-more-text');

            try {
                btn.disabled = true;
                viewMoreText.textContent = 'Loading...';
                spinner.classList.remove('d-none');

                await loadRecentHistory();
            } catch (error) {
                console.error('Error loading more history:', error);
            } finally {
                btn.disabled = false;
                viewMoreText.textContent = 'View More';
                spinner.classList.add('d-none');
            }
        }

        async function handleTimeOut() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing';

            try {
                const response = await fetch('../api/time-out.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        professor_id: professorId,
                        session: currentSession
                    })
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'Time out failed');
                }

                if (data.status === 'success') {
                    showSuccessModal(
                        `${currentSession} Time Out Successful`,
                        `You have been successfully checked out from ${currentSession} session at ${new Date().toLocaleTimeString()}.`
                    );
                    setTimeout(() => {
                        const timeOutModal = bootstrap.Modal.getInstance(document.getElementById('timeOutModal'));
                        if (timeOutModal) timeOutModal.hide();
                    }, 2000);

                    // Update the attendance record with work duration
                    await updateWorkDuration();
                } else {
                    throw new Error(data.message || 'Time out failed');
                }
            } catch (error) {
                showErrorModal('Time Out Failed', error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sign-out-alt me-2"></i> Confirm Time Out';
            }
        }

        async function updateWorkDuration() {
            try {
                const response = await fetch('../api/calculate-work-duration.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        professor_id: professorId,
                        date: new Date().toISOString().split('T')[0]
                    })
                });

                const data = await response.json();
                if (!response.ok || data.status !== 'success') {
                    console.error('Failed to update work duration:', data.message);
                }
            } catch (error) {
                console.error('Error updating work duration:', error);
            }
        }

        function initDropdowns() {
            // Initialize dropdowns with proper configuration
            const notificationToggle = document.getElementById('notificationDropdown');
            const profileToggle = document.getElementById('profileDropdown');

            if (!notificationToggle || !profileToggle) return;

            // Initialize dropdown instances
            const notificationDropdown = new bootstrap.Dropdown(notificationToggle, {
                autoClose: true,
                boundary: 'viewport' // Prevents dropdown from being cut off
            });

            const profileDropdown = new bootstrap.Dropdown(profileToggle, {
                autoClose: true,
                boundary: 'viewport'
            });

            // Enhanced click handler for notification dropdown
            notificationToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Close profile dropdown if open
                profileDropdown.hide();

                // Toggle notification dropdown
                notificationDropdown.toggle();
            });

            // Enhanced click handler for profile dropdown
            profileToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Close notification dropdown if open
                notificationDropdown.hide();

                // Toggle profile dropdown
                profileDropdown.toggle();
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationToggle.contains(e.target)) {
                    notificationDropdown.hide();
                }
                if (!profileToggle.contains(e.target)) {
                    profileDropdown.hide();
                }
            });

            // Close dropdowns when scrolling
            window.addEventListener('scroll', function() {
                notificationDropdown.hide();
                profileDropdown.hide();
            });

            // Close dropdowns when clicking on items
            document.querySelectorAll('.dropdown-menu a').forEach(item => {
                item.addEventListener('click', function() {
                    notificationDropdown.hide();
                    profileDropdown.hide();
                });
            });
        }

        // ========== HELPER FUNCTIONS ========== //

        function showSuccessModal(title, message) {
            document.getElementById('success-message').textContent = title;
            document.getElementById('success-details').textContent = message;
            const successModal = new bootstrap.Modal(document.getElementById('successModal'));
            successModal.show();
        }

        function showErrorModal(title, message) {
            document.getElementById('error-title').textContent = title;
            document.getElementById('error-message').textContent = message;
            const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
            errorModal.show();
        }

        function formatTime(timeString) {
            if (!timeString) return '';

            try {
                if (/^\d{2}:\d{2}:\d{2}$/.test(timeString)) {
                    const [hours, minutes] = timeString.split(':');
                    return `${hours}:${minutes}`;
                }

                const time = new Date(timeString);
                if (isNaN(time.getTime())) {
                    return timeString;
                }
                return time.toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return timeString;
            }
        }

        function formatDate(dateString) {
            if (!dateString) return '';

            try {
                if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
                    const [year, month, day] = dateString.split('-');
                    return `${month}/${day}/${year}`;
                }

                const date = new Date(dateString);
                if (isNaN(date.getTime())) {
                    return dateString;
                }
                return date.toLocaleDateString();
            } catch (e) {
                return dateString;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const chatContainer = document.getElementById('chatbot-container');
            const chatToggle = document.getElementById('chat-toggle');
            const closeChat = document.getElementById('close-chat');
            const chatMessages = document.getElementById('chat-messages');
            const userInput = document.getElementById('user-input');
            const sendBtn = document.getElementById('send-btn');
            const quickReplies = document.getElementById('quick-replies');

            // Sample quick replies based on context
            const defaultQuickReplies = [
                "What's my schedule today?",
                "When is my next class?",
                "How do I time out?",
                "What are the cutoff times?"
            ];

            // Toggle chat visibility
            chatToggle.addEventListener('click', () => {
                chatContainer.style.display = chatContainer.style.display === 'flex' ? 'none' : 'flex';
                if (chatContainer.style.display === 'flex') {
                    userInput.focus();
                    // Add welcome message if first interaction
                    if (chatMessages.children.length <= 1) {
                        addWelcomeMessage();
                    }
                }
            });

            closeChat.addEventListener('click', () => {
                chatContainer.style.display = 'none';
            });

            // Add message to chat with enhanced formatting
            function addMessage(text, isUser) {
                const messageDiv = document.createElement('div');
                messageDiv.className = `message ${isUser ? 'user-message' : 'bot-message'}`;

                // Format links and lists in bot responses
                if (!isUser) {
                    text = text.replace(/<strong>(.*?)<\/strong>/g, '<strong>$1</strong>');
                    text = text.replace(/<ul>(.*?)<\/ul>/gs, '<ul style="margin-left: 20px;">$1</ul>');
                    text = text.replace(/<li>(.*?)<\/li>/gs, '<li style="list-style-type: disc; margin-bottom: 5px;">$1</li>');
                }

                messageDiv.innerHTML = text;

                // Add timestamp
                const timestamp = document.createElement('div');
                timestamp.className = 'timestamp';
                timestamp.textContent = new Date().toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                messageDiv.appendChild(timestamp);

                chatMessages.appendChild(messageDiv);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            // Show typing indicator
            function showTyping() {
                const typingDiv = document.createElement('div');
                typingDiv.className = 'typing-indicator';
                typingDiv.innerHTML = '<span></span><span></span><span></span>';
                typingDiv.id = 'typing-indicator';
                chatMessages.appendChild(typingDiv);
                chatMessages.scrollTop = chatMessages.scrollHeight;
                return typingDiv;
            }

            // Hide typing indicator
            function hideTyping() {
                const typing = document.getElementById('typing-indicator');
                if (typing) typing.remove();
            }

            // Process user message with enhanced error handling
            function processUserMessage(message) {
                if (!message.trim()) return;

                addMessage(message, true);
                userInput.value = '';

                const typing = showTyping();

                // Send to server
                fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `chat_message=${encodeURIComponent(message)}`
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        hideTyping();
                        addMessage(data.response, false);

                        // Update quick replies based on context
                        updateQuickReplies(message, data.response);
                    })
                    .catch(error => {
                        hideTyping();
                        addMessage("Sorry, I encountered an error processing your request. Please try again.", false);
                        console.error('Error:', error);
                    });
            }

            // Update quick replies based on context
            function updateQuickReplies(userMessage, botResponse) {
                quickReplies.innerHTML = '';
                let replies = [];

                if (userMessage.includes('schedule') || userMessage.includes('class')) {
                    replies = [
                        "When is my next class?",
                        "What room is my class in?",
                        "Am I late for any classes?"
                    ];
                } else if (userMessage.includes('time in') || userMessage.includes('time out')) {
                    replies = [
                        "How do I time in?",
                        "How do I time out?",
                        "What's my schedule today?"
                    ];
                } else if (userMessage.includes('department') || userMessage.includes('colleagues')) {
                    replies = [
                        "Who is present today?",
                        "What's my schedule today?",
                        "System cutoff times",
                        "Help with time in/out"
                    ];
                } else {
                    replies = defaultQuickReplies;
                }

                replies.forEach(reply => {
                    const btn = document.createElement('div');
                    btn.className = 'quick-reply';
                    btn.textContent = reply;
                    btn.addEventListener('click', () => {
                        processUserMessage(reply);
                    });
                    quickReplies.appendChild(btn);
                });
            }

            // Send message on button click
            sendBtn.addEventListener('click', () => {
                processUserMessage(userInput.value);
            });

            // Send message on Enter key
            userInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    processUserMessage(userInput.value);
                }
            });

            // Quick reply buttons in messages
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('quick-reply') && e.target.dataset.message) {
                    processUserMessage(e.target.dataset.message);
                }
            });

            // Initialize quick replies
            updateQuickReplies('', '');

            // Auto-open for first-time users
            if (!localStorage.getItem('chatbotShown')) {
                setTimeout(() => {
                    chatContainer.style.display = 'flex';
                    addWelcomeMessage();
                    localStorage.setItem('chatbotShown', 'true');
                }, 3000);
            }

            // Proactive notification for upcoming classes
            if (professorId) {
                // Check if there's a class starting soon (within 15 minutes)
                const now = new Date();
                const currentHour = now.getHours();
                const currentMinute = now.getMinutes();

                // Only show proactive notifications during working hours
                if (currentHour >= 7 && currentHour < 18) {
                    setTimeout(() => {
                        fetch('', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: 'chat_message=next class'
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.response.includes('minutes') &&
                                    !data.response.includes('no upcoming') &&
                                    chatContainer.style.display !== 'flex') {

                                    // Show notification badge
                                    const notificationBadge = document.createElement('span');
                                    notificationBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                                    notificationBadge.style.fontSize = '0.6rem';
                                    notificationBadge.textContent = '!';
                                    notificationBadge.id = 'chat-notification-badge';
                                    chatToggle.appendChild(notificationBadge);

                                    // Make badge pulse for attention
                                    notificationBadge.style.animation = 'pulse 1.5s infinite';

                                    // Add CSS for pulse animation
                                    const style = document.createElement('style');
                                    style.textContent = `
                            @keyframes pulse {
                                0% { transform: translate(-50%, -50%) scale(1); }
                                50% { transform: translate(-50%, -50%) scale(1.2); }
                                100% { transform: translate(-50%, -50%) scale(1); }
                            }
                        `;
                                    document.head.appendChild(style);
                                }
                            });
                    }, 5000); // Check after 5 seconds
                }
            }
        });
    </script>
</body>

</html>