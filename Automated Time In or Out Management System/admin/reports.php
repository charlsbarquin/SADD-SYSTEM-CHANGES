<?php
session_start();
require_once '../config/database.php';

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin-login.php');
    exit;
}

// Session timeout (30 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    header('Location: admin-login.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

// Get admin details
$admin_id = $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT username, email, last_attempt FROM admins WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'daily_summary';
$date_range = $_GET['date_range'] ?? 'today';
$status_filter = $_GET['status'] ?? 'all';

// Calculate date ranges
$today = date('Y-m-d');
$current_month = date('Y-m');
$current_year = date('Y');

switch ($date_range) {
    case 'today':
        $start_date = $today;
        $end_date = $today;
        break;
    case 'this_week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'this_month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        break;
    case 'this_year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-12-31');
        break;
    case 'custom':
        $start_date = $_GET['start_date'] ?? $today;
        $end_date = $_GET['end_date'] ?? $today;
        break;
    default:
        $start_date = $today;
        $end_date = $today;
}

// Generate reports based on type
switch ($report_type) {
    case 'daily_summary':
        $report_title = "Daily Attendance Summary";
        $query = "SELECT 
                    DATE(a.checkin_date) as date,
                    COUNT(DISTINCT a.professor_id) as total_professors,
                    SUM(CASE WHEN (a.am_check_in IS NOT NULL OR a.pm_check_in IS NOT NULL) THEN 1 ELSE 0 END) as total_attendance,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN a.status = 'half-day' THEN 1 ELSE 0 END) as half_day,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_arrivals
                  FROM attendance a
                  WHERE DATE(a.checkin_date) BETWEEN ? AND ?";

        $params = [$start_date, $end_date];
        $types = 'ss';

        if ($status_filter !== 'all') {
            $query .= " AND a.status = ?";
            $params[] = $status_filter;
            $types .= 's';
        }

        $query .= " GROUP BY DATE(a.checkin_date) ORDER BY date DESC";
        break;

    case 'professor_activity':
        $report_title = "Professor Activity Report";
        $query = "SELECT 
                    p.id,
                    p.name,
                    p.designation,
                    p.department,
                    COUNT(DISTINCT a.checkin_date) as total_days,
                    SUM(CASE WHEN (a.am_check_in IS NOT NULL OR a.pm_check_in IS NOT NULL) THEN 1 ELSE 0 END) as timeins,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN a.status = 'half-day' THEN 1 ELSE 0 END) as half_day,
                    SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_arrivals,
                    SEC_TO_TIME(AVG(
                        CASE 
                            WHEN a.am_check_in IS NOT NULL AND a.pm_check_out IS NOT NULL THEN 
                                TIMESTAMPDIFF(SECOND, a.am_check_in, a.pm_check_out)
                            WHEN a.am_check_in IS NOT NULL AND a.am_check_out IS NOT NULL THEN 
                                TIMESTAMPDIFF(SECOND, a.am_check_in, a.am_check_out)
                            WHEN a.pm_check_in IS NOT NULL AND a.pm_check_out IS NOT NULL THEN 
                                TIMESTAMPDIFF(SECOND, a.pm_check_in, a.pm_check_out)
                            ELSE 0
                        END
                    )) as avg_duration
                  FROM professors p
                  LEFT JOIN attendance a ON p.id = a.professor_id AND DATE(a.checkin_date) BETWEEN ? AND ?
                  WHERE p.status = 'active'";

        $params = [$start_date, $end_date];
        $types = 'ss';

        if ($status_filter !== 'all') {
            $query .= " AND a.status = ?";
            $params[] = $status_filter;
            $types .= 's';
        }

        $query .= " GROUP BY p.id ORDER BY p.name";
        break;

    case 'attendance_details':
        $report_title = "Attendance Details Report";
        $query = "SELECT 
                    p.id as professor_id,
                    p.name, 
                    a.checkin_date as date,
                    DAYNAME(a.checkin_date) as day_name,
                    (SELECT MIN(ps.start_time) 
                     FROM professor_schedules ps 
                     WHERE ps.professor_id = a.professor_id 
                     AND DAYOFWEEK(a.checkin_date) = ps.day_id) as scheduled_time,
                    CASE 
                        WHEN a.am_check_in IS NOT NULL THEN TIME(a.am_check_in)
                        WHEN a.pm_check_in IS NOT NULL THEN TIME(a.pm_check_in)
                        ELSE NULL
                    END as actual_time,
                    CASE 
                        WHEN a.am_check_in IS NULL AND a.pm_check_in IS NULL THEN 'Absent'
                        WHEN a.is_late = 1 THEN 'Late'
                        ELSE 'On Time'
                    END as status,
                    a.status as attendance_status,
                    a.work_duration
                FROM attendance a
                JOIN professors p ON a.professor_id = p.id
                WHERE DATE(a.checkin_date) BETWEEN ? AND ?";
        
        $params = [$start_date, $end_date];
        $types = 'ss';
        
        if ($status_filter !== 'all') {
            if ($status_filter === 'present') {
                $query .= " AND (a.am_check_in IS NOT NULL OR a.pm_check_in IS NOT NULL) AND a.is_late = 0";
            } elseif ($status_filter === 'late') {
                $query .= " AND a.is_late = 1";
            } elseif ($status_filter === 'absent') {
                $query .= " AND a.am_check_in IS NULL AND a.pm_check_in IS NULL";
            }
        }
        
        $query .= " ORDER BY a.checkin_date, p.name";
        break;

    default:
        $report_title = "Daily Attendance Summary";
        $report_type = 'daily_summary';
        $query = "SELECT 
                    DATE(a.checkin_date) as date,
                    COUNT(DISTINCT a.professor_id) as total_professors,
                    SUM(CASE WHEN (a.am_check_in IS NOT NULL OR a.pm_check_in IS NOT NULL) THEN 1 ELSE 0 END) as total_attendance,
                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN a.status = 'half-day' THEN 1 ELSE 0 END) as half_day,
                    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_arrivals
                  FROM attendance a
                  WHERE DATE(a.checkin_date) BETWEEN ? AND ?
                  GROUP BY DATE(a.checkin_date) ORDER BY date DESC";
        $params = [$start_date, $end_date];
        $types = 'ss';
}

