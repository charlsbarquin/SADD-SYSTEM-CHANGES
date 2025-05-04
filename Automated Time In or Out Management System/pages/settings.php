<?php
include '../config/database.php';
session_start();

// Ensure 'user_role' is set to prevent undefined key errors
if (!isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = 'user'; // Default role if not logged in
}

// Check if settings table has the required columns, if not add them
$check_columns = $conn->query("SHOW COLUMNS FROM settings LIKE 'am_cutoff'");
if ($check_columns->num_rows == 0) {
    $conn->query("ALTER TABLE settings ADD COLUMN am_cutoff TIME DEFAULT '12:00:00'");
    $conn->query("ALTER TABLE settings ADD COLUMN pm_cutoff TIME DEFAULT '17:00:00'");
    $conn->query("UPDATE settings SET am_cutoff = '12:00:00', pm_cutoff = '17:00:00' WHERE id = 1");
}

// Fetch current settings
$sql = "SELECT * FROM settings WHERE id = 1";
$result = $conn->query($sql);
$settings = $result->fetch_assoc();

if (!$settings) {
    // Initialize default settings if they don't exist
    $default_settings = [
        'timezone' => 'Asia/Manila',
        'am_cutoff' => '12:00:00',
        'pm_cutoff' => '17:00:00'
    ];

    $stmt = $conn->prepare("INSERT INTO settings (timezone, am_cutoff, pm_cutoff) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", 
        $default_settings['timezone'],
        $default_settings['am_cutoff'],
        $default_settings['pm_cutoff']
    );
    $stmt->execute();
    $settings = $default_settings;
}

// Handle settings update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_settings'])) {
    $timezone = trim($_POST['timezone']);
    $am_cutoff = trim($_POST['am_cutoff']);
    $pm_cutoff = trim($_POST['pm_cutoff']);

    // Validate time inputs
    if (!validateTime($am_cutoff)) {
        $_SESSION['error_message'] = "Invalid AM cutoff time format (HH:MM)";
        header("Location: settings.php");
        exit;
    }
    
    if (!validateTime($pm_cutoff)) {
        $_SESSION['error_message'] = "Invalid PM cutoff time format (HH:MM)";
        header("Location: settings.php");
        exit;
    }

    $update_sql = "UPDATE settings SET timezone = ?, am_cutoff = ?, pm_cutoff = ? WHERE id = 1";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("sss", $timezone, $am_cutoff, $pm_cutoff);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Settings updated successfully!";
        header("Location: settings.php");
        exit;
    } else {
        $_SESSION['error_message'] = "Error updating settings: " . $conn->error;
    }
}

// Handle Reset Attendance Data (Only for Admins)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_attendance']) && $_SESSION['user_role'] === 'admin') {
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM attendance");
        $conn->query("ALTER TABLE attendance AUTO_INCREMENT = 1");
        $conn->query("DELETE FROM notifications WHERE type LIKE 'time%'");
        $conn->query("DELETE FROM logs WHERE action LIKE '%time%'");
        $conn->commit();
        $_SESSION['success_message'] = "Attendance data reset successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error resetting attendance: " . $e->getMessage();
    }
    header("Location: settings.php");
    exit;
}

