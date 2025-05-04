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

// Get current day info
$current_day_id = date('N'); // 1=Monday to 7=Sunday
$current_day_name = date('l');
$today = date('Y-m-d');
$current_time = date('H:i:s');

// Check if there are scheduled classes today
$has_scheduled_classes = $conn->query("
    SELECT COUNT(*) FROM professor_schedules 
    WHERE day_id = $current_day_id
")->fetch_row()[0] > 0;

// Dashboard Statistics
$stats = [
    'total_professors' => $conn->query("SELECT COUNT(*) FROM professors WHERE status = 'active'")->fetch_row()[0],
    'total_attendance_records' => $conn->query("SELECT COUNT(*) FROM attendance")->fetch_row()[0],
    'today_attendance' => $conn->query("SELECT COUNT(*) FROM attendance WHERE checkin_date = CURDATE()")->fetch_row()[0],
    'active_sessions' => $conn->query("SELECT COUNT(*) FROM attendance WHERE checkin_date = CURDATE() AND 
                                      ((am_check_in IS NOT NULL AND am_check_out IS NULL) OR 
                                       (pm_check_in IS NOT NULL AND pm_check_out IS NULL))")->fetch_row()[0],
];

// Get late arrivals for today with accurate schedule comparison
$late_arrivals = $conn->query("
    SELECT 
        p.id,
        p.name, 
        p.department,
        p.designation,
        CASE 
            WHEN a.am_check_in IS NOT NULL THEN TIME(a.am_check_in)
            WHEN a.pm_check_in IS NOT NULL THEN TIME(a.pm_check_in)
            ELSE NULL
        END as checkin_time,
        CASE 
            WHEN a.am_check_in IS NOT NULL THEN 'AM'
            WHEN a.pm_check_in IS NOT NULL THEN 'PM'
            ELSE NULL
        END as session,
        ps.start_time as scheduled_time,
        ps.subject,
        ps.room,
        TIMESTAMPDIFF(MINUTE, ps.start_time, 
            CASE 
                WHEN a.am_check_in IS NOT NULL THEN TIME(a.am_check_in)
                WHEN a.pm_check_in IS NOT NULL THEN TIME(a.pm_check_in)
                ELSE NULL
            END) as minutes_late
    FROM attendance a
    JOIN professors p ON a.professor_id = p.id
    JOIN professor_schedules ps ON ps.professor_id = a.professor_id 
        AND DAYOFWEEK(a.checkin_date) = ps.day_id
        AND DAYOFWEEK(CURDATE()) = ps.day_id
        AND (
            (TIME(a.am_check_in) BETWEEN ps.start_time AND ps.end_time) OR
            (TIME(a.pm_check_in) BETWEEN ps.start_time AND ps.end_time)
        )
    WHERE a.checkin_date = CURDATE()
    AND (
        (a.am_check_in IS NOT NULL AND TIME(a.am_check_in) > ps.start_time) OR
        (a.pm_check_in IS NOT NULL AND TIME(a.pm_check_in) > ps.start_time)
    )
    ORDER BY minutes_late DESC
");

// Get on-time professors for today with accurate schedule comparison
$on_time_professors = $conn->query("
    SELECT 
        p.id,
        p.name, 
        p.department,
        p.designation,
        CASE 
            WHEN a.am_check_in IS NOT NULL THEN TIME(a.am_check_in)
            WHEN a.pm_check_in IS NOT NULL THEN TIME(a.pm_check_in)
            ELSE NULL
        END as checkin_time,
        CASE 
            WHEN a.am_check_in IS NOT NULL THEN 'AM'
            WHEN a.pm_check_in IS NOT NULL THEN 'PM'
            ELSE NULL
        END as session,
        ps.start_time as scheduled_time,
        ps.subject,
        ps.room,
        TIMESTAMPDIFF(MINUTE, ps.start_time, 
            CASE 
                WHEN a.am_check_in IS NOT NULL THEN TIME(a.am_check_in)
                WHEN a.pm_check_in IS NOT NULL THEN TIME(a.pm_check_in)
                ELSE NULL
            END) as minutes_early
    FROM attendance a
    JOIN professors p ON a.professor_id = p.id
    JOIN professor_schedules ps ON ps.professor_id = a.professor_id 
        AND DAYOFWEEK(a.checkin_date) = ps.day_id
        AND DAYOFWEEK(CURDATE()) = ps.day_id
        AND (
            (TIME(a.am_check_in) BETWEEN ps.start_time AND ps.end_time) OR
            (TIME(a.pm_check_in) BETWEEN ps.start_time AND ps.end_time)
        )
    WHERE a.checkin_date = CURDATE()
    AND (
        (a.am_check_in IS NOT NULL AND TIME(a.am_check_in) <= ps.start_time) OR
        (a.pm_check_in IS NOT NULL AND TIME(a.pm_check_in) <= ps.start_time)
    )
    ORDER BY checkin_time ASC
");

// Recent Attendance (Last 10 records)
$recent_attendance = $conn->query("
    SELECT 
        a.*, 
        p.name, 
        p.designation,
        p.department,
        p.email,
        CASE 
            WHEN a.am_check_in IS NULL AND a.pm_check_in IS NULL THEN 'Absent'
            WHEN a.status = 'present' THEN 'Present'
            WHEN a.status = 'absent' THEN 'Absent'
            WHEN a.status = 'half-day' THEN 'Half Day'
            WHEN (a.am_check_in IS NOT NULL AND a.pm_check_out IS NULL) OR (a.pm_check_in IS NOT NULL AND a.pm_check_out IS NULL) THEN 'Active'
            ELSE 'Present'
        END as status,
        a.work_duration
    FROM attendance a
    JOIN professors p ON a.professor_id = p.id
    ORDER BY a.recorded_at DESC
    LIMIT 10
");

// Recent Notifications
$notifications = $conn->query("
    SELECT * FROM logs 
    ORDER BY timestamp DESC 
    LIMIT 5
");

// Get attendance data for the past 7 days for the chart
$attendance_data = $conn->query("
    SELECT 
        DATE(a.checkin_date) as day,
        COUNT(DISTINCT a.professor_id) as total_checkins
    FROM attendance a
    WHERE a.checkin_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
    GROUP BY DATE(a.checkin_date)
    ORDER BY day ASC
");

// Initialize arrays with default values for all 7 days
$days = [];
$attendance_chart_data = [];

// Get the last 7 days including today
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $day_name = date('D', strtotime($date));
    $days[] = $day_name;
    $attendance_chart_data[$day_name] = 0;
}

// Fill in actual data from the query
if ($attendance_data) {
    while ($row = $attendance_data->fetch_assoc()) {
        $day_name = date('D', strtotime($row['day']));
        $attendance_chart_data[$day_name] = $row['total_checkins'];
    }
}

// Convert to arrays in the correct order for the chart
$attendance_chart_values = array_values($attendance_chart_data);

// Get system activity data
$system_activity = $conn->query("
    SELECT 
        DATE(timestamp) as day,
        COUNT(*) as activity_count
    FROM logs
    WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(timestamp)
    ORDER BY day ASC
");

// Initialize system activity data
$system_activity_data = array_fill_keys($days, 0);

// Fill system activity data
if ($system_activity) {
    while ($row = $system_activity->fetch_assoc()) {
        $day_name = date('D', strtotime($row['day']));
        $system_activity_data[$day_name] = $row['activity_count'];
    }
}

// Convert to arrays for the chart
$system_activity_values = array_values($system_activity_data);

// Get time distribution data for histogram
$time_distribution_query = $conn->query("
    SELECT 
        SUM(TIME(am_check_in) BETWEEN '06:00:00' AND '08:00:00') as '6-8AM',
        SUM(TIME(am_check_in) BETWEEN '08:00:00' AND '10:00:00') as '8-10AM',
        SUM(TIME(am_check_in) BETWEEN '10:00:00' AND '12:00:00') as '10-12PM',
        SUM(TIME(pm_check_in) BETWEEN '12:00:00' AND '14:00:00') as '12-2PM',
        SUM(TIME(pm_check_in) BETWEEN '14:00:00' AND '16:00:00') as '2-4PM',
        SUM(TIME(pm_check_in) BETWEEN '16:00:00' AND '18:00:00') as '4-6PM',
        SUM(TIME(pm_check_in) >= '18:00:00' OR TIME(am_check_in) >= '18:00:00') as 'After 6PM'
    FROM attendance
    WHERE checkin_date = CURDATE()
");

$time_distribution = $time_distribution_query->fetch_assoc();

// Handle case when no data exists
if (!$time_distribution) {
    $time_distribution = [
        '6-8AM' => 0,
        '8-10AM' => 0,
        '10-12PM' => 0,
        '12-2PM' => 0,
        '2-4PM' => 0,
        '4-6PM' => 0,
        'After 6PM' => 0
    ];
}

// Prepare data for the chart
$time_distribution_data = [
    $time_distribution['6-8AM'],
    $time_distribution['8-10AM'],
    $time_distribution['10-12PM'],
    $time_distribution['12-2PM'],
    $time_distribution['2-4PM'],
    $time_distribution['4-6PM'],
    $time_distribution['After 6PM']
];

// PDF Report Generation Handler
if (isset($_POST['generate_pdf'])) {
    require_once '../tcpdf/tcpdf.php';

    class MYPDF extends TCPDF
    {
        public function Header()
        {
            $this->SetFont('helvetica', 'B', 12);
            $this->Cell(0, 15, 'Bicol University Polangui - Attendance Report', 0, false, 'C', 0, '', 0, false, 'M', 'M');
            $this->Ln(10);
        }

        public function Footer()
        {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        }
    }

    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('BUP Attendance System');
    $pdf->SetTitle('Attendance Report - ' . date('Y-m-d'));
    $pdf->SetHeaderData('', 0, '', '');
    $pdf->setHeaderFont(array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, 25);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->AddPage();

    // Report content
    $html = '<h2>Attendance Report (' . $_POST['start_date'] . ' to ' . $_POST['end_date'] . ')</h2>';
    $html .= '<table border="1" cellpadding="4">
        <tr style="background-color:#f2f2f2;">
            <th width="20%">Professor</th>
            <th width="10%">Date</th>
            <th width="12%">Time In</th>
            <th width="12%">Time Out</th>
            <th width="10%">Status</th>
            <th width="12%">Subject</th>
            <th width="12%">Hours</th>
        </tr>';

    $report_data = $conn->query("
        SELECT 
            p.name, 
            a.checkin_date, 
            CASE 
                WHEN a.am_check_in IS NOT NULL THEN TIME(a.am_check_in)
                ELSE TIME(a.pm_check_in)
            END as checkin_time,
            CASE 
                WHEN a.pm_check_out IS NOT NULL THEN TIME(a.pm_check_out)
                WHEN a.am_check_out IS NOT NULL THEN TIME(a.am_check_out)
                ELSE NULL
            END as checkout_time,
            a.work_duration,
            ps.subject,
            CASE 
                WHEN a.am_check_in IS NULL AND a.pm_check_in IS NULL THEN 'Absent'
                WHEN (a.am_check_in IS NOT NULL AND a.pm_check_out IS NULL) OR (a.pm_check_in IS NOT NULL AND a.pm_check_out IS NULL) THEN 'Active'
                ELSE 'Present'
            END as status
        FROM attendance a
        JOIN professors p ON a.professor_id = p.id
        LEFT JOIN professor_schedules ps ON ps.professor_id = a.professor_id AND DAYOFWEEK(a.checkin_date) = ps.day_id
        WHERE a.checkin_date BETWEEN '{$_POST['start_date']}' AND '{$_POST['end_date']}'
        ORDER BY a.checkin_date DESC
    ");

    while ($row = $report_data->fetch_assoc()) {
        $html .= '<tr>
            <td>' . $row['name'] . '</td>
            <td>' . $row['checkin_date'] . '</td>
            <td>' . ($row['checkin_time'] ? date('h:i A', strtotime($row['checkin_time'])) : '--') . '</td>
            <td>' . ($row['checkout_time'] ? date('h:i A', strtotime($row['checkout_time'])) : '--') . '</td>
            <td>' . $row['status'] . '</td>
            <td>' . ($row['subject'] ?? '--') . '</td>
            <td>' . ($row['work_duration'] ?: '--') . '</td>
        </tr>';
    }

    $html .= '</table>';
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('attendance_report_' . date('Ymd') . '.pdf', 'D');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Bicol University Polangui Admin Dashboard">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Dashboard | Bicol University Polangui</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        .chart-container {
            position: relative;
            height: 180px;
            width: 100%;
        }

        .attendance-status-badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }

        .badge-present {
            background-color: #28a745;
        }

        .badge-absent {
            background-color: #dc3545;
        }

        .badge-half-day {
            background-color: #fd7e14;
        }

        .badge-active {
            background-color: #17a2b8;
        }

        .badge-late {
            background-color: #ffc107;
            color: #212529;
        }

        .badge-on-time {
            background-color: #28a745;
            color: white;
        }

        .badge-early {
            background-color: #17a2b8;
            color: white;
        }

        .stat-card {
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .quick-action-card {
            padding: 1rem;
            border-radius: 8px;
            background: white;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid #eee;
            height: 100%;
        }

        .quick-action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-color: #4361ee;
        }

        .quick-action-card .action-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.25rem;
        }

        .pending-approvals-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid #eee;
        }

        .pending-approvals-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-color: #4361ee;
        }

        .pending-approvals-card .pending-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            font-size: 1.25rem;
        }

        .punctuality-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid #eee;
            height: 100%;
        }

        .punctuality-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .punctuality-card .card-header {
            border-bottom: 1px solid #eee;
            padding-bottom: 0.75rem;
            margin-bottom: 1rem;
        }

        .punctuality-card .professor-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid #f5f5f5;
        }

        .punctuality-card .professor-item:last-child {
            border-bottom: none;
        }

        .minutes-late {
            font-weight: bold;
            color: #dc3545;
        }

        .minutes-early {
            font-weight: bold;
            color: #28a745;
        }

        .subject-info {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 3px;
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
                <h1>Dashboard</h1>
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
            <!-- Statistics Cards -->
            <div class="row g-3 mb-4">
                <!-- Total Professors -->
                <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6 col-12">
                    <div class="card border-start border-primary border-4 h-100 stat-card">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="text-muted mb-1" style="font-size: 0.8rem;">Total Professors</h6>
                                    <h4 class="mb-0"><?php echo $stats['total_professors']; ?></h4>
                                    <small class="text-muted" style="font-size: 0.7rem;">Active faculty members</small>
                                </div>
                                <div class="ms-2 bg-primary bg-opacity-10 p-2 rounded">
                                    <i class="fas fa-user-tie text-primary fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Attendance -->
                <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6 col-12">
                    <div class="card border-start border-info border-4 h-100 stat-card">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="text-muted mb-1" style="font-size: 0.8rem;">Today's Attendance</h6>
                                    <h4 class="mb-0"><?php echo $stats['today_attendance']; ?></h4>
                                    <small class="text-muted" style="font-size: 0.7rem;">Check-ins today</small>
                                </div>
                                <div class="ms-2 bg-info bg-opacity-10 p-2 rounded">
                                    <i class="fas fa-clipboard-check text-info fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Active Sessions -->
                <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6 col-12">
                    <div class="card border-start border-success border-4 h-100 stat-card">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="text-muted mb-1" style="font-size: 0.8rem;">Active Sessions</h6>
                                    <h4 class="mb-0"><?php echo $stats['active_sessions']; ?></h4>
                                    <small class="text-muted" style="font-size: 0.7rem;">Currently in class</small>
                                </div>
                                <div class="ms-2 bg-success bg-opacity-10 p-2 rounded">
                                    <i class="fas fa-chalkboard-teacher text-success fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Total Records -->
                <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6 col-12">
                    <div class="card border-start border-secondary border-4 h-100 stat-card">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="text-muted mb-1" style="font-size: 0.8rem;">Total Records</h6>
                                    <h4 class="mb-0"><?php echo $stats['total_attendance_records']; ?></h4>
                                    <small class="text-muted" style="font-size: 0.7rem;">All-time attendance</small>
                                </div>
                                <div class="ms-2 bg-secondary bg-opacity-10 p-2 rounded">
                                    <i class="fas fa-database text-secondary fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Punctuality Section -->
            <div class="row mt-4">
                <?php if ($has_scheduled_classes): ?>
                    <!-- Late Arrivals -->
                    <div class="col-md-6">
                        <div class="punctuality-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-clock text-danger me-2"></i> Late Arrivals</h5>
                                <span class="badge bg-danger"><?= $late_arrivals->num_rows ?></span>
                            </div>
                            <div class="card-body">
                                <?php if ($late_arrivals->num_rows > 0): ?>
                                    <?php while ($late = $late_arrivals->fetch_assoc()): ?>
                                        <div class="professor-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?= htmlspecialchars($late['name']) ?></strong>
                                                    <small class="text-muted d-block"><?= htmlspecialchars($late['department']) ?> - <?= htmlspecialchars($late['designation']) ?></small>
                                                    <div class="subject-info">
                                                        <?= htmlspecialchars($late['subject']) ?> - <?= htmlspecialchars($late['room']) ?>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge badge-late"><?= $late['session'] ?> Session</span>
                                                    <div class="minutes-late">
                                                        <?= $late['minutes_late'] ?> min late
                                                    </div>
                                                    <small class="text-muted">
                                                        Actual: <?= date('h:i A', strtotime($late['checkin_time'])) ?><br>
                                                        Scheduled: <?= date('h:i A', strtotime($late['scheduled_time'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-center py-3 text-muted">
                                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                        <p>No late arrivals today</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- On Time Professors -->
                    <div class="col-md-6">
                        <div class="punctuality-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-check-circle text-success me-2"></i> On Time Professors</h5>
                                <span class="badge bg-success"><?= $on_time_professors->num_rows ?></span>
                            </div>
                            <div class="card-body">
                                <?php if ($on_time_professors->num_rows > 0): ?>
                                    <?php while ($on_time = $on_time_professors->fetch_assoc()): ?>
                                        <div class="professor-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?= htmlspecialchars($on_time['name']) ?></strong>
                                                    <small class="text-muted d-block"><?= htmlspecialchars($on_time['department']) ?> - <?= htmlspecialchars($on_time['designation']) ?></small>
                                                    <div class="subject-info">
                                                        <?= htmlspecialchars($on_time['subject']) ?> - <?= htmlspecialchars($on_time['room']) ?>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge <?= $on_time['minutes_early'] > 0 ? 'badge-early' : 'badge-on-time' ?>">
                                                        <?= $on_time['session'] ?> Session
                                                    </span>
                                                    <?php if ($on_time['minutes_early'] > 0): ?>
                                                        <div class="minutes-early">
                                                            <?= $on_time['minutes_early'] ?> min early
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-success">
                                                            On Time
                                                        </div>
                                                    <?php endif; ?>
                                                    <small class="text-muted">
                                                        Arrived: <?= date('h:i A', strtotime($on_time['checkin_time'])) ?><br>
                                                        Scheduled: <?= date('h:i A', strtotime($on_time['scheduled_time'])) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-center py-3 text-muted">
                                        <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                                        <p>No on-time check-ins recorded yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            No classes are scheduled for today (<?= $current_day_name ?>).
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="row mt-4">
                <!-- Activity Overview -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-chart-line me-2"></i> Weekly Attendance & System Activity
                        </div>
                        <div class="card-body">
                            <canvas id="activityChart" height="250"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Recent Notifications -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-bell me-2"></i> Recent Notifications
                        </div>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php while ($log = $notifications->fetch_assoc()): ?>
                                    <a href="#" class="list-group-item list-group-item-action">
                                        <div class="d-flex justify-content-between">
                                            <span><?php echo htmlspecialchars($log['action']); ?></span>
                                            <small class="text-muted"><?php echo date('h:i A', strtotime($log['timestamp'])); ?></small>
                                        </div>
                                        <?php if ($log['user']): ?>
                                            <small class="text-muted">By <?php echo htmlspecialchars($log['user']); ?></small>
                                        <?php endif; ?>
                                    </a>
                                <?php endwhile; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Attendance Logs -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-table me-2"></i> Recent Attendance Logs
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Department</th>
                                            <th>Designation</th>
                                            <th>Time In</th>
                                            <th>Time Out</th>
                                            <th>Status</th>
                                            <th>Work Duration</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($attendance = $recent_attendance->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($attendance['name']); ?></td>
                                                <td><?php echo htmlspecialchars($attendance['department']); ?></td>
                                                <td><?php echo htmlspecialchars($attendance['designation']); ?></td>
                                                <td>
                                                    <?php if ($attendance['am_check_in']): ?>
                                                        AM: <?= date('h:i A', strtotime($attendance['am_check_in'])) ?><br>
                                                    <?php endif; ?>
                                                    <?php if ($attendance['pm_check_in']): ?>
                                                        PM: <?= date('h:i A', strtotime($attendance['pm_check_in'])) ?>
                                                    <?php endif; ?>
                                                    <?php if (!$attendance['am_check_in'] && !$attendance['pm_check_in']): ?>
                                                        --
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($attendance['pm_check_out']): ?>
                                                        <?= date('h:i A', strtotime($attendance['pm_check_out'])) ?>
                                                    <?php elseif ($attendance['am_check_out']): ?>
                                                        <?= date('h:i A', strtotime($attendance['am_check_out'])) ?>
                                                    <?php else: ?>
                                                        --
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge attendance-status-badge 
                                                        <?php
                                                        switch ($attendance['status']) {
                                                            case 'Present':
                                                                echo 'badge-present';
                                                                break;
                                                            case 'Absent':
                                                                echo 'badge-absent';
                                                                break;
                                                            case 'Half Day':
                                                                echo 'badge-half-day';
                                                                break;
                                                            case 'Active':
                                                                echo 'badge-active';
                                                                break;
                                                            default:
                                                                echo 'bg-secondary';
                                                        }
                                                        ?>">
                                                        <?php echo htmlspecialchars($attendance['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($attendance['work_duration']): ?>
                                                        <?php
                                                        $parts = explode(':', $attendance['work_duration']);
                                                        $hours = (int)$parts[0];
                                                        $minutes = (int)$parts[1];
                                                        echo "$hours hrs $minutes mins";
                                                        ?>
                                                    <?php else: ?>
                                                        --
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary view-attendance-btn"
                                                        data-id="<?php echo $attendance['id']; ?>">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Generation and Quick Actions -->
            <div class="row mt-4">
                <!-- Left Column -->
                <div class="col-md-6">
                    <!-- Today's Time In Times Card -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-clock me-2"></i> Today's Time In Times</span>
                            <small class="text-muted"><?= date('M j, Y') ?></small>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="checkinHistogram"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Generate Report Card -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <i class="fas fa-file-pdf me-2"></i> Generate Attendance Report
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" name="start_date" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">End Date</label>
                                            <input type="date" name="end_date" class="form-control" required>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" name="generate_pdf" class="btn btn-danger w-100">
                                    <i class="fas fa-file-pdf me-1"></i> Generate PDF Report
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions Section -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <i class="fas fa-bolt me-2"></i> Quick Actions
                        </div>
                        <div class="card-body p-3">
                            <div class="row g-3">
                                <!-- Add Professor -->
                                <div class="col-6">
                                    <div class="quick-action-card" onclick="window.location.href='add-professor.php?action=add'">
                                        <div class="action-icon bg-primary bg-opacity-10 text-primary">
                                            <i class="fas fa-user-plus"></i>
                                        </div>
                                        <h6>Add Professor</h6>
                                        <small class="text-muted">Register new faculty</small>
                                    </div>
                                </div>

                                <!-- View Attendance -->
                                <div class="col-6">
                                    <div class="quick-action-card" onclick="window.location.href='manage-attendance.php'">
                                        <div class="action-icon bg-success bg-opacity-10 text-success">
                                            <i class="fas fa-clipboard-list"></i>
                                        </div>
                                        <h6>View Attendance</h6>
                                        <small class="text-muted">Check daily logs</small>
                                    </div>
                                </div>

                                <!-- View Reports -->
                                <div class="col-6">
                                    <div class="quick-action-card" onclick="window.location.href='reports.php'">
                                        <div class="action-icon bg-info bg-opacity-10 text-info">
                                            <i class="fas fa-chart-pie"></i>
                                        </div>
                                        <h6>View Reports</h6>
                                        <small class="text-muted">Generate analytics</small>
                                    </div>
                                </div>

                                <!-- Manual Entry -->
                                <div class="col-6">
                                    <div class="quick-action-card" onclick="window.location.href='add-attendance.php'">
                                        <div class="action-icon bg-warning bg-opacity-10 text-warning">
                                            <i class="fas fa-plus-circle"></i>
                                        </div>
                                        <h6>Manual Entry</h6>
                                        <small class="text-muted">Create new record</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Add Attendance Card -->
                            <div class="pending-approvals-card mt-4" onclick="window.location.href='add-attendance.php'">
                                <div class="d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <h6 class="text-muted mb-1">Add Attendance</h6>
                                        <h4 class="mb-0"><i class="fas fa-plus"></i></h4>
                                        <div class="d-flex align-items-center mt-1">
                                            <span class="text-primary small">Add Now</span>
                                            <i class="fas fa-arrow-right ms-2 small text-primary"></i>
                                        </div>
                                    </div>
                                    <div class="pending-icon">
                                        <i class="fas fa-calendar-plus"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="footer mt-4">
                &copy; <?php echo date('Y'); ?> Bicol University Polangui. All rights reserved.
            </div>
        </div>

        <!-- View Attendance Modal -->
        <div class="modal fade" id="attendanceDetailModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Attendance Details</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Content will be dynamically inserted here -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            // Wait for DOM to be fully loaded
            document.addEventListener('DOMContentLoaded', function() {
                // Activity Chart with two datasets
                const ctx = document.getElementById('activityChart').getContext('2d');
                const activityChart = new Chart(ctx, {
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
                                yAxisID: 'y'
                            },
                            {
                                label: 'System Activity',
                                data: <?php echo json_encode($system_activity_values); ?>,
                                borderColor: 'rgba(255, 99, 132, 1)',
                                backgroundColor: 'rgba(255, 99, 132, 0.2)',
                                tension: 0.3,
                                borderWidth: 2,
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
                            }
                        },
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Attendance Records'
                                },
                                beginAtZero: true
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'System Activity'
                                },
                                beginAtZero: true,
                                // grid line settings
                                grid: {
                                    drawOnChartArea: false, // only want the grid lines for one axis to show up
                                },
                            }
                        },
                        animation: {
                            duration: 1000
                        }
                    }
                });

                // Time In Histogram Chart
                const timeCtx = document.getElementById('checkinHistogram').getContext('2d');
                const timeHistogram = new Chart(timeCtx, {
                    type: 'bar',
                    data: {
                        labels: ['6-8AM', '8-10AM', '10-12PM', '12-2PM', '2-4PM', '4-6PM', 'After 6PM'],
                        datasets: [{
                            label: 'Number of Time Ins',
                            data: <?php echo json_encode($time_distribution_data); ?>,
                            backgroundColor: 'rgba(54, 185, 204, 0.7)',
                            borderColor: 'rgba(44, 123, 229, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y + ' professors';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0,
                                    stepSize: 1
                                },
                                title: {
                                    display: true,
                                    text: 'Number of Professors'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Time Blocks'
                                }
                            }
                        },
                        animation: {
                            duration: 1000
                        }
                    }
                });

                // View Attendance Button Functionality
                document.querySelectorAll('.view-attendance-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const attendanceId = this.getAttribute('data-id');
                        const row = this.closest('tr');
                        const name = row.cells[0].textContent;
                        const department = row.cells[1].textContent;
                        const designation = row.cells[2].textContent;
                        const timeIn = row.cells[3].textContent;
                        const timeOut = row.cells[4].textContent;
                        const status = row.cells[5].textContent;
                        const workDuration = row.cells[6].textContent;

                        // Create modal content
                        const modalContent = `
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h6>Professor Information</h6>
                                        <hr>
                                        <p><strong>Name:</strong> ${name}</p>
                                        <p><strong>Department:</strong> ${department}</p>
                                        <p><strong>Designation:</strong> ${designation}</p>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <h6>Attendance Summary</h6>
                                        <hr>
                                        <p><strong>Status:</strong> 
                                            <span class="badge ${status === 'Absent' ? 'badge-absent' : 
                                                (status === 'Half Day' ? 'badge-half-day' : 
                                                (status === 'Active' ? 'badge-active' : 'badge-present'))}">
                                                ${status}
                                            </span>
                                        </p>
                                        <p><strong>Work Duration:</strong> ${workDuration}</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6>Attendance Details</h6>
                                    <hr>
                                    <p><strong>Time In:</strong> ${timeIn}</p>
                                    <p><strong>Time Out:</strong> ${timeOut}</p>
                                </div>
                            </div>
                        `;

                        // Insert content into modal and show it
                        document.querySelector('#attendanceDetailModal .modal-body').innerHTML = modalContent;
                        const modal = new bootstrap.Modal(document.getElementById('attendanceDetailModal'));
                        modal.show();
                    });
                });

                // Real-time notifications functionality
                let lastNotificationTimestamp = null;

                function fetchNewNotifications() {
                    fetch(`realtime-notifications.php?last_timestamp=${lastNotificationTimestamp}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.notifications && data.notifications.length > 0) {
                                updateNotificationsUI(data.notifications);
                                lastNotificationTimestamp = data.last_timestamp;

                                // Show desktop notification for new alerts
                                if (Notification.permission === "granted") {
                                    data.notifications.forEach(notif => {
                                        new Notification("New Notification", {
                                            body: notif.action
                                        });
                                    });
                                }
                            }
                        })
                        .catch(error => console.error('Error fetching notifications:', error));
                }

                function updateNotificationsUI(notifications) {
                    const notificationList = document.querySelector('.list-group');

                    // Prepend new notifications
                    notifications.reverse().forEach(notif => {
                        const notificationItem = document.createElement('a');
                        notificationItem.className = 'list-group-item list-group-item-action';
                        notificationItem.href = '#';
                        notificationItem.innerHTML = `
                            <div class="d-flex justify-content-between">
                                <span>${notif.action}</span>
                                <small class="text-muted">${notif.time_formatted}</small>
                            </div>
                            ${notif.user ? `<small class="text-muted">By ${notif.user}</small>` : ''}
                        `;
                        notificationList.prepend(notificationItem);
                    });

                    // Play notification sound
                    const audio = new Audio('../assets/sounds/notification.mp3');
                    audio.play().catch(e => console.log('Audio play failed:', e));

                    // Limit to 5 notifications
                    while (notificationList.children.length > 5) {
                        notificationList.removeChild(notificationList.lastChild);
                    }
                }

                // Request notification permission
                if (window.Notification && Notification.permission !== "granted") {
                    Notification.requestPermission();
                }

                // Initial load and then poll every 5 seconds
                fetchNewNotifications();
                setInterval(fetchNewNotifications, 5000);

                // Also check for notifications when the page becomes visible again
                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) {
                        fetchNewNotifications();
                    }
                });
            });
        </script>
</body>

</html>