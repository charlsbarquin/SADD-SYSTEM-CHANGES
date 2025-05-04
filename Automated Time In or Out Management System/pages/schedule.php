<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Authentication check
if (!isset($_SESSION['professor_logged_in']) || !isset($_SESSION['professor_id'])) {
    header('Location: login.php');
    exit;
}

$professor_id = $_SESSION['professor_id'];
$success = '';
$error = '';

// Get professor details
$professor_stmt = $conn->prepare("SELECT * FROM professors WHERE id = ?");
$professor_stmt->bind_param('i', $professor_id);
$professor_stmt->execute();
$professor = $professor_stmt->get_result()->fetch_assoc();

if (!$professor) {
    header('Location: login.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();

        // Delete existing schedules for this professor
        $delete_stmt = $conn->prepare("DELETE FROM professor_schedules WHERE professor_id = ?");
        $delete_stmt->bind_param("i", $professor_id);
        $delete_stmt->execute();

        // Insert new schedules
        if (isset($_POST['schedules']) && is_array($_POST['schedules'])) {
            $insert_stmt = $conn->prepare("INSERT INTO professor_schedules 
                                         (professor_id, day_id, start_time, end_time, subject, room) 
                                         VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($_POST['schedules'] as $day_id => $day_schedules) {
                foreach ($day_schedules as $schedule) {
                    if (!empty($schedule['start_time']) && !empty($schedule['end_time'])) {
                        $subject = $schedule['subject'] ?? '';
                        $room = $schedule['room'] ?? '';

                        $insert_stmt->bind_param(
                            "iissss",
                            $professor_id,
                            $day_id,
                            $schedule['start_time'],
                            $schedule['end_time'],
                            $subject,
                            $room
                        );
                        $insert_stmt->execute();
                    }
                }
            }
        }

        $conn->commit();
        $success = 'Schedule updated successfully!';

        // Log the action to the database
        $action = 'Professor updated schedule';
        $log_stmt = $conn->prepare("INSERT INTO logs (action, user, timestamp) VALUES (?, ?, NOW())");
        $log_stmt->bind_param("ss", $action, $professor['name']);
        $log_stmt->execute();

        // Refresh the schedule data after update
        $schedule = [];
        $result = $conn->query("SELECT * FROM professor_schedules WHERE professor_id = $professor_id ORDER BY day_id, start_time");
        while ($row = $result->fetch_assoc()) {
            $schedule[$row['day_id']][] = $row;
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error = 'Error updating schedule: ' . $e->getMessage();

        // Log the error
        $action = 'Schedule update failed: ' . $e->getMessage();
        $log_stmt = $conn->prepare("INSERT INTO logs (action, user, timestamp) VALUES (?, ?, NOW())");
        $log_stmt->bind_param("ss", $action, $professor['name']);
        $log_stmt->execute();
    }
}

// Get current schedule if not set from form submission
if (!isset($schedule)) {
    $schedule = [];
    $result = $conn->query("SELECT * FROM professor_schedules WHERE professor_id = $professor_id ORDER BY day_id, start_time");
    while ($row = $result->fetch_assoc()) {
        $schedule[$row['day_id']][] = $row;
    }
}

// Get days of week
$days = $conn->query("SELECT * FROM schedule_days ORDER BY id")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule | University Portal</title>

    <!-- Bootstrap & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">

    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #eef2ff;
            --secondary: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --dark: #1e293b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7fb;
            color: var(--dark);
        }

        .main-container {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
            margin: 20px auto;
            padding: 25px;
        }

        .page-header {
            margin-bottom: 30px;
            padding: 20px;
            background-color: var(--primary-light);
            border-radius: 8px;
            text-align: center;
        }

        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .professor-card {
            border-left: 4px solid var(--primary);
            transition: all 0.3s;
        }

        .day-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            margin-bottom: 20px;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .day-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .day-header {
            background-color: var(--primary-light);
            color: var(--dark);
            padding: 12px 15px;
            font-weight: 600;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .day-empty .day-header {
            background-color: #f8f9fa;
            color: var(--secondary);
        }

        .schedule-item {
            padding: 15px;
            border-bottom: 1px solid var(--border);
        }

        .time-input {
            max-width: 120px;
        }

        .add-schedule-btn {
            margin-top: 10px;
            padding: 10px;
            background-color: var(--light);
            border-top: 1px solid var(--border);
        }

        .remove-schedule-btn {
            color: var(--danger);
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            font-size: 1.1rem;
        }

        .submit-btn {
            background-color: var(--primary);
            border: none;
            padding: 12px 30px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .submit-btn:hover {
            background-color: #3a56d4;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        /* Enhanced Schedule Table Styles */
        .schedule-table-container {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .schedule-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .schedule-table th {
            background-color: var(--primary);
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .schedule-table td {
            padding: 12px;
            border: 1px solid #f0f0f0;
            text-align: center;
            vertical-align: middle;
            position: relative;
        }

        .time-header {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--dark);
        }

        .time-slot {
            background-color: #e9f7fe;
            border-radius: 6px;
            padding: 10px;
            margin: 4px 0;
            font-size: 0.85rem;
            border-left: 4px solid var(--primary);
            transition: all 0.2s ease;
        }

        .time-slot:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .time-slot strong {
            display: block;
            margin-bottom: 3px;
            color: var(--primary);
            font-size: 0.9rem;
        }

        .time-slot-continue {
            background-color: #f0f7ff;
            border-radius: 6px;
            padding: 10px;
            margin: 4px 0;
            font-size: 0.85rem;
            text-align: center;
            color: #6c757d;
        }

        .time-slot-end {
            background-color: #e0f7fa;
            border-radius: 6px;
            padding: 10px;
            margin: 4px 0;
            font-size: 0.85rem;
            text-align: center;
            color: #17a2b8;
            border-left: 4px solid #17a2b8;
        }

        .current-time {
            background-color: #fff3cd;
            position: relative;
            box-shadow: inset 0 0 0 2px #ffc107;
        }

        .current-time::after {
            content: "Current Time";
            position: absolute;
            top: 2px;
            right: 2px;
            font-size: 0.6rem;
            color: #856404;
            background: rgba(255, 193, 7, 0.3);
            padding: 1px 4px;
            border-radius: 3px;
        }

        .empty-slot {
            color: #adb5bd;
            font-size: 0.85rem;
        }

        .stat-card {
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card .card-body {
            padding: 1.5rem;
        }

        .stat-card .stat-icon {
            font-size: 2rem;
            opacity: 0.8;
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            margin: 5px 0;
        }

        .stat-card .stat-label {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 500;
        }

        .badge-present {
            background-color: #28a745;
        }

        .badge-late {
            background-color: #ffc107;
            color: #212529;
        }

        .badge-half-day {
            background-color: #fd7e14;
        }

        .badge-absent {
            background-color: #dc3545;
        }

        .schedule-legend {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin-right: 15px;
            font-size: 0.85rem;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 3px;
            margin-right: 5px;
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {

            .schedule-table th,
            .schedule-table td {
                padding: 8px;
                font-size: 0.85rem;
            }

            .time-slot,
            .time-slot-continue,
            .time-slot-end {
                padding: 6px;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }

            .page-header {
                padding: 15px;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .time-input {
                max-width: 100%;
            }

            .schedule-table-container {
                overflow-x: auto;
            }
        }

        /* Animation for schedule updates */
        @keyframes highlightUpdate {
            0% {
                background-color: rgba(67, 97, 238, 0.2);
            }

            100% {
                background-color: transparent;
            }
        }

        .updated-cell {
            animation: highlightUpdate 2s ease-out;
        }

        /* Navbar link fix */
        .navbar-nav .nav-link {
            font-weight: normal !important;
        }

        /* Ensure buttons stay right-aligned with proper spacing */
        .text-end {
            padding-right: 15px;
            /* Adjust if needed */
        }

        /* Optional: Add spacing between buttons */
        .btn-group .btn {
            margin-left: 5px;
            /* Space between buttons */
            margin-right: 0;
            /* Remove default right margin */
        }

        /* Error item styles */
        .error-item {
            padding: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .error-item:last-child {
            border-bottom: none;
        }

        .error-header {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error-icon {
            color: #d9534f;
            font-size: 1.1em;
        }

        .error-message {
            color: #555;
            font-size: 0.95rem;
            margin-left: 28px;
        }

        .time-comparison {
            background-color: #f8f9fa;
            padding: 0.5rem;
            border-radius: 0.25rem;
            margin-top: 0.5rem;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            color: #6c757d;
        }
    </style>
</head>

<body>
    <?php include '../includes/navbar.php'; ?>

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Schedule Validation Errors
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Please correct the following issues:</p>
                    <div class="list-group" id="errorList">
                        <!-- Errors will be inserted here by JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK, I'll fix them</button>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid py-4">
        <div class="main-container">
            <!-- Header Section -->
            <div class="page-header">
                <h1><i class="fas fa-calendar-alt me-2"></i>My Weekly Schedule</h1>
                <p class="page-subtitle">Manage your teaching schedule for the semester</p>
            </div>

            <!-- Professor Info Card -->
            <div class="card mb-4 professor-card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h4 class="mb-3 text-primary"><?= htmlspecialchars($professor['name']) ?></h4>
                            <div class="professor-detail">
                                <div class="text-muted small mb-1">Department</div>
                                <div><?= htmlspecialchars($professor['department']) ?></div>
                            </div>
                            <div class="professor-detail mt-2">
                                <div class="text-muted small mb-1">Designation</div>
                                <div><?= htmlspecialchars($professor['designation']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="professor-detail">
                                <div class="text-muted small mb-1">Email</div>
                                <div>
                                    <a href="mailto:<?= htmlspecialchars($professor['email']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($professor['email']) ?>
                                    </a>
                                </div>
                            </div>
                            <div class="professor-detail mt-2">
                                <div class="text-muted small mb-1">Phone</div>
                                <div><?= htmlspecialchars($professor['phone']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Schedule Form -->
            <form method="POST" id="scheduleForm">
                <div class="row">
                    <?php foreach ($days as $day):
                        $hasSchedules = isset($schedule[$day['id']]) && !empty($schedule[$day['id']]);
                        $dayClass = $hasSchedules ? '' : 'day-empty';
                    ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="day-card <?= $dayClass ?>" data-day-id="<?= $day['id'] ?>">
                                <div class="day-header">
                                    <?= htmlspecialchars($day['day_name']) ?>
                                    <?php if (!$hasSchedules): ?>
                                        <span class="empty-indicator">No classes</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($hasSchedules): ?>
                                    <div class="day-body">
                                        <div id="schedules-<?= $day['id'] ?>">
                                            <?php foreach ($schedule[$day['id']] as $index => $item): ?>
                                                <div class="schedule-item">
                                                    <div class="row g-2">
                                                        <div class="col-md-5">
                                                            <label class="form-label">Start Time</label>
                                                            <input type="time" class="form-control time-input"
                                                                name="schedules[<?= $day['id'] ?>][<?= $index ?>][start_time]"
                                                                value="<?= htmlspecialchars($item['start_time']) ?>">
                                                        </div>
                                                        <div class="col-md-5">
                                                            <label class="form-label">End Time</label>
                                                            <input type="time" class="form-control time-input"
                                                                name="schedules[<?= $day['id'] ?>][<?= $index ?>][end_time]"
                                                                value="<?= htmlspecialchars($item['end_time']) ?>">
                                                        </div>
                                                        <div class="col-md-2 d-flex align-items-end">
                                                            <button type="button" class="btn btn-link remove-schedule-btn"
                                                                onclick="removeSchedule(this, <?= $day['id'] ?>)">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Subject</label>
                                                            <input type="text" class="form-control"
                                                                name="schedules[<?= $day['id'] ?>][<?= $index ?>][subject]"
                                                                value="<?= htmlspecialchars($item['subject']) ?>"
                                                                placeholder="e.g. CS 101">
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Room</label>
                                                            <input type="text" class="form-control"
                                                                name="schedules[<?= $day['id'] ?>][<?= $index ?>][room]"
                                                                value="<?= htmlspecialchars($item['room']) ?>"
                                                                placeholder="e.g. Room 205">
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="text-center add-schedule-btn">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="addSchedule(<?= $day['id'] ?>)">
                                                <i class="fas fa-plus me-1"></i> Add Time Slot
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="no-classes text-center py-3">
                                        <p class="text-muted">No classes scheduled for this day</p>
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="addFirstSchedule(<?= $day['id'] ?>)">
                                            <i class="fas fa-plus me-1"></i> Add Schedule
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary submit-btn">
                        <i class="fas fa-save me-1"></i> Save Schedule
                    </button>
                </div>
            </form>

            <!-- Schedule Overview Section -->
            <div class="schedule-overview mt-5">
                <h4 class="mb-4"><i class="fas fa-calendar-week me-2"></i>Weekly Schedule Overview</h4>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary bg-opacity-10 border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="stat-label">Total Classes</h6>
                                        <h3 class="stat-value">
                                            <?php
                                            $total_classes = 0;
                                            foreach ($schedule as $day_schedules) {
                                                $total_classes += count($day_schedules);
                                            }
                                            echo $total_classes;
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="stat-icon text-primary">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card stat-card bg-success bg-opacity-10 border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="stat-label">Teaching Hours</h6>
                                        <h3 class="stat-value">
                                            <?php
                                            $total_hours = 0;
                                            foreach ($schedule as $day_schedules) {
                                                foreach ($day_schedules as $class) {
                                                    $start = strtotime($class['start_time']);
                                                    $end = strtotime($class['end_time']);
                                                    $total_hours += ($end - $start) / 3600;
                                                }
                                            }
                                            echo number_format($total_hours, 1);
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="stat-icon text-success">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card stat-card bg-info bg-opacity-10 border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="stat-label">Busiest Day</h6>
                                        <h3 class="stat-value">
                                            <?php
                                            $busiest_day = '';
                                            $max_classes = 0;
                                            $max_hours = 0;

                                            foreach ($schedule as $day_id => $day_schedules) {
                                                $day_hours = 0;
                                                foreach ($day_schedules as $class) {
                                                    $start = strtotime($class['start_time']);
                                                    $end = strtotime($class['end_time']);
                                                    $day_hours += ($end - $start) / 3600;
                                                }

                                                if ($day_hours > $max_hours) {
                                                    $max_hours = $day_hours;
                                                    foreach ($days as $day) {
                                                        if ($day['id'] == $day_id) {
                                                            $busiest_day = $day['day_name'];
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                            echo $busiest_day ?: 'N/A';
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="stat-icon text-info">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="card stat-card bg-warning bg-opacity-10 border-0">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="stat-label">Next Class</h6>
                                        <h3 class="stat-value">
                                            <?php
                                            $next_class = '';
                                            $now = time();
                                            $current_day = date('N'); // 1-7 (Monday-Sunday)
                                            $found = false;

                                            // Check today's remaining classes
                                            if (isset($schedule[$current_day])) {
                                                foreach ($schedule[$current_day] as $class) {
                                                    $class_time = strtotime($class['start_time']);
                                                    if ($class_time > $now) {
                                                        $next_class = 'Today ' . date('g:i A', $class_time);
                                                        $found = true;
                                                        break;
                                                    }
                                                }
                                            }

                                            // If not found today, check upcoming days
                                            if (!$found) {
                                                for ($i = 1; $i <= 7; $i++) {
                                                    $check_day = ($current_day + $i - 1) % 7 + 1;
                                                    if (isset($schedule[$check_day]) && !empty($schedule[$check_day])) {
                                                        $class = $schedule[$check_day][0];
                                                        foreach ($days as $day) {
                                                            if ($day['id'] == $check_day) {
                                                                $next_class = $day['day_name'] . ' ' . date('g:i A', strtotime($class['start_time']));
                                                                break 2;
                                                            }
                                                        }
                                                    }
                                                }
                                            }

                                            echo $next_class ?: 'No upcoming';
                                            ?>
                                        </h3>
                                    </div>
                                    <div class="stat-icon text-warning">
                                        <i class="fas fa-arrow-up"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Schedule Legend -->
                <div class="schedule-legend mb-3">
                    <div class="legend-item">
                        <div class="legend-color" style="background-color: #e9f7fe; border-left: 4px solid #4361ee;"></div>
                        <span>Class Time Slot</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background-color: #f0f7ff;"></div>
                        <span>Class Continuation</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background-color: #e0f7fa; border-left: 4px solid #17a2b8;"></div>
                        <span>Class End Time</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color" style="background-color: #fff3cd;"></div>
                        <span>Current Time</span>
                    </div>
                </div>

                <!-- Schedule Table -->
                <div class="schedule-table-container">
                    <table id="schedule-table" class="table schedule-table">
                        <thead>
                            <tr>
                                <th class="time-header">Time</th>
                                <?php foreach ($days as $day): ?>
                                    <th><?= htmlspecialchars($day['day_name']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Track ongoing classes to show continuation indicators
                            $ongoingClasses = [];

                            // Generate time slots from 6:00 AM to 9:00 PM in 30-minute increments
                            for ($hour = 6; $hour <= 21; $hour++):
                                foreach (['00', '30'] as $minute):
                                    // Skip 21:30 if we want to end at exactly 9:00 PM
                                    if ($hour === 21 && $minute === '30') continue;

                                    $displayHour = $hour > 12 ? $hour - 12 : $hour;
                                    $amPm = $hour >= 12 ? 'PM' : 'AM';
                                    if ($hour === 12) $amPm = 'PM'; // 12 PM for noon
                                    if ($hour === 0) $displayHour = 12; // 12 AM for midnight

                                    $time = str_pad($displayHour, 2, '0', STR_PAD_LEFT) . ':' . $minute . ' ' . $amPm;
                                    $dbTime = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . $minute . ':00';

                                    // Check if current time
                                    $currentHour = date('G');
                                    $currentMinute = date('i');
                                    $isCurrentTime = ($hour == $currentHour && $minute == str_pad(floor($currentMinute / 30) * 30, 2, '0', STR_PAD_LEFT))
                                        ? 'current-time' : '';
                            ?>
                                    <tr class="<?= $isCurrentTime ?>">
                                        <td class="time-header"><?= $time ?></td>
                                        <?php foreach ($days as $day): ?>
                                            <td>
                                                <?php if (isset($schedule[$day['id']])): ?>
                                                    <?php
                                                    $has_class = false;
                                                    $is_first_slot = false;
                                                    $is_last_slot = false;
                                                    $is_continuation = false;
                                                    $class_info = null;

                                                    // First check for new classes starting at this exact time
                                                    foreach ($schedule[$day['id']] as $item) {
                                                        $start = $item['start_time'];
                                                        $end = $item['end_time'];

                                                        $startFormatted = substr($start, 0, 5);
                                                        $dbTimeFormatted = substr($dbTime, 0, 5);

                                                        if ($dbTimeFormatted === $startFormatted) {
                                                            $has_class = true;
                                                            $is_first_slot = true;
                                                            $class_info = [
                                                                'start' => $startFormatted,
                                                                'end' => substr($end, 0, 5),
                                                                'subject' => $item['subject'],
                                                                'room' => $item['room']
                                                            ];
                                                            break;
                                                        }
                                                    }

                                                    // If no new class starts here, check ongoing classes
                                                    if (!$has_class && isset($ongoingClasses[$day['id']])) {
                                                        foreach ($ongoingClasses[$day['id']] as $key => $class) {
                                                            $classEndTime = $class['end'];
                                                            $dbTimeFormatted = substr($dbTime, 0, 5);

                                                            if ($dbTimeFormatted < $classEndTime) {
                                                                $has_class = true;
                                                                $is_continuation = true;
                                                                $class_info = $class;
                                                                break;
                                                            } elseif ($dbTimeFormatted === $classEndTime) {
                                                                $has_class = true;
                                                                $is_last_slot = true;
                                                                $class_info = $class;
                                                                unset($ongoingClasses[$day['id']][$key]);
                                                                break;
                                                            } else {
                                                                unset($ongoingClasses[$day['id']][$key]);
                                                            }
                                                        }
                                                    }

                                                    // If we found a new class starting, add it to ongoing classes
                                                    if ($is_first_slot) {
                                                        if (!isset($ongoingClasses[$day['id']])) {
                                                            $ongoingClasses[$day['id']] = [];
                                                        }
                                                        $ongoingClasses[$day['id']][] = $class_info;
                                                    }

                                                    // Display appropriate cell content
                                                    if ($has_class) {
                                                        if ($is_first_slot) {
                                                            // Convert times to 12-hour format
                                                            $startParts = explode(':', $class_info['start']);
                                                            $startHour = (int)$startParts[0];
                                                            $startDisplayHour = $startHour > 12 ? $startHour - 12 : $startHour;
                                                            if ($startHour === 0) $startDisplayHour = 12;
                                                            $startAmPm = $startHour >= 12 ? 'PM' : 'AM';
                                                            $startFormatted = str_pad($startDisplayHour, 2, '0', STR_PAD_LEFT) . ':' . $startParts[1] . ' ' . $startAmPm;

                                                            $endParts = explode(':', $class_info['end']);
                                                            $endHour = (int)$endParts[0];
                                                            $endDisplayHour = $endHour > 12 ? $endHour - 12 : $endHour;
                                                            if ($endHour === 0) $endDisplayHour = 12;
                                                            $endAmPm = $endHour >= 12 ? 'PM' : 'AM';
                                                            $endFormatted = str_pad($endDisplayHour, 2, '0', STR_PAD_LEFT) . ':' . $endParts[1] . ' ' . $endAmPm;
                                                    ?>
                                                            <div class="time-slot">
                                                                <strong><?= $startFormatted ?> - <?= $endFormatted ?></strong>
                                                                <?= htmlspecialchars($class_info['subject']) ?><br>
                                                                <?= htmlspecialchars($class_info['room']) ?>
                                                            </div>
                                                        <?php
                                                        } elseif ($is_last_slot) {
                                                            $endParts = explode(':', $class_info['end']);
                                                            $endHour = (int)$endParts[0];
                                                            $endDisplayHour = $endHour > 12 ? $endHour - 12 : $endHour;
                                                            if ($endHour === 0) $endDisplayHour = 12;
                                                            $endAmPm = $endHour >= 12 ? 'PM' : 'AM';
                                                            $endFormatted = str_pad($endDisplayHour, 2, '0', STR_PAD_LEFT) . ':' . $endParts[1] . ' ' . $endAmPm;
                                                        ?>
                                                            <div class="time-slot-end">
                                                                <span class="end-indicator">Until <?= $endFormatted ?></span>
                                                            </div>
                                                        <?php
                                                        } else {
                                                        ?>
                                                            <div class="time-slot-continue">
                                                                <span class="continue-indicator">→</span>
                                                            </div>
                                                        <?php
                                                        }
                                                    } else {
                                                        ?>
                                                        <span class="empty-slot">-</span>
                                                    <?php
                                                    }
                                                    ?>
                                                <?php else: ?>
                                                    <span class="empty-slot">-</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Download Buttons -->
                <div class="mt-3 text-end">
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-sm btn-secondary" id="btn-copy">
                            <i class="fas fa-copy me-1"></i> Copy
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" id="btn-csv">
                            <i class="fas fa-file-csv me-1"></i> CSV
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" id="btn-excel">
                            <i class="fas fa-file-excel me-1"></i> Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" id="btn-pdf">
                            <i class="fas fa-file-pdf me-1"></i> PDF
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" id="btn-print">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

    <script>
        // Add first schedule to an empty day
        function addFirstSchedule(dayId) {
            const dayCard = document.querySelector(`.day-card[data-day-id="${dayId}"]`);
            if (!dayCard) return;

            // Remove empty state if it exists
            const emptyState = dayCard.querySelector('.no-classes');
            if (emptyState) {
                emptyState.remove();
            }

            // Remove day-empty class
            dayCard.classList.remove('day-empty');

            // Create day body with first schedule
            const dayBody = document.createElement('div');
            dayBody.className = 'day-body';
            dayBody.id = `day-body-${dayId}`;
            dayBody.innerHTML = `
            <div id="schedules-${dayId}">
                <div class="schedule-item">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-control time-input" 
                                   name="schedules[${dayId}][0][start_time]">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">End Time</label>
                            <input type="time" class="form-control time-input" 
                                   name="schedules[${dayId}][0][end_time]">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="button" class="btn btn-link remove-schedule-btn" 
                                    onclick="removeSchedule(this, ${dayId})">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subject</label>
                            <input type="text" class="form-control" 
                                   name="schedules[${dayId}][0][subject]" 
                                   placeholder="e.g. CS 101">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Room</label>
                            <input type="text" class="form-control" 
                                   name="schedules[${dayId}][0][room]" 
                                   placeholder="e.g. Room 205">
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center add-schedule-btn">
                <button type="button" class="btn btn-sm btn-outline-primary" 
                        onclick="addSchedule(${dayId})">
                    <i class="fas fa-plus me-1"></i> Add Time Slot
                </button>
            </div>
        `;

            dayCard.appendChild(dayBody);
        }

        // Add new schedule slot for a day
        function addSchedule(dayId) {
            const container = document.getElementById(`schedules-${dayId}`);
            if (!container) return;

            const index = container.querySelectorAll('.schedule-item').length;

            const newItem = document.createElement('div');
            newItem.className = 'schedule-item';
            newItem.innerHTML = `
            <div class="row g-2">
                <div class="col-md-5">
                    <label class="form-label">Start Time</label>
                    <input type="time" class="form-control time-input" 
                           name="schedules[${dayId}][${index}][start_time]">
                </div>
                <div class="col-md-5">
                    <label class="form-label">End Time</label>
                    <input type="time" class="form-control time-input" 
                           name="schedules[${dayId}][${index}][end_time]">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" class="btn btn-link remove-schedule-btn" 
                            onclick="removeSchedule(this, ${dayId})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="col-12">
                    <label class="form-label">Subject</label>
                    <input type="text" class="form-control" 
                           name="schedules[${dayId}][${index}][subject]" 
                           placeholder="e.g. CS 101">
                </div>
                <div class="col-12">
                    <label class="form-label">Room</label>
                    <input type="text" class="form-control" 
                           name="schedules[${dayId}][${index}][room]" 
                           placeholder="e.g. Room 205">
                </div>
            </div>
        `;

            container.appendChild(newItem);

            // Scroll to the new item
            newItem.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        // Remove a schedule slot
        function removeSchedule(button, dayId) {
            const item = button.closest('.schedule-item');
            if (!item) return;

            const container = item.parentElement;
            const dayCard = container.closest('.day-card');
            const dayBody = container.closest('.day-body');

            item.remove();

            // Re-index remaining items
            const items = container.querySelectorAll('.schedule-item');
            items.forEach((item, index) => {
                item.querySelectorAll('[name]').forEach(input => {
                    input.name = input.name.replace(/\[\d+\]\[\d+\]/g, `[${dayId}][${index}]`);
                });
            });

            // If no more schedule items, show the empty state
            if (items.length === 0) {
                // Remove the entire day body
                dayBody.remove();

                // Create and show the empty state
                const emptyState = document.createElement('div');
                emptyState.className = 'no-classes text-center py-3';
                emptyState.innerHTML = `
                <p class="text-muted">No classes scheduled for this day</p>
                <button type="button" class="btn btn-sm btn-outline-primary" 
                        onclick="addFirstSchedule(${dayId})">
                    <i class="fas fa-plus me-1"></i> Add Schedule
                </button>
            `;

                dayCard.classList.add('day-empty');
                dayCard.appendChild(emptyState);
            }
        }

        // Helper function to format time for display (e.g., "13:00" -> "1:00 PM")
        function formatTimeForDisplay(timeString) {
            if (!timeString) return '';
            const [hours, minutes] = timeString.split(':');
            const hourNum = parseInt(hours);
            const ampm = hourNum >= 12 ? 'PM' : 'AM';
            const displayHour = hourNum % 12 || 12;
            return `${displayHour}:${minutes} ${ampm}`;
        }

        // Helper function to get day name from ID
        function getDayName(dayId) {
            const days = {
                <?php
                foreach ($days as $day) {
                    echo $day['id'] . ": '" . addslashes($day['day_name']) . "',";
                }
                ?>
            };
            return days[dayId] || '';
        }

        // Initialize Bootstrap modal
        const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));

        // Validate time inputs before submission
        document.getElementById('scheduleForm').addEventListener('submit', function(e) {
            let valid = true;
            const dayIds = [<?php echo implode(',', array_column($days, 'id')); ?>];
            const errorMessages = [];

            dayIds.forEach(dayId => {
                const container = document.getElementById(`schedules-${dayId}`);
                if (!container) return;

                const items = container.querySelectorAll('.schedule-item');
                const dayName = getDayName(dayId);

                items.forEach((item, index) => {
                    const startTime = item.querySelector('[name*="start_time"]').value;
                    const endTime = item.querySelector('[name*="end_time"]').value;
                    const subject = item.querySelector('[name*="subject"]').value;

                    // Check if any field is filled for this time slot
                    const hasData = startTime || endTime || subject;

                    if (hasData) {
                        if (!startTime || !endTime) {
                            errorMessages.push({
                                day: dayName,
                                slot: index + 1,
                                message: 'Please fill both start and end times or remove the empty time slot',
                                startTime: startTime,
                                endTime: endTime
                            });
                            valid = false;
                        } else if (startTime >= endTime) {
                            errorMessages.push({
                                day: dayName,
                                slot: index + 1,
                                message: 'The end time must be after the start time',
                                startTime: startTime,
                                endTime: endTime
                            });
                            valid = false;
                        }
                    }
                });
            });

            if (!valid) {
                e.preventDefault();

                // Populate modal with error messages
                const errorList = document.getElementById('errorList');
                errorList.innerHTML = '';

                errorMessages.forEach(error => {
                    const errorItem = document.createElement('div');
                    errorItem.className = 'list-group-item';
                    errorItem.innerHTML = `
                        <div class="error-item">
                            <div class="error-header">
                                <i class="fas fa-calendar-day error-icon"></i>
                                ${error.day} - Time Slot #${error.slot}
                            </div>
                            <div class="error-message">
                                ${error.message}
                                ${error.startTime ? `
                                <div class="time-comparison">
                                    ${formatTimeForDisplay(error.startTime)} 
                                    <i class="fas fa-arrow-right mx-2 text-muted"></i> 
                                    ${formatTimeForDisplay(error.endTime)}
                                </div>` : ''}
                            </div>
                        </div>
                    `;
                    errorList.appendChild(errorItem);
                });

                // Show modal
                errorModal.show();

                // Scroll to the first error if possible
                const firstErrorContainer = document.querySelector(`#schedules-${dayIds[0]}`);
                if (firstErrorContainer) {
                    firstErrorContainer.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }
        });

        // Initialize DataTable with export buttons
        $(document).ready(function() {
            var table = $('#schedule-table').DataTable({
                responsive: true,
                searching: false,
                paging: false,
                info: false,
                ordering: false,
                dom: "<'row'<'col-sm-12'tr>>",
                buttons: [{
                        extend: 'copy',
                        className: 'btn btn-sm btn-secondary',
                        text: '<i class="fas fa-copy"></i> Copy'
                    },
                    {
                        extend: 'csv',
                        className: 'btn btn-sm btn-secondary',
                        text: '<i class="fas fa-file-csv"></i> CSV'
                    },
                    {
                        extend: 'excel',
                        className: 'btn btn-sm btn-secondary',
                        text: '<i class="fas fa-file-excel"></i> Excel'
                    },
                    {
                        extend: 'pdf',
                        className: 'btn btn-sm btn-secondary',
                        text: '<i class="fas fa-file-pdf"></i> PDF'
                    },
                    {
                        extend: 'print',
                        className: 'btn btn-sm btn-secondary',
                        text: '<i class="fas fa-print"></i> Print'
                    }
                ]
            });

            // Add button click handlers
            $('#btn-copy').click(function() {
                table.button('.buttons-copy').trigger();
            });
            $('#btn-csv').click(function() {
                table.button('.buttons-csv').trigger();
            });
            $('#btn-excel').click(function() {
                table.button('.buttons-excel').trigger();
            });
            $('#btn-pdf').click(function() {
                table.button('.buttons-pdf').trigger();
            });
            $('#btn-print').click(function() {
                table.button('.buttons-print').trigger();
            });

            // Highlight updated cells when coming back from form submission
            <?php if ($success): ?>
                // Find all cells that contain time slots and highlight them
                $('.time-slot, .time-slot-end').each(function() {
                    $(this).closest('td').addClass('updated-cell');
                });
            <?php endif; ?>
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>