// Function to validate time format
function validateTime($time) {
    return preg_match("/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/", $time);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Attendance System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/attendance-report.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .main-container {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
            padding: 25px;
            margin-bottom: 30px;
        }
        .page-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .page-header h1 {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .page-subtitle {
            color: #7f8c8d;
            font-size: 16px;
            margin-bottom: 15px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-header {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .section-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: #2c3e50;
        }
        .form-label {
            font-weight: 500;
            color: #2c3e50;
        }
        .time-help-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        .danger-zone {
            border-left: 4px solid #dc3545;
            padding-left: 15px;
        }
        .alert {
            border-radius: 8px;
        }
    </style>
</head>

<body>
    <?php include '../includes/navbar.php'; ?>

    <div class="container-fluid py-4">
        <div class="main-container">
            <!-- Header Section -->
            <div class="page-header">
                <h1><i class="fas fa-cog me-2"></i>System Settings</h1>
                <p class="page-subtitle">Configure system parameters and preferences</p>
            </div>

            <!-- Success Message -->
            <?php if (isset($_SESSION['success_message'])) : ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (isset($_SESSION['error_message'])) : ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- General Settings Section -->
            <div class="section">
                <div class="section-header">
                    <h2><i class="fas fa-clock me-2"></i>General Settings</h2>
                </div>
                <form method="POST">
                    <!-- Session Settings -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">AM Session Cutoff</label>
                            <input type="time" name="am_cutoff" class="form-control"
                                value="<?= htmlspecialchars($settings['am_cutoff']); ?>" required
                                oninput="updateAmCutoffPreview()">
                            <small class="text-muted time-help-text">Morning session ends at:
                                <strong id="amCutoffPreview"><?= htmlspecialchars($settings['am_cutoff']); ?></strong>
                            </small>
                            <small class="text-muted time-help-text d-block">
                                Determines when the AM session ends and PM session begins.
                            </small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PM Session Cutoff</label>
                            <input type="time" name="pm_cutoff" class="form-control"
                                value="<?= htmlspecialchars($settings['pm_cutoff']); ?>" required
                                oninput="updatePmCutoffPreview()">
                            <small class="text-muted time-help-text">Afternoon session ends at:
                                <strong id="pmCutoffPreview"><?= htmlspecialchars($settings['pm_cutoff']); ?></strong>
                            </small>
                            <small class="text-muted time-help-text d-block">
                                Determines when the PM session officially ends for the day.
                            </small>
                        </div>
                    </div>

                    <!-- Timezone -->
                    <div class="mb-3">
                        <label class="form-label">Timezone</label>
                        <select name="timezone" class="form-control">
                            <option value="Asia/Manila" <?= ($settings['timezone'] == "Asia/Manila") ? 'selected' : ''; ?>>Asia/Manila (UTC+8)</option>
                            <option value="UTC" <?= ($settings['timezone'] == "UTC") ? 'selected' : ''; ?>>UTC</option>
                            <option value="America/New_York" <?= ($settings['timezone'] == "America/New_York") ? 'selected' : ''; ?>>America/New York (UTC-5/-4)</option>
                            <option value="Europe/London" <?= ($settings['timezone'] == "Europe/London") ? 'selected' : ''; ?>>Europe/London (UTC+0/+1)</option>
                        </select>
                        <small class="text-muted time-help-text">
                            Affects how all time-based data is displayed and recorded.
                        </small>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" name="save_settings" class="btn btn-primary px-4 py-2">
                            <i class="fas fa-save me-2"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Dangerous Actions (Only for Admins) -->
            <?php if ($_SESSION['user_role'] === 'admin') : ?>
                <div class="section danger-zone">
                    <div class="section-header">
                        <h2 class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Admin Actions</h2>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> These actions are irreversible and will permanently affect system data.
                    </div>
                    
                    <form method="POST" id="resetForm" class="mb-3">
                        <button type="submit" name="reset_attendance" class="btn btn-danger w-100">
                            <i class="fas fa-trash-alt me-2"></i> Reset All Attendance Data
                        </button>
                        <small class="text-muted time-help-text">
                            This will delete all attendance records but preserve professor accounts and schedules.
                        </small>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update preview functions
        function updateAmCutoffPreview() {
            document.getElementById("amCutoffPreview").textContent =
                document.querySelector("input[name='am_cutoff']").value;
        }

        function updatePmCutoffPreview() {
            document.getElementById("pmCutoffPreview").textContent =
                document.querySelector("input[name='pm_cutoff']").value;
        }

        // Confirm before resetting data
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            if (!confirm('⚠️ WARNING: This will permanently delete ALL attendance records. Continue?')) {
                e.preventDefault();
            }
        });

        // Dark mode support
        $(document).ready(function() {
            if (localStorage.getItem("dark-mode") === "enabled") {
                document.body.classList.add("dark-mode");
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>