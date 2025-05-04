<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Authentication check
$professorId = $_SESSION['professor_id'] ?? null;
if (!$professorId) {
    header('Location: ../pages/login.php');
    exit;
}

// Function to calculate duration between two times
function calculateDuration($start, $end)
{
    if (!$start || !$end) return '-';

    $startTime = new DateTime($start);
    $endTime = new DateTime($end);
    $interval = $startTime->diff($endTime);

    return $interval->format('%H:%I:%S');
}

// Function to sum two time durations
function sumDurations($time1, $time2)
{
    if ($time1 === '-' && $time2 === '-') return '-';
    
    $seconds = 0;
    
    if ($time1 !== '-') {
        list($h, $m, $s) = explode(':', $time1);
        $seconds += $h * 3600 + $m * 60 + $s;
    }
    
    if ($time2 !== '-') {
        list($h, $m, $s) = explode(':', $time2);
        $seconds += $h * 3600 + $m * 60 + $s;
    }
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

// Initialize variables
$showModal = false;
$modalTitle = '';
$modalMessage = '';
$modalType = '';

// Get professor details
$professor = [];
$stmt = $conn->prepare("SELECT name, department, designation, email, phone FROM professors WHERE id = ?");
$stmt->bind_param("i", $professorId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $professor = $result->fetch_assoc();
}
$stmt->close();

// Get summary statistics
$summaryStmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT date) AS total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
        SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) AS half_days,
        SEC_TO_TIME(AVG(
            CASE 
                WHEN am_check_in IS NOT NULL AND pm_check_out IS NOT NULL THEN 
                    TIMESTAMPDIFF(SECOND, am_check_in, pm_check_out)
                WHEN am_check_in IS NOT NULL AND am_check_out IS NOT NULL THEN 
                    TIMESTAMPDIFF(SECOND, am_check_in, am_check_out)
                WHEN pm_check_in IS NOT NULL AND pm_check_out IS NOT NULL THEN 
                    TIMESTAMPDIFF(SECOND, pm_check_in, pm_check_out)
                ELSE 0
            END
        )) AS avg_duration
    FROM attendance 
    WHERE professor_id = ?
");
$summaryStmt->bind_param("i", $professorId);
$summaryStmt->execute();
$summaryResult = $summaryStmt->get_result();
$summaryData = $summaryResult->fetch_assoc();
$summaryStmt->close();

