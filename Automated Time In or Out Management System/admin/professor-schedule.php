<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Authentication check
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin-login.php');
    exit;
}

// Get professor ID from URL
$professor_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;

if (!$professor_id) {
    header('Location: reports.php?report_type=professor_activity');
    exit;
}

// Get professor details
$professor_stmt = $conn->prepare("SELECT * FROM professors WHERE id = ?");
$professor_stmt->bind_param('i', $professor_id);
$professor_stmt->execute();
$professor = $professor_stmt->get_result()->fetch_assoc();

if (!$professor) {
    header('Location: reports.php?report_type=professor_activity');
    exit;
}

// Get filter parameters
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'this_month';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Calculate date ranges
$today = date('Y-m-d');
$current_month = date('Y-m');
$current_year = date('Y');

switch ($date_range) {
    case 'today':
        $start_date = $today;
        $end_date = $today;
        break;
    case 'yesterday':
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = $start_date;
        break;
    case 'this_week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'last_week':
        $start_date = date('Y-m-d', strtotime('monday last week'));
        $end_date = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'this_month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'this_year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        break;
    default:
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
}

// Get attendance records
$query = "SELECT 
            a.id,
            a.date,
            a.am_check_in,
            a.am_check_out,
            a.pm_check_in,
            a.pm_check_out,
            a.status,
            a.is_late,
            a.work_duration,
            TIME_TO_SEC(a.work_duration) as duration_seconds,
            TIMESTAMPDIFF(MINUTE, 
                CASE WHEN a.am_check_in IS NOT NULL THEN a.am_check_in ELSE a.pm_check_in END,
                CASE WHEN a.pm_check_out IS NOT NULL THEN a.pm_check_out ELSE a.am_check_out END
            ) as calculated_duration
          FROM attendance a
          WHERE a.professor_id = ?
          AND a.date BETWEEN ? AND ?";

// Apply status filter
$params = [$professor_id, $start_date, $end_date];
$types = 'iss';

if ($status_filter !== 'all') {
    $status_map = [
        'present' => 'present',
        'late' => 'present',
        'half-day' => 'half-day',
        'absent' => 'absent'
    ];
    $query .= " AND a.status = ?";
    $params[] = $status_map[$status_filter];
    $types .= 's';

    if ($status_filter === 'late') {
        $query .= " AND a.is_late = 1";
    }
}

$query .= " ORDER BY a.date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$attendance_data = $stmt->get_result();

// Get min/max dates for datepicker
$date_bounds = $conn->query("SELECT MIN(date) as min_date, MAX(date) as max_date FROM attendance WHERE professor_id = $professor_id")->fetch_assoc();

// Calculate statistics
$stats_query = "SELECT 
                  COUNT(*) as total,
                  SUM(CASE WHEN status = 'present' AND is_late = 0 THEN 1 ELSE 0 END) as present,
                  SUM(CASE WHEN status = 'present' AND is_late = 1 THEN 1 ELSE 0 END) as late,
                  SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_day,
                  SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                  AVG(TIME_TO_SEC(work_duration)) as avg_duration_seconds
                FROM attendance
                WHERE professor_id = ?
                AND date BETWEEN ? AND ?";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param('iss', $professor_id, $start_date, $end_date);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Convert average duration from seconds to hours and minutes
if ($stats['avg_duration_seconds']) {
    $avg_hours = floor($stats['avg_duration_seconds'] / 3600);
    $avg_minutes = floor(($stats['avg_duration_seconds'] % 3600) / 60);
    $stats['avg_duration'] = $avg_hours . 'h ' . $avg_minutes . 'm';
} else {
    $stats['avg_duration'] = 'N/A';
}

// Get professor's current schedule for all days
$schedule_query = "SELECT 
                    ps.id as schedule_id,
                    ps.day_id, 
                    sd.day_name, 
                    ps.start_time, 
                    ps.end_time, 
                    ps.subject, 
                    ps.room
                  FROM professor_schedules ps
                  JOIN schedule_days sd ON ps.day_id = sd.id
                  WHERE ps.professor_id = ?
                  ORDER BY ps.day_id, ps.start_time";
$schedule_stmt = $conn->prepare($schedule_query);
$schedule_stmt->bind_param('i', $professor_id);
$schedule_stmt->execute();
$schedules = $schedule_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Group schedules by day
$grouped_schedules = [];
$days_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
foreach ($days_order as $day_name) {
    $grouped_schedules[$day_name] = [];
}

foreach ($schedules as $schedule) {
    $grouped_schedules[$schedule['day_name']][] = $schedule;
}

// Function to calculate time difference in minutes
function calculateDuration($start, $end)
{
    if (!$start || !$end) return 0;

    $start_time = new DateTime($start);
    $end_time = new DateTime($end);
    $interval = $start_time->diff($end_time);

    return ($interval->h * 60) + $interval->i;
}

