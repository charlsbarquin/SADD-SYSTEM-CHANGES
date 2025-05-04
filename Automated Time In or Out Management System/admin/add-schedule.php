<?php
session_start();
require_once '../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: ../admin/login.php');
    exit;
}

// Initialize variables
$professors = [];
$days = [];
$success = '';
$error = '';

// Get list of professors
$professor_query = $conn->query("SELECT id, name, department FROM professors ORDER BY name");
if ($professor_query) {
    $professors = $professor_query->fetch_all(MYSQLI_ASSOC);
}

// Get days of week
$days_query = $conn->query("SELECT * FROM schedule_days ORDER BY id");
if ($days_query) {
    $days = $days_query->fetch_all(MYSQLI_ASSOC);
}

// If no days exist, create default days
if (empty($days)) {
    $default_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    foreach ($default_days as $index => $day_name) {
        $conn->query("INSERT INTO schedule_days (id, day_name) VALUES (".($index+1).", '$day_name')");
    }
    $days = $conn->query("SELECT * FROM schedule_days ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['professor_id'])) {
    $professor_id = (int)$_POST['professor_id'];
    $schedules = $_POST['schedules'] ?? [];

    try {
        $conn->begin_transaction();

        // Delete existing schedules for this professor
        $delete_stmt = $conn->prepare("DELETE FROM professor_schedules WHERE professor_id = ?");
        $delete_stmt->bind_param("i", $professor_id);
        $delete_stmt->execute();

        // Insert new schedules
        if (!empty($schedules)) {
            $insert_stmt = $conn->prepare("INSERT INTO professor_schedules 
                                         (professor_id, day_id, start_time, end_time, subject, room) 
                                         VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($schedules as $day_id => $day_schedules) {
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

        // Log the action
        $log_message = "Admin updated schedule for professor ID: $professor_id";
        $log_stmt = $conn->prepare("INSERT INTO admin_logs (action, admin_id, timestamp) VALUES (?, ?, NOW())");
        $log_stmt->bind_param("si", $log_message, $_SESSION['admin_id']);
        $log_stmt->execute();
        
        // Redirect to prevent form resubmission
        $_SESSION['success'] = $success;
        header("Location: add-schedule.php?professor_id=$professor_id");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = 'Error updating schedule: ' . $e->getMessage();
    }
}

// Get current schedule if professor_id is set
$current_schedule = [];
$current_professor = null;
if (isset($_GET['professor_id'])) {
    $professor_id = (int)$_GET['professor_id'];
    
    // Get professor details
    $prof_stmt = $conn->prepare("SELECT id, name, department FROM professors WHERE id = ?");
    $prof_stmt->bind_param("i", $professor_id);
    $prof_stmt->execute();
    $current_professor = $prof_stmt->get_result()->fetch_assoc();
    
    // Get schedule
    $schedule_query = $conn->query("SELECT * FROM professor_schedules WHERE professor_id = $professor_id ORDER BY day_id, start_time");
    if ($schedule_query) {
        while ($row = $schedule_query->fetch_assoc()) {
            $current_schedule[$row['day_id']][] = $row;
        }
    }
}

// Display success message from session
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Professor Schedule | Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
        }

        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
        }

        .card-header {
            background-color: #0099CC;
            color: white;
            font-weight: 600;
            border-radius: 10px 10px 0 0 !important;
        }

        .day-header {
            background-color: #0099CC;
            color: white;
            padding: 10px;
            font-weight: 600;
            border-radius: 8px 8px 0 0;
        }

        .schedule-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            background-color: white;
            margin-bottom: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .time-input {
            max-width: 120px;
        }

        .remove-schedule-btn {
            color: #dc3545;
            cursor: pointer;
            transition: color 0.2s;
        }

        .remove-schedule-btn:hover {
            color: #a71d2a;
        }

        .professor-info-card {
            border-left: 4px solid #0099CC;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            
            .schedule-item {
                padding: 10px;
            }
            
            .time-input {
                max-width: 100%;
            }
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
                    <h2 class="mb-1">Manage Professor Schedule</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="manage-users.php">Professors</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Manage Schedule</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <!-- Display Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-12">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Select Professor</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" class="mb-4">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-8">
                                        <label for="professor_id" class="form-label">Professor</label>
                                        <select class="form-select" id="professor_id" name="professor_id" required>
                                            <option value="">-- Select Professor --</option>
                                            <?php foreach ($professors as $professor): ?>
                                                <option value="<?= $professor['id'] ?>"
                                                    <?= isset($_GET['professor_id']) && $_GET['professor_id'] == $professor['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($professor['name']) ?> (<?= htmlspecialchars($professor['department']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search me-1"></i> Load Schedule
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <?php if ($current_professor): ?>
                                <div class="card professor-info-card mb-4">
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <h5 class="card-title"><?= htmlspecialchars($current_professor['name']) ?></h5>
                                                <p class="card-text">
                                                    <strong>Department:</strong> <?= htmlspecialchars($current_professor['department']) ?>
                                                </p>
                                            </div>
                                            <div class="col-md-6 text-md-end">
                                                <a href="professor-schedule.php?id=<?= $current_professor['id'] ?>" class="btn btn-outline-primary">
                                                    <i class="fas fa-calendar-alt me-1"></i> View Schedule
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (isset($_GET['professor_id'])): ?>
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Weekly Schedule</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="scheduleForm">
                                    <input type="hidden" name="professor_id" value="<?= htmlspecialchars($_GET['professor_id']) ?>">

                                    <div class="row">
                                        <?php foreach ($days as $day): ?>
                                            <div class="col-md-6 col-lg-4 mb-4">
                                                <div class="card h-100">
                                                    <div class="day-header">
                                                        <?= htmlspecialchars($day['day_name']) ?>
                                                    </div>
                                                    <div class="card-body">
                                                        <div id="schedules-<?= $day['id'] ?>">
                                                            <?php if (isset($current_schedule[$day['id']])): ?>
                                                                <?php foreach ($current_schedule[$day['id']] as $index => $item): ?>
                                                                    <div class="schedule-item">
                                                                        <div class="row g-2">
                                                                            <div class="col-md-5">
                                                                                <label class="form-label">Start Time</label>
                                                                                <input type="time" class="form-control time-input"
                                                                                    name="schedules[<?= $day['id'] ?>][<?= $index ?>][start_time]"
                                                                                    value="<?= htmlspecialchars($item['start_time']) ?>" required>
                                                                            </div>
                                                                            <div class="col-md-5">
                                                                                <label class="form-label">End Time</label>
                                                                                <input type="time" class="form-control time-input"
                                                                                    name="schedules[<?= $day['id'] ?>][<?= $index ?>][end_time]"
                                                                                    value="<?= htmlspecialchars($item['end_time']) ?>" required>
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
                                                                                    value="<?= htmlspecialchars($item['subject']) ?>" required>
                                                                            </div>
                                                                            <div class="col-12">
                                                                                <label class="form-label">Room</label>
                                                                                <input type="text" class="form-control"
                                                                                    name="schedules[<?= $day['id'] ?>][<?= $index ?>][room]"
                                                                                    value="<?= htmlspecialchars($item['room']) ?>" required>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <div class="schedule-item">
                                                                    <div class="row g-2">
                                                                        <div class="col-md-5">
                                                                            <label class="form-label">Start Time</label>
                                                                            <input type="time" class="form-control time-input"
                                                                                name="schedules[<?= $day['id'] ?>][0][start_time]" required>
                                                                        </div>
                                                                        <div class="col-md-5">
                                                                            <label class="form-label">End Time</label>
                                                                            <input type="time" class="form-control time-input"
                                                                                name="schedules[<?= $day['id'] ?>][0][end_time]" required>
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
                                                                                name="schedules[<?= $day['id'] ?>][0][subject]" required>
                                                                        </div>
                                                                        <div class="col-12">
                                                                            <label class="form-label">Room</label>
                                                                            <input type="text" class="form-control"
                                                                                name="schedules[<?= $day['id'] ?>][0][room]" required>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-center mt-3">
                                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                                onclick="addSchedule(<?= $day['id'] ?>)">
                                                                <i class="fas fa-plus me-1"></i> Add Time Slot
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="text-center mt-4">
                                        <button type="submit" class="btn btn-primary px-4">
                                            <i class="fas fa-save me-1"></i> Save Schedule
                                        </button>
                                        <a href="professor-schedule.php?id=<?= $_GET['professor_id'] ?>" class="btn btn-outline-secondary ms-2">
                                            <i class="fas fa-times me-1"></i> Cancel
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add new schedule slot for a day
        function addSchedule(dayId) {
            const container = document.getElementById(`schedules-${dayId}`);
            const index = container.querySelectorAll('.schedule-item').length;

            const newItem = document.createElement('div');
            newItem.className = 'schedule-item';
            newItem.innerHTML = `
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="form-label">Start Time</label>
                        <input type="time" class="form-control time-input" 
                               name="schedules[${dayId}][${index}][start_time]" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">End Time</label>
                        <input type="time" class="form-control time-input" 
                               name="schedules[${dayId}][${index}][end_time]" required>
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
                               name="schedules[${dayId}][${index}][subject]" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Room</label>
                        <input type="text" class="form-control" 
                               name="schedules[${dayId}][${index}][room]" required>
                    </div>
                </div>
            `;

            container.appendChild(newItem);
        }

        // Remove a schedule slot
        function removeSchedule(button, dayId) {
            const item = button.closest('.schedule-item');
            if (item) {
                const container = item.parentElement;
                // Don't remove if it's the last item
                if (container.querySelectorAll('.schedule-item').length > 1) {
                    item.remove();
                    // Re-index remaining items
                    const items = container.querySelectorAll('.schedule-item');
                    items.forEach((item, index) => {
                        item.querySelectorAll('[name]').forEach(input => {
                            input.name = input.name.replace(/\[\d+\]\[\d+\]/g, `[${dayId}][${index}]`);
                        });
                    });
                } else {
                    alert('Each day must have at least one time slot. To remove all schedules for this day, delete the time values.');
                }
            }
        }

        // Validate time inputs before submission
        document.getElementById('scheduleForm')?.addEventListener('submit', function(e) {
            let valid = true;
            const dayIds = [<?php echo implode(',', array_column($days, 'id')); ?>];

            // Check each day that has time slots
            dayIds.forEach(dayId => {
                const container = document.getElementById(`schedules-${dayId}`);
                const items = container?.querySelectorAll('.schedule-item') || [];

                items.forEach(item => {
                    const startTime = item.querySelector('[name*="start_time"]').value;
                    const endTime = item.querySelector('[name*="end_time"]').value;
                    const subject = item.querySelector('[name*="subject"]').value;
                    const room = item.querySelector('[name*="room"]').value;

                    // Only validate if at least one field is filled
                    if (startTime || endTime || subject || room) {
                        if (!startTime || !endTime || !subject || !room) {
                            alert(`Please fill all fields or remove the time slot for ${getDayName(dayId)}`);
                            valid = false;
                        } else if (startTime >= endTime) {
                            alert(`End time must be after start time for ${getDayName(dayId)}`);
                            valid = false;
                        }
                    }
                });
            });

            if (!valid) {
                e.preventDefault();
            }
        });

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
    </script>
</body>

</html>