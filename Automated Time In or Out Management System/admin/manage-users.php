<?php
session_start();
require_once '../config/database.php';

// Authentication check
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin-login.php');
    exit;
}

// Get filter parameter with sanitization
$filter = 'all'; // Removed pending filter since we don't need approvals

// Build query
$query = "SELECT p.*, 
            (SELECT COUNT(*) FROM attendance WHERE professor_id = p.id) as attendance_count,
            (SELECT COUNT(*) FROM professor_schedules WHERE professor_id = p.id) as schedule_count
          FROM professors p
          ORDER BY p.name ASC";

$professors = $conn->query($query);

// Get counts for dashboard
$total_professors = $conn->query("SELECT COUNT(*) FROM professors")->fetch_row()[0];
$active_professors = $conn->query("SELECT COUNT(*) FROM professors WHERE status = 'active'")->fetch_row()[0];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Professors | Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/dashboard.css">

    <style>
        .status-badge {
            font-size: 0.8rem;
            padding: 0.35em 0.65em;
        }

        .profile-img-sm {
            width: 35px;
            height: 35px;
            object-fit: cover;
            border-radius: 50%;
        }

        .stats-card {
            transition: transform 0.2s;
        }

        .stats-card:hover {
            transform: translateY(-3px);
        }

        .action-btn {
            min-width: 80px;
            margin: 2px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        /* Button styling */
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            line-height: 1.5;
            border-radius: 0.25rem;
        }

        /* Export buttons styling */
        .dt-buttons .btn {
            border-radius: 4px !important;
            margin-right: 5px;
            padding: 5px 10px;
            font-size: 0.8rem;
        }

        .dt-buttons .btn i {
            margin-right: 3px;
        }

        /* Specific button colors */
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

        /* Table improvements */
        .table {
            --bs-table-bg: transparent;
            --bs-table-striped-bg: rgba(0, 0, 0, 0.02);
            --bs-table-hover-bg: rgba(0, 0, 0, 0.03);
            font-size: 0.9rem;
        }

        .table th {
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            border-bottom-width: 1px;
        }

        /* Icon buttons */
        .btn-icon {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            padding: 0;
            transition: all 0.2s;
        }

        .btn-icon:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* Status badges */
        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
        }

        .schedule-badge {
            background-color: #6f42c1;
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
                    <h2 class="mb-1">Professor Management</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Professors</li>
                        </ol>
                    </nav>
                </div>
                <div>
                    <a href="add-professor.php" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Add New Professor
                    </a>
                </div>
            </div>

            <!-- ADD MESSAGES RIGHT HERE -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success mb-4">
                    <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_SESSION['success']);
                                                                unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger mb-4">
                    <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_SESSION['error']);
                                                                    unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card stats-card border-start border-primary border-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-1">Total Professors</h6>
                                    <h3 class="mb-0"><?= $total_professors ?></h3>
                                </div>
                                <div class="bg-primary bg-opacity-10 p-3 rounded">
                                    <i class="fas fa-users text-primary fs-4"></i>
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
                                    <h6 class="text-muted mb-1">Active Professors</h6>
                                    <h3 class="mb-0"><?= $active_professors ?></h3>
                                </div>
                                <div class="bg-success bg-opacity-10 p-3 rounded">
                                    <i class="fas fa-user-check text-success fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card border-start border-purple border-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-1">With Schedules</h6>
                                    <h3 class="mb-0"><?= $conn->query("SELECT COUNT(DISTINCT professor_id) FROM professor_schedules")->fetch_row()[0] ?></h3>
                                </div>
                                <div class="bg-purple bg-opacity-10 p-3 rounded">
                                    <i class="fas fa-calendar-alt text-purple fs-4"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Professors Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Professor List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="professorsTable" class="table table-striped table-hover" style="width:100%">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Professor</th>
                                    <th>Contact</th>
                                    <th>Designation</th>
                                    <th>Check-ins</th>
                                    <th>Schedule</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($prof = $professors->fetch_assoc()): ?>
                                    <tr>
                                        <td class="align-middle"><?= $prof['id'] ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <div>
                                                    <div class="fw-bold"><?= htmlspecialchars($prof['name']) ?></div>
                                                    <small class="text-muted"><?= htmlspecialchars($prof['department'] ?? 'N/A') ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="align-middle">
                                            <div><?= htmlspecialchars($prof['email']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($prof['phone'] ?? 'N/A') ?></small>
                                        </td>
                                        <td class="align-middle"><?= htmlspecialchars($prof['designation']) ?></td>
                                        <td class="align-middle">
                                            <span class="badge bg-primary bg-opacity-10 text-primary"><?= $prof['attendance_count'] ?> check-ins</span>
                                        </td>
                                        <td class="align-middle">
                                            <?php if ($prof['schedule_count'] > 0): ?>
                                                <span class="badge schedule-badge"><?= $prof['schedule_count'] ?> slots</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">No schedule</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-middle">
                                            <span class="badge rounded-pill bg-success">
                                                Active
                                            </span>
                                        </td>
                                        <td class="align-middle">
                                            <div class="d-flex justify-content-end gap-2">
                                                <a href="professor-schedule.php?id=<?= $prof['id'] ?>" class="btn btn-sm btn-icon btn-info" title="View Schedule">
                                                    <i class="fas fa-calendar-alt"></i>
                                                </a>
                                                <a href="edit-professor.php?id=<?= $prof['id'] ?>" class="btn btn-sm btn-icon btn-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button class="btn btn-sm btn-icon btn-danger delete-btn" data-id="<?= $prof['id'] ?>" title="Delete">
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

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this professor? This action cannot be undone.</p>
                    <p class="text-danger"><strong>Warning:</strong> All associated attendance records and schedules will also be deleted.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDelete" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
            const table = $('#professorsTable').DataTable({
                responsive: true,
                dom: '<"top"Bf>rt<"bottom"lip><"clear">',
                buttons: [{
                        extend: 'excelHtml5',
                        text: '<i class="fas fa-file-excel me-1"></i> Excel',
                        className: 'btn btn-sm buttons-excel',
                        title: 'Professors List',
                        exportOptions: {
                            columns: ':visible'
                        }
                    },
                    {
                        extend: 'pdfHtml5',
                        text: '<i class="fas fa-file-pdf me-1"></i> PDF',
                        className: 'btn btn-sm buttons-pdf',
                        title: 'Professors List',
                        exportOptions: {
                            columns: ':visible'
                        }
                    },
                    {
                        extend: 'csvHtml5',
                        text: '<i class="fas fa-file-csv me-1"></i> CSV',
                        className: 'btn btn-sm buttons-csv',
                        title: 'Professors List',
                        exportOptions: {
                            columns: ':visible'
                        }
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print me-1"></i> Print',
                        className: 'btn btn-sm buttons-print',
                        title: 'Professors List',
                        exportOptions: {
                            columns: ':visible'
                        }
                    }
                ],
                pageLength: 10,
                lengthMenu: [5, 10, 25, 50, 100]
            });

            // Remove button grouping
            $('.dt-buttons').removeClass('btn-group');

            // Handle delete button clicks
            $('#professorsTable').on('click', '.delete-btn', function() {
                const professorId = $(this).data('id');
                $('#confirmDelete').attr('href', 'delete-professor.php?id=' + professorId);
                $('#deleteModal').modal('show');
            });

            // Toast notification function
            function showToast(message, type) {
                // Remove existing toasts
                $('.toast-container').remove();

                const toast = $(`
                <div class="toast-container">
                    <div class="toast show align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                        <div class="d-flex">
                            <div class="toast-body">${message}</div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                    </div>
                </div>
            `);

                $('body').append(toast);

                // Auto-hide after 3 seconds
                setTimeout(() => {
                    toast.find('.toast').toast('hide');
                    setTimeout(() => toast.remove(), 500);
                }, 3000);
            }
        });
    </script>
</body>

</html>