// Prepare and execute query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$report_data = $stmt->get_result();

// Get attendance statistics for the selected date range
$attendance_stats = $conn->query("
    SELECT 
        COUNT(DISTINCT a.professor_id) as total_professors,
        COUNT(DISTINCT a.checkin_date) as total_days,
        SUM(CASE WHEN (a.am_check_in IS NOT NULL OR a.pm_check_in IS NOT NULL) THEN 1 ELSE 0 END) as total_attendance,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN a.status = 'half-day' THEN 1 ELSE 0 END) as half_day,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent,
        SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_arrivals
    FROM attendance a
    WHERE DATE(a.checkin_date) BETWEEN '$start_date' AND '$end_date'
")->fetch_assoc();

// Get attendance data for the chart
$attendance_chart_data = $conn->query("
    SELECT 
        DATE(a.checkin_date) as day,
        COUNT(DISTINCT a.professor_id) as total_checkins
    FROM attendance a
    WHERE DATE(a.checkin_date) BETWEEN '$start_date' AND '$end_date'
    GROUP BY DATE(a.checkin_date)
    ORDER BY day ASC
");

// Initialize arrays with default values for all days in range
$days = [];
$attendance_chart_values = [];

// Get all dates in the range
$period = new DatePeriod(
    new DateTime($start_date),
    new DateInterval('P1D'),
    new DateTime(date('Y-m-d', strtotime($end_date . ' +1 day')))
);

foreach ($period as $date) {
    $day_name = $date->format('Y-m-d');
    $days[] = $date->format('M j');
    $attendance_chart_values[$day_name] = 0;
}

// Fill in actual data from the query
if ($attendance_chart_data) {
    while ($row = $attendance_chart_data->fetch_assoc()) {
        $day_name = $row['day'];
        $attendance_chart_values[$day_name] = $row['total_checkins'];
    }
}

// Convert to arrays in the correct order for the chart
$attendance_chart_values = array_values($attendance_chart_values);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Bicol University Polangui Reports Dashboard">
    <meta name="robots" content="noindex, nofollow">
    <title>Reports | Bicol University Polangui</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        .chart-container {
            position: relative;
            height: 180px;
            width: 100%;
        }

        .stat-card {
            transition: all 0.3s ease;
            border-radius: 8px;
            overflow: hidden;
            border-left: 4px solid;
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

        .table-report {
            font-size: 0.9rem;
        }

        .table-report th {
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            color: #495057;
        }

        .badge-present {
            background-color: #28a745;
        }

        .badge-half-day {
            background-color: #fd7e14;
        }

        .badge-absent {
            background-color: #dc3545;
        }

        .badge-late {
            background-color: #ffc107;
            color: #212529;
        }

        .badge-on-time {
            background-color: #17a2b8;
        }

        .nav-pills .nav-link.active {
            background-color: var(--primary-color);
        }

        .nav-pills .nav-link {
            color: #495057;
            font-weight: 500;
        }

        .date-range-btn.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .custom-date-range {
            display: none;
            margin-top: 10px;
        }
        
        .custom-date-range.active {
            display: block;
        }

        .report-filter-card {
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .stat-card-primary {
            border-left-color: #4361ee;
            background-color: rgba(67, 97, 238, 0.05);
        }

        .stat-card-success {
            border-left-color: #28a745;
            background-color: rgba(40, 167, 69, 0.05);
        }

        .stat-card-warning {
            border-left-color: #ffc107;
            background-color: rgba(255, 193, 7, 0.05);
        }

        .stat-card-danger {
            border-left-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.05);
        }

        .icon-primary {
            color: #4361ee;
        }

        .icon-success {
            color: #28a745;
        }

        .icon-warning {
            color: #ffc107;
        }

        .icon-danger {
            color: #dc3545;
        }

        .dt-buttons .btn {
            margin-right: 5px;
        }
    </style>
</head>

<body>
    <?php include 'partials/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-title">
                <h1>Reports Dashboard</h1>
                <small class="text-muted"><?= date('l, F j, Y') ?></small>
            </div>

            <div class="user-menu">
                <div class="user-info">
                    <p class="user-role">Administrator</p>
                </div>
            </div>
        </div>

        <!-- Dashboard Content -->
        <div class="dashboard-content">
            <!-- Report Filters -->
            <div class="report-filter-card">
                <form method="GET" id="reportForm">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select class="form-select" id="report_type" name="report_type">
                                <option value="daily_summary" <?= $report_type === 'daily_summary' ? 'selected' : '' ?>>Daily Summary</option>
                                <option value="professor_activity" <?= $report_type === 'professor_activity' ? 'selected' : '' ?>>Professor Activity</option>
                                <option value="attendance_details" <?= $report_type === 'attendance_details' ? 'selected' : '' ?>>Attendance Details</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="present" <?= $status_filter === 'present' ? 'selected' : '' ?>>Present</option>
                                <option value="late" <?= $status_filter === 'late' ? 'selected' : '' ?>>Late</option>
                                <option value="absent" <?= $status_filter === 'absent' ? 'selected' : '' ?>>Absent</option>
                            </select>
                        </div>

                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-1"></i> Generate
                            </button>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-12">
                            <label class="form-label">Date Range</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="date_range" id="today" value="today" autocomplete="off" <?= $date_range === 'today' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="today">Today</label>

                                <input type="radio" class="btn-check" name="date_range" id="this_week" value="this_week" autocomplete="off" <?= $date_range === 'this_week' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="this_week">This Week</label>

                                <input type="radio" class="btn-check" name="date_range" id="this_month" value="this_month" autocomplete="off" <?= $date_range === 'this_month' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="this_month">This Month</label>

                                <input type="radio" class="btn-check" name="date_range" id="this_year" value="this_year" autocomplete="off" <?= $date_range === 'this_year' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="this_year">This Year</label>
                                
                                <input type="radio" class="btn-check" name="date_range" id="custom" value="custom" autocomplete="off" <?= $date_range === 'custom' ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="custom">Custom</label>
                            </div>
                            
                            <div class="custom-date-range <?= $date_range === 'custom' ? 'active' : '' ?>">
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <label for="start_date" class="form-label">Start Date</label>
                                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="end_date" class="form-label">End Date</label>
                                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Report Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card stat-card-primary">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="stat-label">Total Professors</h6>
                                    <h3 class="stat-value"><?= $attendance_stats['total_professors'] ?? 0 ?></h3>
                                    <small class="text-muted">Active faculty</small>
                                </div>
                                <div class="stat-icon icon-primary">
                                    <i class="fas fa-user-tie"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card stat-card-success">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="stat-label">Present</h6>
                                    <h3 class="stat-value"><?= $attendance_stats['present'] ?? 0 ?></h3>
                                    <small class="text-muted">On time records</small>
                                </div>
                                <div class="stat-icon icon-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card stat-card-warning">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="stat-label">Late Arrivals</h6>
                                    <h3 class="stat-value"><?= $attendance_stats['late_arrivals'] ?? 0 ?></h3>
                                    <small class="text-muted">Tardy records</small>
                                </div>
                                <div class="stat-icon icon-warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card stat-card-danger">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="stat-label">Absent</h6>
                                    <h3 class="stat-value"><?= $attendance_stats['absent'] ?? 0 ?></h3>
                                    <small class="text-muted">Missing records</small>
                                </div>
                                <div class="stat-icon icon-danger">
                                    <i class="fas fa-user-times"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Chart -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-chart-line me-2"></i> Attendance Trend
                        </div>
                        <div class="card-body">
                            <canvas id="attendanceChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Content -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?= $report_title ?></h5>
                    <div class="text-muted">
                        <?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="reportTable" class="table table-report table-hover" style="width:100%">
                            <thead>
                                <?php if ($report_type === 'daily_summary'): ?>
                                    <tr>
                                        <th>Date</th>
                                        <th>Professors</th>
                                        <th>Present</th>
                                        <th>Half-day</th>
                                        <th>Absent</th>
                                        <th>Late Arrivals</th>
                                        <th>Actions</th>
                                    </tr>
                                <?php elseif ($report_type === 'professor_activity'): ?>
                                    <tr>
                                        <th>Professor</th>
                                        <th>Department</th>
                                        <th>Time Ins</th>
                                        <th>Present Days</th>
                                        <th>Half-days</th>
                                        <th>Late Arrivals</th>
                                        <th>Avg. Duration</th>
                                        <th>Actions</th>
                                    </tr>
                                <?php elseif ($report_type === 'attendance_details'): ?>
                                    <tr>
                                        <th>Professor</th>
                                        <th>Date</th>
                                        <th>Day</th>
                                        <th>Scheduled Time</th>
                                        <th>Actual Time</th>
                                        <th>Status</th>
                                        <th>Duration</th>
                                        <th>Actions</th>
                                    </tr>
                                <?php endif; ?>
                            </thead>
                            <tbody>
                                <?php if ($report_data->num_rows > 0): ?>
                                    <?php $report_data->data_seek(0); ?>
                                    <?php while ($row = $report_data->fetch_assoc()): ?>
                                        <?php if ($report_type === 'daily_summary'): ?>
                                            <tr>
                                                <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                                                <td><?= $row['total_professors'] ?></td>
                                                <td><span class="badge bg-success"><?= $row['present'] ?></span></td>
                                                <td><span class="badge bg-warning text-dark"><?= $row['half_day'] ?></span></td>
                                                <td><span class="badge bg-danger"><?= $row['absent'] ?></span></td>
                                                <td><span class="badge bg-secondary"><?= $row['late_arrivals'] ?></span></td>
                                                <td>
                                                    <a href="manage-attendance.php?date=<?= $row['date'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php elseif ($report_type === 'professor_activity'): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['name']) ?></td>
                                                <td><?= htmlspecialchars($row['department']) ?></td>
                                                <td><?= $row['timeins'] ?></td>
                                                <td><?= $row['present'] ?></td>
                                                <td><?= $row['half_day'] ?></td>
                                                <td><?= $row['late_arrivals'] ?></td>
                                                <td>
                                                    <?php if ($row['avg_duration'] && $row['avg_duration'] != '00:00:00'): ?>
                                                        <?= substr($row['avg_duration'], 0, 5) ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="professor-attendance.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php elseif ($report_type === 'attendance_details'): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['name']) ?></td>
                                                <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                                                <td><?= htmlspecialchars($row['day_name']) ?></td>
                                                <td>
                                                    <?php if ($row['scheduled_time']): ?>
                                                        <?= date('h:i A', strtotime($row['scheduled_time'])) ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($row['actual_time']): ?>
                                                        <?= date('h:i A', strtotime($row['actual_time'])) ?>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($row['status'] === 'Absent'): ?>
                                                        <span class="badge bg-danger">Absent</span>
                                                    <?php elseif ($row['status'] === 'Late'): ?>
                                                        <span class="badge bg-warning text-dark">Late</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">On Time</span>
                                                    <?php endif; ?>
                                                    <?php if ($row['attendance_status'] === 'half-day'): ?>
                                                        <span class="badge bg-warning text-dark">Half Day</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $row['work_duration'] ? substr($row['work_duration'], 0, 5) : 'N/A' ?></td>
                                                <td>
                                                    <a href="professor-attendance.php?id=<?= $row['professor_id'] ?>&date=<?= $row['date'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?=
                                                        $report_type === 'daily_summary' ? 7 : 
                                                        ($report_type === 'professor_activity' ? 8 : 8)
                                                        ?>" class="text-center py-4">
                                            No records found for the selected filters
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="footer mt-4">
                &copy; <?php echo date('Y'); ?> Bicol University Polangui. All rights reserved.
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            $(document).ready(function() {
                // Show/hide custom date range inputs
                $('input[name="date_range"]').change(function() {
                    if ($(this).val() === 'custom') {
                        $('.custom-date-range').addClass('active');
                    } else {
                        $('.custom-date-range').removeClass('active');
                    }
                });

                // Initialize DataTable with matching export buttons
                const table = $('#reportTable').DataTable({
                    responsive: true,
                    dom: '<"top"<"d-flex justify-content-between align-items-center"lfB>>rt<"bottom"ip><"clear">',
                    buttons: [{
                            extend: 'copyHtml5',
                            text: '<i class="fas fa-copy me-1"></i> Copy',
                            className: 'btn btn-sm btn-secondary',
                            exportOptions: {
                                columns: ':visible'
                            }
                        },
                        {
                            extend: 'excelHtml5',
                            text: '<i class="fas fa-file-excel me-1"></i> Excel',
                            className: 'btn btn-sm btn-success',
                            title: 'Attendance_Records',
                            exportOptions: {
                                columns: ':visible'
                            }
                        },
                        {
                            extend: 'pdfHtml5',
                            text: '<i class="fas fa-file-pdf me-1"></i> PDF',
                            className: 'btn btn-sm btn-danger',
                            title: 'Attendance_Records',
                            exportOptions: {
                                columns: ':visible'
                            },
                            customize: function(doc) {
                                doc.defaultStyle.fontSize = 8;
                                doc.styles.tableHeader.fontSize = 9;
                                doc.pageMargins = [20, 20, 20, 20];
                            }
                        },
                        {
                            extend: 'print',
                            text: '<i class="fas fa-print me-1"></i> Print',
                            className: 'btn btn-sm btn-info',
                            title: 'Attendance Records',
                            exportOptions: {
                                columns: ':visible'
                            }
                        }
                    ],
                    pageLength: 10,
                    lengthMenu: [5, 10, 25, 50, 100]
                });

                // Attendance Chart
                const ctx = document.getElementById('attendanceChart').getContext('2d');
                const attendanceChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($days); ?>,
                        datasets: [{
                            label: 'Attendance Records',
                            data: <?php echo json_encode($attendance_chart_values); ?>,
                            borderColor: 'rgba(54, 162, 235, 1)',
                            backgroundColor: 'rgba(54, 162, 235, 0.2)',
                            tension: 0.3,
                            borderWidth: 2,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Professors'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Date'
                                }
                            }
                        },
                        animation: {
                            duration: 1000
                        }
                    }
                });
            });
        </script>
</body>
</html>