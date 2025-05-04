<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

header('Content-Type: application/json');

try {
    // Check authentication
    if (!isset($_SESSION['admin_id'])) {
        throw new Exception('Unauthorized access');
    }

    // Get parameters
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // Query for notifications
    $query = "SELECT n.*, 
                     CASE 
                         WHEN n.user_id IS NULL THEN 'System'
                         WHEN p.id IS NOT NULL THEN p.name
                         WHEN a.id IS NOT NULL THEN a.username
                         ELSE 'Unknown'
                     END as user_name
              FROM notifications n
              LEFT JOIN professors p ON n.user_id = p.id
              LEFT JOIN admins a ON n.user_id = a.id
              ORDER BY created_at DESC 
              LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        // Standardize terminology
        $message = str_replace(
            ['timed in', 'timed out', 'Time-in', 'Time-out', 'AM session', 'PM session'],
            ['Timed In', 'Timed Out', 'Time In', 'Time Out', 'Morning Session', 'Afternoon Session'],
            $row['message']
        );

        $notifications[] = [
            'id' => $row['id'],
            'type' => ucfirst(str_replace('-', ' ', $row['type'])),
            'message' => $message,
            'user_name' => $row['user_name'],
            'timestamp' => $row['created_at'],
            'is_read' => (bool)$row['is_read']
        ];
    }

    // Mark notifications as read when fetched by admin
    if (!empty($notifications)) {
        $ids = array_column($notifications, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $markReadQuery = "UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($markReadQuery);
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
    }

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'count' => count($notifications)
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>