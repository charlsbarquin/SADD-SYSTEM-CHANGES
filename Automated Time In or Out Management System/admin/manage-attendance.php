<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Authentication check
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin-login.php');
    exit;
}

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get filter parameters with sanitization
$date_filter = isset($_GET['date']) ? $conn->real_escape_string($_GET['date']) : '';
$professor_filter = isset($_GET['professor']) && is_numeric($_GET['professor']) ? (int)$_GET['professor'] : 'all';
$status_filter = isset($_GET['status']) && in_array($_GET['status'], ['all', 'present', 'half-day', 'absent']) ? $_GET['status'] : 'all';

// Build base query - Modified to group by professor and date
$query = "SELECT 
            a.id as attendance_id, 
            p.id as professor_db_id,
            a.professor_id,
            a.checkin_date as date,
            MAX(a.am_check_in) as am_check_in,
            MAX(a.am_check_out) as am_check_out,
            MAX(a.pm_check_in) as pm_check_in,
            MAX(a.pm_check_out) as pm_check_out,
            MAX(a.status) as status,
            MAX(a.work_duration) as work_duration,
            p.name as professor_name, 
            p.department, 
            p.designation,
            CASE 
                WHEN MAX(a.am_check_in) IS NOT NULL AND MAX(a.pm_check_in) IS NOT NULL THEN 'present'
                WHEN (MAX(a.am_check_in) IS NOT NULL OR MAX(a.pm_check_in) IS NOT NULL) THEN 'half-day'
                ELSE 'absent'
            END as calculated_status
          FROM attendance a
          JOIN professors p ON a.professor_id = p.id";

// Apply filters
$where = [];
$params = [];
$types = '';

if (!empty($date_filter)) {
    $where[] = "a.checkin_date = ?";
    $params[] = $date_filter;
    $types .= 's';
}

if ($professor_filter !== 'all') {
    $where[] = "a.professor_id = ?";
    $params[] = $professor_filter;
    $types .= 'i';
}

