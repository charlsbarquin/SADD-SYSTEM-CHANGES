<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['professor_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

// Configuration
$itemsPerPage = 15;
$maxDaysOld = 7;

// Get notifications
$notifications = [];
$query = "SELECT * FROM notifications 
          WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          ORDER BY created_at DESC 
          LIMIT ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $maxDaysOld, $itemsPerPage);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Format message with consistent terminology
    $row['message'] = str_replace(
        ['timed in', 'timed out', 'AM session', 'PM session'],
        ['Timed In', 'Timed Out', 'Morning Session', 'Afternoon Session'],
        $row['message']
    );
    
    // Highlight late arrivals
    if (strpos($row['message'], '(Late)') !== false) {
        $row['message'] = str_replace('(Late)', '<span class="badge bg-warning">Late</span>', $row['message']);
    }
    
    $row['type'] = ucfirst(str_replace('-', ' ', $row['type']));
    $notifications[] = $row;
}

// Get activity logs
$activityLogs = [];
$query = "SELECT * FROM logs 
          WHERE timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)
          ORDER BY timestamp DESC 
          LIMIT ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $maxDaysOld, $itemsPerPage);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Format action with consistent terminology
    $row['action'] = str_replace(
        ['timed in', 'timed out', 'AM session', 'PM session'],
        ['Timed In', 'Timed Out', 'Morning Session', 'Afternoon Session'],
        $row['action']
    );
    $activityLogs[] = $row;
}

// Get unread count
$unreadCount = 0;
$query = "SELECT COUNT(*) FROM notifications 
          WHERE is_read = 0 
          AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $maxDaysOld);
$stmt->execute();
$unreadCount = $stmt->get_result()->fetch_row()[0];
$stmt->close();

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $query = "UPDATE notifications SET is_read = 1";
    $conn->query($query);
    header("Location: notifications.php");
    exit;
}

