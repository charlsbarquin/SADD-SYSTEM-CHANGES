<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

header('Content-Type: application/json');

$response = ['hasNew' => false, 'count' => 0];

try {
    // Check if user is logged in
    if (!isset($_SESSION['admin_id']) && !isset($_SESSION['professor_id'])) {
        throw new Exception('Unauthorized');
    }

    // Get user ID based on session
    $userId = isset($_SESSION['professor_id']) ? $_SESSION['professor_id'] : $_SESSION['admin_id'];
    $userType = isset($_SESSION['professor_id']) ? 'professor' : 'admin';

    // Get count of unread notifications for this user
    $query = "SELECT COUNT(*) FROM notifications 
              WHERE is_read = 0 
              AND user_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_row()[0];

    // Also check system-wide notifications for admins
    if ($userType === 'admin') {
        $systemQuery = "SELECT COUNT(*) FROM notifications 
                       WHERE is_read = 0 
                       AND user_id IS NULL";
        $systemResult = $conn->query($systemQuery);
        $systemCount = $systemResult->fetch_row()[0];
        $count += $systemCount;
    }

    $response = [
        'hasNew' => $count > 0,
        'count' => $count
    ];

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>