// Get professor ID from URL
$professor_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;

if (!$professor_id) {
    header('Location: reports.php?report_type=professor_activity');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professor Schedule | Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        .professor-card {
            border-left: 4px solid #4361ee;
            transition: all 0.3s;
        }

        .stat-card {
            border-radius: 8px;
            overflow: hidden;
            height: 100%;
        }

        .stat-card .card-body {
            padding: 1.25rem;
        }

        .stat-card .stat-icon {
            font-size: 1.75rem;
            opacity: 0.8;
        }

        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .stat-card .stat-label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
        }

        .table-attendance {
            font-size: 0.9rem;
        }

        .table-attendance th {
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            color: #495057;
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

        .badge-timed-in {
            background-color: #17a2b8;
        }

        .date-range-btn.active {
            background-color: #4361ee;
            color: white;
        }

        .schedule-day {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .schedule-time {
            font-weight: 500;
            color: #4361ee;
        }

        .schedule-subject {
            font-weight: 500;
        }

        .schedule-room {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .current-schedule {
            background-color: #e9f7fe;
            border-left: 4px solid #4361ee;
        }

        .dt-buttons .btn {
            border-radius: 4px !important;
            margin-right: 5px;
            padding: 5px 10px;
            font-size: 0.8rem;
        }

        .day-header {
            font-weight: 600;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #dee2e6;
        }

        .attendance-container {
            margin-bottom: 2rem;
        }

        .schedule-container {
            margin-bottom: 2rem;
        }

        .filter-container {
            margin-bottom: 2rem;
        }

        .time-session {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .time-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }

        .time-value {
            font-weight: 500;
        }

        .duration-badge {
            font-size: 0.75rem;
            padding: 0.25em 0.5em;
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }

        .current-day {
            background-color: rgba(67, 97, 238, 0.1);
            border-left: 3px solid #4361ee;
        }
    </style>
</head>

<body>
    <?php include 'partials/sidebar.php'; ?>

    <main class="main-content">
        <div class="container-fluid py-4">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1 fw-bold">Professor Schedule & Attendance</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home me-1"></i> Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="reports.php?report_type=professor_activity"><i class="fas fa-chart-bar me-1"></i> Professor Reports</a></li>
                            <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-user-tie me-1"></i> <?= htmlspecialchars($professor['name']) ?></li>
                        </ol>
                    </nav>
                </div>
            </div>

            <!-- Professor Information -->
            <div class="card mb-4 professor-card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h4 class="mb-3 text-primary font-weight-bold"><?= htmlspecialchars($professor['name']) ?></h4>
                            <div class="professor-detail">
                                <div class="text-muted small mb-1">Department</div>
                                <div class="font-weight-medium"><?= htmlspecialchars($professor['department']) ?></div>
                            </div>
                            <div class="professor-detail mt-2">
                                <div class="text-muted small mb-1">Designation</div>
                                <div class="font-weight-medium"><?= htmlspecialchars($professor['designation']) ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="professor-detail">
                                <div class="text-muted small mb-1">Email</div>
                                <div class="font-weight-medium">
                                    <a href="mailto:<?= htmlspecialchars($professor['email']) ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($professor['email']) ?>
                                    </a>
                                </div>
                            </div>
                            <div class="professor-detail mt-2">
                                <div class="text-muted small mb-1">Phone</div>
                                <div class="font-weight-medium"><?= htmlspecialchars($professor['phone']) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="card filter-container">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Attendance Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" id="attendanceForm">
                        <input type="hidden" name="id" value="<?= $professor_id ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status Filter</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                    <option value="present" <?= $status_filter === 'present' ? 'selected' : '' ?>>Present</option>
                                    <option value="late" <?= $status_filter === 'late' ? 'selected' : '' ?>>Late</option>
                                    <option value="half-day" <?= $status_filter === 'half-day' ? 'selected' : '' ?>>Half Day</option>
                                    <option value="absent" <?= $status_filter === 'absent' ? 'selected' : '' ?>>Absent</option>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Date Range</label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="date_range" id="today" value="today" autocomplete="off" <?= $date_range === 'today' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="today">Today</label>

                                    <input type="radio" class="btn-check" name="date_range" id="yesterday" value="yesterday" autocomplete="off" <?= $date_range === 'yesterday' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="yesterday">Yesterday</label>

                                    <input type="radio" class="btn-check" name="date_range" id="this_week" value="this_week" autocomplete="off" <?= $date_range === 'this_week' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="this_week">This Week</label>

                                    <input type="radio" class="btn-check" name="date_range" id="this_month" value="this_month" autocomplete="off" <?= $date_range === 'this_month' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="this_month">This Month</label>

                                    <input type="radio" class="btn-check" name="date_range" id="this_year" value="this_year" autocomplete="off" <?= $date_range === 'this_year' ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="this_year">This Year</label>
                                </div>
                            </div>

                            <div class="col-md-12 text-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-filter me-1"></i> Apply Filters
                                </button>
                                <a href="professor-schedule.php?id=<?= $professor_id ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-sync me-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card bg-primary bg-opacity-10 border-0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="stat-label">Total Records</h6>
                                    <h3 class="stat-value"><?= $stats['total'] ?? 0 ?></h3>
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
                                    <h6 class="stat-label">Present</h6>
                                    <h3 class="stat-value"><?= $stats['present'] ?? 0 ?></h3>
                                </div>
                                <div class="stat-icon text-success">
                                    <i class="fas fa-check-circle"></i>
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
                                    <h6 class="stat-label">Late Arrivals</h6>
                                    <h3 class="stat-value"><?= $stats['late'] ?? 0 ?></h3>
                                </div>
                                <div class="stat-icon text-warning">
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
                                    <h6 class="stat-label">Avg. Duration</h6>
                                    <h3 class="stat-value">
                                        <?= $stats['avg_duration'] ?? 'N/A' ?>
                                    </h3>
                                </div>
                                <div class="stat-icon text-info">
                                    <i class="fas fa-stopwatch"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Records -->
            <div class="card attendance-container">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Attendance Records</h5>
                    <div class="text-muted">
                        <?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="attendanceTable" class="table table-attendance table-hover" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Check-In/Out Times</th>
                                    <th>Total Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($attendance_data->num_rows > 0):
                                    // Group records by date first
                                    $grouped_records = [];
                                    while ($record = $attendance_data->fetch_assoc()) {
                                        $date = $record['date'];
                                        if (!isset($grouped_records[$date])) {
                                            $grouped_records[$date] = [];
                                        }
                                        $grouped_records[$date][] = $record;
                                    }

                                    foreach ($grouped_records as $date => $records):
                                        $day_name = date('l', strtotime($date));
                                        $is_current_day = date('Y-m-d') === $date;

                                        // Initialize variables
                                        $am_check_in = null;
                                        $am_check_out = null;
                                        $pm_check_in = null;
                                        $pm_check_out = null;
                                        $status = 'absent';
                                        $is_late = false;
                                        $total_duration = 0;

                                        // Combine all records for this date
                                        foreach ($records as $record) {
                                            if ($record['am_check_in']) $am_check_in = $record['am_check_in'];
                                            if ($record['am_check_out']) $am_check_out = $record['am_check_out'];
                                            if ($record['pm_check_in']) $pm_check_in = $record['pm_check_in'];
                                            if ($record['pm_check_out']) $pm_check_out = $record['pm_check_out'];

                                            // Use the most favorable status (present > half-day > absent)
                                            if ($record['status'] === 'present') {
                                                $status = 'present';
                                                $is_late = $record['is_late'] || $is_late;
                                            } elseif ($record['status'] === 'half-day' && $status !== 'present') {
                                                $status = 'half-day';
                                            }

                                            // Sum up durations
                                            $total_duration += $record['calculated_duration'] ?? 0;
                                        }

                                        // Calculate durations
                                        $am_duration = ($am_check_in && $am_check_out) ?
                                            calculateDuration($am_check_in, $am_check_out) : 0;
                                        $pm_duration = ($pm_check_in && $pm_check_out) ?
                                            calculateDuration($pm_check_in, $pm_check_out) : 0;
                                        $total_duration = $am_duration + $pm_duration;
                                ?>
                                        <tr class="<?= $is_current_day ? 'current-day' : '' ?>">
                                            <td><?= date('M j, Y', strtotime($date)) ?></td>
                                            <td><?= $day_name ?></td>
                                            <td>
                                                <div class="time-session">
                                                    <!-- AM Session -->
                                                    <div class="mb-2">
                                                        <span class="badge bg-primary bg-opacity-10 text-primary mb-1">AM</span>
                                                        <?php if ($am_check_in): ?>
                                                            <div>
                                                                <span class="time-label">Time In</span>
                                                                <span class="time-value"><?= date('h:i A', strtotime($am_check_in)) ?></span>
                                                            </div>
                                                            <?php if ($am_check_out): ?>
                                                                <div>
                                                                    <span class="time-label">Time Out</span>
                                                                    <span class="time-value"><?= date('h:i A', strtotime($am_check_out)) ?></span>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="badge rounded-pill bg-warning bg-opacity-10 text-warning">
                                                                    <i class="fas fa-clock me-1"></i> Pending time out
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">
                                                                <i class="fas fa-minus me-1"></i> Not timed in
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <!-- PM Session -->
                                                    <div>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger mb-1">PM</span>
                                                        <?php if ($pm_check_in): ?>
                                                            <div>
                                                                <span class="time-label">Time In</span>
                                                                <span class="time-value"><?= date('h:i A', strtotime($pm_check_in)) ?></span>
                                                            </div>
                                                            <?php if ($pm_check_out): ?>
                                                                <div>
                                                                    <span class="time-label">Time Out</span>
                                                                    <span class="time-value"><?= date('h:i A', strtotime($pm_check_out)) ?></span>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="badge rounded-pill bg-warning bg-opacity-10 text-warning">
                                                                    <i class="fas fa-clock me-1"></i> Pending time out
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">
                                                                <i class="fas fa-minus me-1"></i> Not timed in
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($total_duration > 0): ?>
                                                    <span class="badge rounded-pill bg-success bg-opacity-10 text-success">
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?= floor($total_duration / 60) ?>h <?= $total_duration % 60 ?>m
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">
                                                        <i class="fas fa-minus me-1"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($am_check_out || $pm_check_out): ?>
                                                    <?php if ($status === 'present'): ?>
                                                        <?php if ($is_late): ?>
                                                            <span class="badge badge-late">Late</span>
                                                        <?php else: ?>
                                                            <span class="badge badge-present">Present</span>
                                                        <?php endif; ?>
                                                    <?php elseif ($status === 'half-day'): ?>
                                                        <span class="badge badge-half-day">Half Day</span>
                                                    <?php endif; ?>
                                                <?php elseif ($am_check_in || $pm_check_in): ?>
                                                    <span class="badge badge-timed-in">Timed In</span>
                                                <?php else: ?>
                                                    <span class="badge badge-absent">Absent</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            No attendance records found for the selected filters
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Weekly Schedule -->
            <div class="card schedule-container">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Weekly Schedule</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($grouped_schedules as $day_name => $day_schedules):
                            $is_current_day = date('l') === $day_name;
                        ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="schedule-day <?= $is_current_day ? 'current-day' : '' ?>">
                                    <h6 class="fw-bold mb-3 day-header">
                                        <?= htmlspecialchars($day_name) ?>
                                        <?php if ($is_current_day): ?>
                                            <span class="badge bg-primary ms-2">Today</span>
                                        <?php endif; ?>
                                    </h6>
                                    <?php if (!empty($day_schedules)): ?>
                                        <?php foreach ($day_schedules as $schedule): ?>
                                            <div class="mb-3 p-3 bg-white rounded">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <div class="schedule-time">
                                                            <?= date('h:i A', strtotime($schedule['start_time'])) ?> - <?= date('h:i A', strtotime($schedule['end_time'])) ?>
                                                        </div>
                                                        <div class="schedule-subject mt-1 fw-bold">
                                                            <?= htmlspecialchars($schedule['subject']) ?>
                                                        </div>
                                                    </div>
                                                    <div class="schedule-room badge bg-light text-dark">
                                                        <?= htmlspecialchars($schedule['room']) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="alert alert-light mb-0">
                                            No classes scheduled
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

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
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable with export buttons
            $('#attendanceTable').DataTable({
                responsive: true,
                dom: '<"top"Bf>rt<"bottom"lip><"clear">',
                buttons: [{
                        extend: 'excelHtml5',
                        text: '<i class="fas fa-file-excel me-1"></i> Excel',
                        className: 'btn btn-sm btn-success',
                        title: '<?= htmlspecialchars($professor['name']) ?> Attendance',
                        exportOptions: {
                            columns: [0, 1, 2, 3, 4, 5]
                        }
                    },
                    {
                        extend: 'pdfHtml5',
                        text: '<i class="fas fa-file-pdf me-1"></i> PDF',
                        className: 'btn btn-sm btn-danger',
                        title: '<?= htmlspecialchars($professor['name']) ?> Attendance',
                        exportOptions: {
                            columns: [0, 1, 2, 3, 4, 5]
                        },
                        customize: function(doc) {
                            doc.defaultStyle.fontSize = 9;
                            doc.styles.tableHeader.fontSize = 10;
                            doc.pageMargins = [20, 20, 20, 20];
                        }
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print me-1"></i> Print',
                        className: 'btn btn-sm btn-info',
                        title: '<?= htmlspecialchars($professor['name']) ?> Attendance',
                        exportOptions: {
                            columns: [0, 1, 2, 3, 4, 5]
                        }
                    }
                ],
                pageLength: 10,
                lengthMenu: [5, 10, 25, 50, 100],
                order: [
                    [0, 'desc']
                ] // Sort by date descending
            });
        });
    </script>
</body>

</html>