if ($status_filter !== 'all') {
    $where[] = "a.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

$query .= " GROUP BY a.professor_id, a.checkin_date";
$query .= " ORDER BY a.checkin_date DESC, p.name ASC";

// Prepare and execute query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$attendance = $stmt->get_result();

// Get professors for filter dropdown
$professors = $conn->query("SELECT id, name FROM professors ORDER BY name");

// Get counts for dashboard
$total_attendance = $conn->query("SELECT COUNT(DISTINCT CONCAT(professor_id, '-', checkin_date)) FROM attendance")->fetch_row()[0];
$today_attendance = $conn->query("SELECT COUNT(DISTINCT CONCAT(professor_id, '-', checkin_date)) FROM attendance WHERE checkin_date = CURDATE()")->fetch_row()[0];
$pending_checkouts = $conn->query("SELECT COUNT(DISTINCT CONCAT(professor_id, '-', checkin_date)) FROM attendance WHERE (am_check_in IS NOT NULL AND am_check_out IS NULL) OR (pm_check_in IS NOT NULL AND pm_check_out IS NULL)")->fetch_row()[0];

// Function to calculate time difference in minutes
function calculateDuration($start, $end) {
    if (!$start || !$end) return 0;
    
    $start_time = new DateTime($start);
    $end_time = new DateTime($end);
    $interval = $start_time->diff($end_time);
    
    return ($interval->h * 60) + $interval->i;
}

// Function to format duration
function formatDuration($minutes) {
    if ($minutes <= 0) return '-';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%dh %02dm', $hours, $mins);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance | Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        .table {
            --bs-table-bg: transparent;
            --bs-table-striped-bg: rgba(0, 0, 0, 0.02);
            --bs-table-hover-bg: rgba(0, 0, 0, 0.03);
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        .table th {
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            border-bottom-width: 1px;
            border-top: none;
        }

        .table td {
            vertical-align: middle;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(var(--primary-rgb), 0.03);
        }

        .status-badge {
            font-weight: 500;
            letter-spacing: 0.5px;
            padding: 0.35em 0.65em;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .duration-badge {
            font-size: 0.75rem;
            padding: 0.25em 0.5em;
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }

        .dt-buttons .btn {
            border-radius: 4px !important;
            margin-right: 5px;
            padding: 5px 10px;
            font-size: 0.8rem;
        }

        .dt-buttons .btn i {
            margin-right: 3px;
        }

        .dt-buttons .btn.buttons-excel {
            background-color: #198754;
            color: white;
            border: none;
        }

        .dt-buttons .btn.buttons-pdf {
            background-color: #dc3545;
            color: white;
            border: none;
        }

        .dt-buttons .btn.buttons-csv {
            background-color: #6c757d;
            color: white;
            border: none;
        }

        .dt-buttons .btn.buttons-print {
            background-color: #0dcaf0;
            color: white;
            border: none;
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
        
        .date-badge {
            font-size: 0.75rem;
            background-color: rgba(108, 117, 125, 0.1);
            color: #6c757d;
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
                    <h2 class="mb-1 fw-bold">Attendance Management</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home me-1"></i> Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page"><i class="fas fa-calendar-check me-1"></i> Attendance</li>
                        </ol>
                    </nav>
                </div>
                <div>
                    <a href="add-attendance.php" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Add Record
                    </a>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card stats-card border-start border-primary border-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-1">Total Records</h6>
                                    <h3 class="mb-0"><?= $total_attendance ?></h3>
                                </div>
                                <div class="bg-primary bg-opacity-10 p-3 rounded">
                                    <i class="fas fa-calendar-alt text-primary fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card border-start border-success border-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-1">Today's Records</h6>
                                    <h3 class="mb-0"><?= $today_attendance ?></h3>
                                </div>
                                <div class="bg-success bg-opacity-10 p-3 rounded">
                                    <i class="fas fa-clock text-success fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card border-start border-warning border-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-1">Pending Time Outs</h6>
                                    <h3 class="mb-0"><?= $pending_checkouts ?></h3>
                                </div>
                                <div class="bg-warning bg-opacity-10 p-3 rounded">
                                    <i class="fas fa-exclamation-triangle text-warning fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Card -->
            <div class="card mb-4">
                <div class="card-body p-2">
                    <form method="GET" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <div class="col-md-3">
                            <label for="date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="date" name="date" value="<?= htmlspecialchars($date_filter) ?>">
                        </div>

                        <div class="col-md-3">
                            <label for="professor" class="form-label">Professor</label>
                            <select class="form-select" id="professor" name="professor">
                                <option value="all">All Professors</option>
                                <?php while ($prof = $professors->fetch_assoc()): ?>
                                    <option value="<?= $prof['id'] ?>" <?= $professor_filter == $prof['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prof['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="present" <?= $status_filter === 'present' ? 'selected' : '' ?>>Present</option>
                                <option value="half-day" <?= $status_filter === 'half-day' ? 'selected' : '' ?>>Half Day</option>
                                <option value="absent" <?= $status_filter === 'absent' ? 'selected' : '' ?>>Absent</option>
                            </select>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-1"></i> Apply Filters
                            </button>
                            <a href="manage-attendance.php" class="btn btn-outline-secondary">
                                <i class="fas fa-sync me-1"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Attendance Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Attendance Records</h5>
                    <div class="d-flex align-items-center">
                        <span class="me-2">Records:</span>
                        <span class="badge bg-primary rounded-pill"><?= $attendance->num_rows ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="attendanceTable" class="table table-striped table-hover" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Professor</th>
                                    <th>Department</th>
                                    <th>AM Session</th>
                                    <th>PM Session</th>
                                    <th>Total Duration</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php while ($record = $attendance->fetch_assoc()):
                                    // Calculate durations
                                    $am_duration = calculateDuration($record['am_check_in'], $record['am_check_out']);
                                    $pm_duration = calculateDuration($record['pm_check_in'], $record['pm_check_out']);
                                    $total_duration = $am_duration + $pm_duration;

                                    // Determine status
                                    $status = $record['status'] ?? $record['calculated_status'];
                                    $is_full_day = $record['am_check_in'] && $record['pm_check_in'];
                                ?>
                                    <tr>
                                        <td><?= $record['professor_db_id'] ?></td>
                                        <td>
                                            <span class="date-badge rounded-pill px-2 py-1">
                                                <?= date('M j, Y', strtotime($record['date'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div>
                                                    <div class="fw-bold"><?= htmlspecialchars($record['professor_name']) ?></div>
                                                    <small class="text-muted"><?= htmlspecialchars($record['designation']) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($record['department']) ?></td>

                                        <!-- AM Session -->
                                        <td>
                                            <div class="time-session">
                                                <?php if ($record['am_check_in']): ?>
                                                    <div>
                                                        <span class="time-label">Time In</span>
                                                        <span class="time-value"><?= date('h:i A', strtotime($record['am_check_in'])) ?></span>
                                                    </div>
                                                    <?php if ($record['am_check_out']): ?>
                                                        <div>
                                                            <span class="time-label">Time Out</span>
                                                            <span class="time-value"><?= date('h:i A', strtotime($record['am_check_out'])) ?></span>
                                                        </div>
                                                        <div>
                                                            <span class="time-label">Duration</span>
                                                            <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary">
                                                                <?= formatDuration($am_duration) ?>
                                                            </span>
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
                                        </td>

                                        <!-- PM Session -->
                                        <td>
                                            <div class="time-session">
                                                <?php if ($record['pm_check_in']): ?>
                                                    <div>
                                                        <span class="time-label">Time In</span>
                                                        <span class="time-value"><?= date('h:i A', strtotime($record['pm_check_in'])) ?></span>
                                                    </div>
                                                    <?php if ($record['pm_check_out']): ?>
                                                        <div>
                                                            <span class="time-label">Time Out</span>
                                                            <span class="time-value"><?= date('h:i A', strtotime($record['pm_check_out'])) ?></span>
                                                        </div>
                                                        <div>
                                                            <span class="time-label">Duration</span>
                                                            <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary">
                                                                <?= formatDuration($pm_duration) ?>
                                                            </span>
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
                                        </td>

                                        <!-- Total Duration -->
                                        <td>
                                            <?php if ($total_duration > 0): ?>
                                                <span class="badge rounded-pill bg-success bg-opacity-10 text-success">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?= formatDuration($total_duration) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">
                                                    <i class="fas fa-minus me-1"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status -->
                                        <td>
                                            <?php
                                            $badge_class = 'bg-secondary';
                                            $status_text = 'Not Recorded';

                                            if ($status === 'present') {
                                                $badge_class = 'bg-success';
                                                $status_text = 'Present';
                                            } elseif ($status === 'half-day') {
                                                $badge_class = 'bg-warning text-dark';
                                                $status_text = 'Half Day';
                                            } elseif ($status === 'absent') {
                                                $badge_class = 'bg-danger';
                                                $status_text = 'Absent';
                                            }
                                            ?>
                                            <span class="badge rounded-pill <?= $badge_class ?>">
                                                <?= $status_text ?>
                                            </span>
                                        </td>

                                        <!-- Actions -->
                                        <td>
                                            <div class="d-flex justify-content-end gap-1">
                                                <!-- Edit Button -->
                                                <a href="edit-attendance.php?id=<?= $record['attendance_id'] ?>"
                                                    class="btn btn-sm btn-icon btn-primary rounded-circle"
                                                    title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>

                                                <!-- Delete Button -->
                                                <button class="btn btn-sm btn-icon btn-danger rounded-circle delete-btn"
                                                    data-id="<?= $record['attendance_id'] ?>"
                                                    title="Delete"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteAttendanceModal">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Delete Attendance Modal -->
    <div class="modal fade" id="deleteAttendanceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Attendance Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this attendance record? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="confirmDeleteAttendance" class="btn btn-danger">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
            const table = $('#attendanceTable').DataTable({
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
                lengthMenu: [5, 10, 25, 50, 100],
                columnDefs: [{
                        responsivePriority: 1,
                        targets: 2
                    }, // Professor
                    {
                        responsivePriority: 2,
                        targets: 8
                    }, // Actions
                    {
                        responsivePriority: 3,
                        targets: 4
                    }, // AM Session
                    {
                        responsivePriority: 4,
                        targets: 0
                    }, // ID
                    {
                        orderable: false,
                        targets: 8
                    } // Actions column
                ],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search records...",
                    lengthMenu: "Show _MENU_ records per page",
                    zeroRecords: "No attendance records found",
                    info: "Showing _START_ to _END_ of _TOTAL_ records",
                    infoEmpty: "No records available",
                    infoFiltered: "(filtered from _MAX_ total records)"
                }
            });

            // Track current IDs for actions
            let currentDeleteId = null;

            // Delete button handler
            $(document).on('click', '.delete-btn', function() {
                currentDeleteId = $(this).data('id');
                $('#deleteAttendanceModal').modal('show');
            });

            // Confirm delete action
            $('#confirmDeleteAttendance').on('click', function() {
                if (!currentDeleteId) return;

                const button = $(this);
                button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Deleting...');

                $.ajax({
                    url: 'delete-attendance.php',
                    type: 'POST',
                    data: {
                        id: currentDeleteId,
                        csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#deleteAttendanceModal').modal('hide');
                        if (response.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: 'Attendance record deleted successfully',
                                icon: 'success'
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Error!', response.message || 'Failed to delete record', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error!', 'Failed to delete record. Please try again.', 'error');
                    },
                    complete: function() {
                        button.prop('disabled', false).html('Delete');
                    }
                });
            });
        });
    </script>
</body>

</html>