// Get attendance records - Modified to group by date
$recordsStmt = $conn->prepare("
    SELECT 
        date,
        MAX(am_check_in) as am_check_in,
        MAX(am_check_out) as am_check_out,
        MAX(pm_check_in) as pm_check_in,
        MAX(pm_check_out) as pm_check_out,
        MAX(status) as status,
        MAX(work_duration) as work_duration
    FROM attendance
    WHERE professor_id = ?
    GROUP BY date
    ORDER BY date DESC
");
$recordsStmt->bind_param("i", $professorId);
$recordsStmt->execute();
$records = $recordsStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance Report | University Portal</title>

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
            margin-bottom: 20px;
            padding: 20px;
            background-color: var(--primary-light);
            border-radius: 8px;
            text-align: center;
            margin-top: -20px;
            padding-top: 25px;
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

        .professor-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .stat-card {
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
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

        .badge-am {
            background-color: #17a2b8;
        }

        .badge-pm {
            background-color: #6c757d;
        }

        .badge-full-day {
            background-color: #6610f2;
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
            background-color: var(--primary-light) !important;
        }

        .table-attendance td {
            vertical-align: middle;
        }

        .filter-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            margin-bottom: 20px;
            padding: 15px;
            transition: all 0.3s;
        }

        .filter-card:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #3a56d4;
            border-color: #3a56d4;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.25);
        }

        /* Make download buttons always visible */
        .dt-buttons {
            margin-bottom: 15px;
        }

        .dt-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
            opacity: 1 !important;
            visibility: visible !important;
        }

        /* Navbar link fix */
        .navbar-nav .nav-link {
            font-weight: normal !important;
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

            .stat-card .stat-value {
                font-size: 1.3rem;
            }
        }

        .page-header {
            margin-bottom: 2rem;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .page-header h1 {
            font-weight: 600;
            color: #2c3e50;
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 1.1rem;
        }

        body {
            font-family: 'Poppins', sans-serif;
        }
    </style>
</head>

<body>
    <?php include '../includes/navbar.php'; ?>

    <div class="container-fluid py-4">
        <div class="main-container">
            <!-- Header Section -->
            <div class="page-header">
                <h1><i class="fas fa-clipboard-list me-2"></i>My Attendance Report</h1>
                <p class="page-subtitle">View your attendance records and statistics</p>
            </div>

            <!-- Professor Info Card -->
            <div class="card mb-4 professor-card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h4 class="mb-3 text-primary"><?= htmlspecialchars($professor['name'] ?? 'N/A') ?></h4>
                            <div class="professor-detail">
                                <div class="text-muted small mb-1">Department</div>
                                <div><?= htmlspecialchars($professor['department'] ?? 'N/A') ?></div>
                            </div>
                            <div class="professor-detail mt-2">
                                <div class="text-muted small mb-1">Designation</div>
                                <div><?= htmlspecialchars($professor['designation'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="professor-detail">
                                <div class="text-muted small mb-1">Email</div>
                                <div>
                                    <a href="mailto:<?= htmlspecialchars($professor['email'] ?? '') ?>" class="text-decoration-none">
                                        <?= htmlspecialchars($professor['email'] ?? 'N/A') ?>
                                    </a>
                                </div>
                            </div>
                            <div class="professor-detail mt-2">
                                <div class="text-muted small mb-1">Phone</div>
                                <div><?= htmlspecialchars($professor['phone'] ?? 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card bg-primary bg-opacity-10 border-0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="stat-label">Total Days</h6>
                                    <h3 class="stat-value"><?= $summaryData['total_days'] ?? 0 ?></h3>
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
                                    <h6 class="stat-label">Present Days</h6>
                                    <h3 class="stat-value"><?= $summaryData['present_days'] ?? 0 ?></h3>
                                </div>
                                <div class="stat-icon text-success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card stat-card bg-danger bg-opacity-10 border-0">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="stat-label">Absent Days</h6>
                                    <h3 class="stat-value"><?= $summaryData['absent_days'] ?? 0 ?></h3>
                                </div>
                                <div class="stat-icon text-danger">
                                    <i class="fas fa-times-circle"></i>
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
                                        <?php if ($summaryData['avg_duration'] && $summaryData['avg_duration'] !== '00:00:00'): ?>
                                            <?= substr($summaryData['avg_duration'], 0, 5) ?>
                                        <?php else: ?>
                                            0:00
                                        <?php endif; ?>
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

            <!-- Filters Section -->
            <div class="card mb-4 filter-card">
                <div class="card-body">
                    <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filters</h5>
                    <form id="filter-form">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" id="start-date">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" id="end-date">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="status-filter">
                                    <option value="">All Status</option>
                                    <option value="present">Present</option>
                                    <option value="absent">Absent</option>
                                    <option value="half-day">Half Day</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Session</label>
                                <select class="form-select" id="session-filter">
                                    <option value="">All Sessions</option>
                                    <option value="AM">AM Session</option>
                                    <option value="PM">PM Session</option>
                                    <option value="Full">Full Day</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <button type="button" class="btn btn-primary" id="apply-filters">
                                    <i class="fas fa-filter me-2"></i> Apply Filters
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="reset-filters">
                                    <i class="fas fa-sync-alt me-2"></i> Reset
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Attendance Table -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-attendance" id="attendance-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Session</th>
                                    <th>AM Time In</th>
                                    <th>AM Time Out</th>
                                    <th>PM Time In</th>
                                    <th>PM Time Out</th>
                                    <th>AM Duration</th>
                                    <th>PM Duration</th>
                                    <th>Total Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $records->fetch_assoc()): ?>
                                    <?php
                                    $date = date("M d, Y", strtotime($row['date']));

                                    // AM Session Data
                                    $amTimeIn = $row['am_check_in'] ? date("h:i A", strtotime($row['am_check_in'])) : '-';
                                    $amTimeOut = $row['am_check_out'] ? date("h:i A", strtotime($row['am_check_out'])) : '-';
                                    $amDuration = ($row['am_check_in'] && $row['am_check_out']) ?
                                        calculateDuration($row['am_check_in'], $row['am_check_out']) : '-';

                                    // PM Session Data
                                    $pmTimeIn = $row['pm_check_in'] ? date("h:i A", strtotime($row['pm_check_in'])) : '-';
                                    $pmTimeOut = $row['pm_check_out'] ? date("h:i A", strtotime($row['pm_check_out'])) : '-';
                                    $pmDuration = ($row['pm_check_in'] && $row['pm_check_out']) ?
                                        calculateDuration($row['pm_check_in'], $row['pm_check_out']) : '-';

                                    // Calculate total duration
                                    $totalDuration = sumDurations($amDuration, $pmDuration);
                                    
                                    // Determine session type
                                    $hasAM = $row['am_check_in'] !== null;
                                    $hasPM = $row['pm_check_in'] !== null;
                                    
                                    // Status badge class
                                    $statusClass = '';
                                    switch ($row['status']) {
                                        case 'present':
                                            $statusClass = 'badge-present';
                                            break;
                                        case 'half-day':
                                            $statusClass = 'badge-half-day';
                                            break;
                                        case 'absent':
                                            $statusClass = 'badge-absent';
                                            break;
                                        default:
                                            $statusClass = 'bg-secondary';
                                    }

                                    // Session badge class and text
                                    $sessionClass = '';
                                    $sessionText = '';
                                    
                                    if ($hasAM && $hasPM) {
                                        $sessionClass = 'badge-full-day';
                                        $sessionText = 'Full Day';
                                    } elseif ($hasAM) {
                                        $sessionClass = 'badge-am';
                                        $sessionText = 'AM Only';
                                    } elseif ($hasPM) {
                                        $sessionClass = 'badge-pm';
                                        $sessionText = 'PM Only';
                                    } else {
                                        $sessionClass = 'bg-secondary';
                                        $sessionText = 'No Session';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= $date ?></td>
                                        <td><span class='badge <?= $sessionClass ?>'><?= $sessionText ?></span></td>
                                        <td><?= $amTimeIn ?></td>
                                        <td><?= $amTimeOut ?></td>
                                        <td><?= $pmTimeIn ?></td>
                                        <td><?= $pmTimeOut ?></td>
                                        <td><?= $amDuration !== '-' ? substr($amDuration, 0, 5) : '-' ?></td>
                                        <td><?= $pmDuration !== '-' ? substr($pmDuration, 0, 5) : '-' ?></td>
                                        <td>
                                            <?php if ($totalDuration !== '-'): ?>
                                                <?php
                                                list($hours, $minutes, $seconds) = explode(':', $totalDuration);
                                                echo "$hours hrs " . str_pad($minutes, 2, '0', STR_PAD_LEFT) . " mins";
                                                ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><span class='badge <?= $statusClass ?>'><?= ucfirst($row['status']) ?></span></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
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
        $(document).ready(function() {
            // Initialize DataTable
            var table = $('#attendance-table').DataTable({
                lengthMenu: [
                    [5, 10, 25, 50, -1],
                    [5, 10, 25, 50, "All"]
                ],
                pageLength: 10,
                dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" +
                    "<'row'<'col-sm-12'tr>>" +
                    "<'row'<'col-sm-12'i>>" +
                    "<'row'<'col-sm-6'B><'col-sm-6'p>>",
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
                ],
                language: {
                    search: "",
                    searchPlaceholder: "Search records...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                initComplete: function() {
                    let searchContainer = $('.dataTables_filter');
                    let searchInput = searchContainer.find('input');

                    // Wrap input with a div for styling
                    searchContainer.html(`
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            ${searchInput.prop('outerHTML')}
                        </div>
                    `);

                    // Select the new input and adjust styling
                    let newSearchInput = searchContainer.find('input');
                    newSearchInput.addClass('form-control')
                        .css({
                            "width": "280px",
                            "border-left": "0"
                        });
                }
            });

            // Set default date range (current month)
            let today = new Date();
            let firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            $('#start-date').val(firstDay.toISOString().split('T')[0]);
            $('#end-date').val(today.toISOString().split('T')[0]);

            // Apply filters
            $('#apply-filters').click(function() {
                var startDate = $('#start-date').val();
                var endDate = $('#end-date').val();
                var status = $('#status-filter').val();
                var session = $('#session-filter').val();

                // Clear previous filters
                table.columns().search('').draw();
                $.fn.dataTable.ext.search.pop();

                // Filter by date range
                if (startDate || endDate) {
                    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                        var rowDate = new Date(data[0]);
                        var min = startDate ? new Date(startDate) : null;
                        var max = endDate ? new Date(endDate) : null;

                        if ((min === null || rowDate >= min) &&
                            (max === null || rowDate <= max)) {
                            return true;
                        }
                        return false;
                    });
                }

                // Filter by status (column 9)
                if (status) {
                    table.column(9).search(status, true, false).draw();
                }

                // Filter by session type (column 1)
                if (session) {
                    if (session === 'Full') {
                        table.column(1).search('Full Day', true, false).draw();
                    } else if (session === 'AM') {
                        table.column(1).search('AM Only', true, false).draw();
                    } else if (session === 'PM') {
                        table.column(1).search('PM Only', true, false).draw();
                    }
                }

                table.draw();

                // Show notification
                let filterCount = table.rows({
                    filter: 'applied'
                }).count();
                let notification = `<div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i> Showing ${filterCount} filtered records
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>`;

                $('.alert').remove();
                $('.main-container').prepend(notification);
            });

            // Reset filters
            $('#reset-filters').click(function() {
                $('#start-date').val(firstDay.toISOString().split('T')[0]);
                $('#end-date').val(today.toISOString().split('T')[0]);
                $('#status-filter').val('');
                $('#session-filter').val('');
                $('#apply-filters').click();
            });
        });
    </script>
</body>

</html>
<?php
// Close database connection
$recordsStmt->close();
$conn->close();
?>