// Handle marking single notification as read via AJAX
if (isset($_POST['mark_read']) && isset($_POST['notification_id'])) {
    $notificationId = (int)$_POST['notification_id'];
    $markReadQuery = "UPDATE notifications SET is_read = 1 WHERE id = ?";
    $stmt = $conn->prepare($markReadQuery);
    $stmt->bind_param("i", $notificationId);
    $stmt->execute();
    $stmt->close();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Automated Attendance System</title>

    <!-- Bootstrap & Font Awesome -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/attendance-report.css">

    <style>
        :root {
            --primary-color: #4e73df;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --secondary-color: #858796;
            --light-color: #f8f9fc;
        }

        .main-container {
            background-color: #f8f9fa;
            min-height: calc(100vh - 56px);
            padding: 2rem 0;
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

        .activity-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .notification-card {
            border-radius: 4px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-color);
            transition: all 0.2s ease;
            padding: 1rem;
            position: relative;
        }

        .notification-card.unread {
            background-color: #f0f7ff;
            border-left-color: var(--danger-color);
        }

        .notification-card:hover {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .log-card {
            border-radius: 4px;
            margin-bottom: 15px;
            border-left: 4px solid var(--secondary-color);
            transition: all 0.2s ease;
            padding: 1rem;
        }

        .log-card:hover {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .notification-time, .log-time {
            font-size: 0.8rem;
            color: var(--secondary-color);
        }

        .badge {
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 0;
            color: var(--secondary-color);
        }

        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        .btn-mark-all {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 0.5rem 1.25rem;
        }

        .btn-mark-all:hover {
            background-color: #3a5bbf;
        }
        
        .recent-notice {
            font-size: 0.85rem;
            color: var(--secondary-color);
            text-align: center;
            margin-top: 1rem;
            font-style: italic;
        }

        .badge-time-in {
            background-color: var(--success-color);
        }

        .badge-time-out {
            background-color: var(--info-color);
        }

        .mark-read-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: none;
            border: none;
            color: var(--secondary-color);
            cursor: pointer;
            font-size: 0.8rem;
        }

        .mark-read-btn:hover {
            color: var(--primary-color);
        }

        .nav-tabs .nav-link {
            font-weight: 500;
            color: var(--secondary-color);
            border: none;
            padding: 0.75rem 1.5rem;
        }

        .nav-tabs .nav-link.active {
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
        }
    </style>
</head>

<body>
    <?php include '../includes/navbar.php'; ?>

    <div class="container-fluid py-4">
        <div class="main-container">
            <div class="container">
                <!-- Header Section -->
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1><i class="fas fa-bell me-2"></i>Notifications & Activity Logs</h1>
                            <p class="page-subtitle">View recent system notifications and activities</p>
                        </div>
                        <?php if ($unreadCount > 0): ?>
                            <form method="POST" action="notifications.php">
                                <button type="submit" name="mark_all_read" class="btn btn-mark-all">
                                    <i class="fas fa-check-double me-2"></i>Mark All as Read
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <ul class="nav nav-tabs mb-4" id="activityTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications" type="button" role="tab">
                            <i class="fas fa-bell me-1"></i> Notifications
                            <?php if ($unreadCount > 0): ?>
                                <span class="badge bg-danger ms-1"><?= $unreadCount ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs" type="button" role="tab">
                            <i class="fas fa-history me-1"></i> Activity Logs
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="activityTabsContent">
                    <!-- Notifications Tab -->
                    <div class="tab-pane fade show active" id="notifications" role="tabpanel">
                        <div class="activity-container">
                            <?php if (empty($notifications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-bell-slash"></i>
                                    <h4>No recent notifications</h4>
                                    <p>System notifications will appear here</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($notifications as $notification): ?>
                                        <div class="notification-card <?= !$notification['is_read'] ? 'unread' : '' ?>">
                                            <?php if (!$notification['is_read']): ?>
                                                <button class="mark-read-btn" data-id="<?= $notification['id'] ?>" title="Mark as read">
                                                    <i class="fas fa-check-circle"></i> Mark as read
                                                </button>
                                            <?php endif; ?>
                                            
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="badge <?= $notification['type'] === 'Time in' ? 'badge-time-in' : 'badge-time-out' ?>">
                                                    <?= $notification['type'] ?>
                                                </span>
                                                <small class="notification-time">
                                                    <?= date('M j, Y g:i A', strtotime($notification['created_at'])) ?>
                                                </small>
                                            </div>
                                            <div class="notification-message">
                                                <?= $notification['message'] ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="recent-notice">
                                    Showing most recent <?= count($notifications) ?> notifications from the last <?= $maxDaysOld ?> days
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Activity Logs Tab -->
                    <div class="tab-pane fade" id="logs" role="tabpanel">
                        <div class="activity-container">
                            <?php if (empty($activityLogs)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-list"></i>
                                    <h4>No recent activity logs</h4>
                                    <p>System activities will appear here</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($activityLogs as $log): ?>
                                        <div class="log-card">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div>
                                                    <strong><?= htmlspecialchars($log['action']) ?></strong>
                                                    <?php if (!empty($log['user'])): ?>
                                                        <span class="text-muted ms-2">by <?= htmlspecialchars($log['user']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="log-time">
                                                    <?= date('M j, Y g:i A', strtotime($log['timestamp'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="recent-notice">
                                    Showing most recent <?= count($activityLogs) ?> activity logs from the last <?= $maxDaysOld ?> days
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap & Font Awesome JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 2 minutes
        setTimeout(function() {
            window.location.reload();
        }, 120000);

        // Mark notification as read
        document.querySelectorAll('.mark-read-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const notificationId = this.getAttribute('data-id');
                const notificationCard = this.closest('.notification-card');
                
                // Send AJAX request to mark as read
                fetch('notifications.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'mark_read=1&notification_id=' + notificationId
                })
                .then(response => {
                    if (response.ok) {
                        // Update UI
                        notificationCard.classList.remove('unread');
                        this.remove();
                        
                        // Update unread count
                        const unreadCount = document.querySelectorAll('.notification-card.unread').length;
                        const countBadge = document.querySelector('#notifications-tab .badge');
                        if (countBadge) {
                            countBadge.textContent = unreadCount;
                            if (unreadCount === 0) {
                                countBadge.remove();
                                document.querySelector('.btn-mark-all').remove();
                            }
                        }
                    }
                });
            });
        });

        // Preserve tab selection on page refresh
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab');

            if (activeTab === 'logs') {
                const tab = new bootstrap.Tab(document.getElementById('logs-tab'));
                tab.show();
            }
        });

        // Update URL when switching tabs
        document.getElementById('logs-tab').addEventListener('shown.bs.tab', function() {
            const url = new URL(window.location);
            url.searchParams.set('tab', 'logs');
            window.history.pushState({}, '', url);
        });

        document.getElementById('notifications-tab').addEventListener('shown.bs.tab', function() {
            const url = new URL(window.location);
            url.searchParams.delete('tab');
            window.history.pushState({}, '', url);
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>