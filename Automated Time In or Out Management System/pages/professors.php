<?php
require_once __DIR__ . '/../includes/session.php';
include '../config/database.php';

// Fetch all professors from database
$professors = [];
$result = $conn->query("SELECT id, name, designation, email, phone FROM professors ORDER BY name");
if ($result) {
    $professors = $result->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professors | Automated Attendance System</title>

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

        .search-container {
            max-width: 500px;
            margin: 0 auto 2rem;
        }

        .search-input {
            border-radius: 20px 0 0 20px;
            padding-left: 20px;
            border: 1px solid #dee2e6;
        }

        .search-btn {
            border-radius: 0 20px 20px 0;
            border-left: none;
        }

        .professor-card {
            transition: all 0.3s ease;
            border-radius: 8px;
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            height: 100%;
            border-left: 4px solid var(--primary-color);
        }

        .professor-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        }

        .professor-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--light-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: var(--primary-color);
            font-size: 2rem;
            font-weight: bold;
        }

        .professor-name {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .professor-designation {
            color: var(--secondary-color);
            font-size: 0.95rem;
            margin-bottom: 1rem;
        }

        .professor-contact {
            margin-top: 1rem;
        }

        .professor-email, .professor-phone {
            color: var(--secondary-color);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            word-break: break-word;
        }

        .professor-email i, .professor-phone i {
            width: 20px;
            text-align: center;
            margin-right: 0.5rem;
            color: var(--primary-color);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .section-title {
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }

        .badge-count {
            background-color: var(--primary-color);
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 0;
            color: var(--secondary-color);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        @media (max-width: 768px) {
            .professor-card {
                margin-bottom: 1.5rem;
            }
            
            .search-container {
                margin-bottom: 1.5rem;
            }
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
                    <h1><i class="fas fa-chalkboard-teacher me-2"></i>Professors</h1>
                    <p class="page-subtitle">Manage and view all professors in the system</p>
                </div>

                <!-- Search Section -->
                <div class="search-container">
                    <div class="input-group">
                        <input type="text" class="form-control search-input" placeholder="Search by name, email or designation..." id="searchInput">
                        <button class="btn btn-primary search-btn" type="button" id="searchBtn">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                    </div>
                </div>

                <!-- Professors List Section -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title"><i class="fas fa-list me-2"></i>Professor List</h2>
                        <span class="badge badge-count">
                            <?= count($professors) ?> professor<?= count($professors) !== 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4" id="professorsContainer">
                        <?php if (count($professors) > 0): ?>
                            <?php foreach ($professors as $professor): 
                                $initials = '';
                                $nameParts = explode(' ', $professor['name']);
                                foreach ($nameParts as $part) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                }
                                $initials = substr($initials, 0, 2);
                            ?>
                                <div class="col professor-item">
                                    <div class="card professor-card h-100">
                                        <div class="card-body text-center p-4">
                                            <!-- Professor Avatar -->
                                            <div class="professor-avatar">
                                                <?= $initials ?>
                                            </div>
                                            
                                            <!-- Professor Info -->
                                            <h5 class="professor-name"><?= htmlspecialchars($professor['name']) ?></h5>
                                            <p class="professor-designation"><?= htmlspecialchars($professor['designation']) ?></p>
                                            
                                            <!-- Contact Info -->
                                            <div class="professor-contact">
                                                <div class="professor-email">
                                                    <i class="fas fa-envelope"></i>
                                                    <?= htmlspecialchars($professor['email']) ?>
                                                </div>
                                                <?php if (!empty($professor['phone'])): ?>
                                                    <div class="professor-phone">
                                                        <i class="fas fa-phone"></i>
                                                        <?= htmlspecialchars($professor['phone']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="empty-state">
                                    <i class="fas fa-user-graduate"></i>
                                    <h4>No Professors Found</h4>
                                    <p>There are currently no professors registered in the system</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $(document).ready(function() {
            // Search functionality
            $('#searchBtn').click(performSearch);
            $('#searchInput').keyup(function(e) {
                if (e.key === 'Enter') performSearch();
                else performSearch(); // Live search as typing
            });

            function performSearch() {
                const searchTerm = $('#searchInput').val().toLowerCase().trim();
                let visibleCount = 0;

                $('.professor-item').each(function() {
                    const $card = $(this);
                    const name = $card.find('.professor-name').text().toLowerCase();
                    const designation = $card.find('.professor-designation').text().toLowerCase();
                    const email = $card.find('.professor-email').text().toLowerCase();
                    const phone = $card.find('.professor-phone').text().toLowerCase();

                    const matches = name.includes(searchTerm) || 
                                  designation.includes(searchTerm) || 
                                  email.includes(searchTerm) || 
                                  phone.includes(searchTerm);

                    $card.toggle(matches);
                    if (matches) visibleCount++;
                });

                // Show no results message if needed
                $('.no-results-message').remove();
                if (visibleCount === 0 && searchTerm !== '') {
                    $('#professorsContainer').append(`
                        <div class="col-12 no-results-message">
                            <div class="empty-state">
                                <i class="fas fa-search"></i>
                                <h4>No Matching Professors</h4>
                                <p>No professors found matching your search criteria</p>
                            </div>
                        </div>
                    `);
                }
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>