// Global variables
const currentSession = '<?php echo $currentSession; ?>';
const professorId = <?php echo json_encode($professorId); ?>;
const professorName = <?php echo json_encode($professorName); ?>;
const isLate = <?php echo json_encode($isLate); ?>;

// Load attendance overview
async function loadAttendanceOverview() {
    try {
        const response = await fetch("../api/get-attendance-overview.php");
        if (!response.ok) throw new Error("Failed to fetch attendance overview");

        const data = await response.json();

        // Update dashboard cards
        updateDashboardCard("total-professors", data.total_professors);
        updateDashboardCard("total-attendance", data.total_attendance);
        updateDashboardCard("pending-checkouts", data.pending_checkouts);

        // Update attendance status chart
        if (window.attendanceChart && data.status_distribution) {
            updateAttendanceChart(data.status_distribution);
        }
    } catch (error) {
        console.error("Error loading attendance overview:", error);
        showErrorToast("Failed to load attendance data", error.message);
    }
}

// Check professor schedule
async function checkProfessorSchedule() {
    if (!professorId) return;
    
    try {
        const response = await fetch(`../api/check-schedule.php?professor_id=${professorId}`);
        if (!response.ok) throw new Error("Failed to fetch schedule data");
        
        const data = await response.json();
        
        if (data.has_schedule && data.schedule) {
            // Update UI to show schedule info
            const scheduleInfo = document.createElement('div');
            scheduleInfo.className = 'schedule-info mt-2';
            scheduleInfo.innerHTML = `
                <div class="alert alert-info p-2">
                    <strong>Today's Schedule:</strong> 
                    ${data.schedule.subject || 'No subject'} (${data.schedule.room || 'No room'})<br>
                    <small>${formatTime(data.schedule.start_time)} - ${formatTime(data.schedule.end_time)}</small>
                    ${data.is_late ? '<span class="badge bg-warning text-dark float-end">Late</span>' : ''}
                </div>
            `;
            
            const welcomeMsg = document.querySelector('.welcome-message');
            if (welcomeMsg) {
                // Remove existing schedule info if present
                const existingInfo = welcomeMsg.querySelector('.schedule-info');
                if (existingInfo) {
                    existingInfo.remove();
                }
                welcomeMsg.appendChild(scheduleInfo);
            }
        }
    } catch (error) {
        console.error('Error checking schedule:', error);
    }
}

// Format time to 12-hour format
function formatTime(timeString) {
    if (!timeString) return '';
    try {
        const [hours, minutes] = timeString.split(':');
        const hour = parseInt(hours);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const hour12 = hour % 12 || 12;
        return `${hour12}:${minutes} ${ampm}`;
    } catch (e) {
        console.error('Error formatting time:', e);
        return timeString;
    }
}

// Update dashboard card with animation
function updateDashboardCard(elementId, value) {
    const element = document.getElementById(elementId);
    if (!element) return;

    animateCounter(elementId, value);
}

// Animate counter values
function animateCounter(elementId, targetValue, duration = 1000) {
    const element = document.getElementById(elementId);
    if (!element) return;

    const start = parseInt(element.textContent) || 0;
    const increment = (targetValue - start) / (duration / 16);
    let current = start;

    const timer = setInterval(() => {
        current += increment;
        if ((increment > 0 && current >= targetValue) || (increment < 0 && current <= targetValue)) {
            clearInterval(timer);
            current = targetValue;
        }
        element.textContent = Math.round(current);
    }, 16);
}

// Load recent history with pagination
async function loadRecentHistory(page = 1) {
    try {
        showLoading(true);
        const response = await fetch(`../api/get-recent-history.php?page=${page}&per_page=5`);
        if (!response.ok) throw new Error("Failed to fetch recent history");

        const data = await response.json();
        updateHistoryList(data.data);
        updatePaginationControls(data.pagination);
    } catch (error) {
        console.error("Error loading recent history:", error);
        showErrorToast("Failed to load recent history", error.message);
    } finally {
        showLoading(false);
    }
}

// Show/hide loading state
function showLoading(show) {
    const spinner = document.getElementById("refresh-spinner");
    const viewMoreText = document.getElementById("view-more-text");
    const viewMoreBtn = document.getElementById("view-more-btn");

    if (spinner && viewMoreText && viewMoreBtn) {
        if (show) {
            spinner.classList.remove("d-none");
            viewMoreText.textContent = "Loading...";
            viewMoreBtn.disabled = true;
        } else {
            spinner.classList.add("d-none");
            viewMoreText.textContent = "View More";
            viewMoreBtn.disabled = false;
        }
    }
}

// Update history list
function updateHistoryList(historyData) {
    const historyList = document.getElementById("recent-history-list");
    if (!historyList) return;

    if (historyData && historyData.length > 0) {
        historyList.innerHTML = historyData.map(item => `
            <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong>${item.professor_name || 'Unknown'}</strong>
                        <div class="text-muted small">${item.action || 'No action'}</div>
                    </div>
                    <div class="text-end">
                        <small class="text-muted">${item.time || ''}<br>${item.date || ''}</small>
                    </div>
                </div>
            </li>
        `).join('');
    } else {
        historyList.innerHTML = '<li class="list-group-item text-center text-muted">No records found</li>';
    }
}

// Update pagination controls
function updatePaginationControls(pagination) {
    const paginationContainer = document.getElementById("history-pagination");
    if (!paginationContainer || !pagination) return;

    const { page, total_pages } = pagination;
    let html = '';

    // Previous button
    html += `<li class="page-item ${page === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${page - 1}">Previous</a>
            </li>`;

    // Page numbers
    const maxVisiblePages = 5;
    let startPage = Math.max(1, page - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(total_pages, startPage + maxVisiblePages - 1);

    if (endPage - startPage + 1 < maxVisiblePages) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }

    if (startPage > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>`;
        if (startPage > 2) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }

    for (let i = startPage; i <= endPage; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>`;
    }

    if (endPage < total_pages) {
        if (endPage < total_pages - 1) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        html += `<li class="page-item"><a class="page-link" href="#" data-page="${total_pages}">${total_pages}</a></li>`;
    }

    // Next button
    html += `<li class="page-item ${page >= total_pages ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${page + 1}">Next</a>
            </li>`;

    paginationContainer.innerHTML = html;

    // Add event listeners to pagination links
    document.querySelectorAll('#history-pagination .page-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            if (this.parentElement.classList.contains('disabled')) return;
            const page = parseInt(this.getAttribute('data-page'));
            loadRecentHistory(page);
        });
    });
}

// Handle View More button click
function handleViewMore() {
    const currentPage = parseInt(document.querySelector('#history-pagination .page-item.active a')?.getAttribute('data-page') || '1');
    loadRecentHistory(currentPage);
}

// Setup event listeners
function setupEventListeners() {
    // Time Out button
    const timeoutBtn = document.getElementById("confirm-timeout");
    if (timeoutBtn) {
        timeoutBtn.addEventListener("click", handleTimeOut);
    }

    // Search functionality
    const searchInput = document.getElementById("search-professor");
    if (searchInput) {
        searchInput.addEventListener("input", debounce(function() {
            const searchTerm = this.value.toLowerCase();
            const items = document.querySelectorAll("#professor-list .list-group-item");
            if (items) {
                items.forEach(item => {
                    const name = item.querySelector('strong')?.textContent.toLowerCase() || '';
                    item.style.display = name.includes(searchTerm) ? "" : "none";
                });
            }
        }, 300));
    }

    // View More button
    const viewMoreBtn = document.getElementById("view-more-btn");
    if (viewMoreBtn) {
        viewMoreBtn.addEventListener("click", function(e) {
            e.preventDefault();
            handleViewMore();
        });
    }

    // Initialize tooltips
    if (window.bootstrap?.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
}

// Handle time out
async function handleTimeOut() {
    const btn = document.getElementById("confirm-timeout");
    if (!btn) return;

    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing';

    try {
        const response = await fetch("../api/time-out.php", {
            method: "POST",
            headers: { 
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: `professor_id=${professorId}&session=${currentSession}`
        });

        if (!response.ok) throw new Error("Network response was not ok");

        const data = await response.json();
        if (data.status !== 'success') throw new Error(data.message || 'Time out failed');

        showSuccessModal(
            `${currentSession} Time Out Successful`,
            `You have been successfully checked out from ${currentSession} session at ${new Date().toLocaleTimeString()}.`
        );
        
        await updateWorkDuration();
        
        // Refresh data
        setTimeout(() => {
            loadAttendanceOverview();
            loadRecentHistory();
        }, 1000);
    } catch (error) {
        console.error("Time out error:", error);
        showErrorModal('Time Out Failed', error.message || 'An error occurred during time out');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// Update work duration
async function updateWorkDuration() {
    try {
        const response = await fetch("../api/calculate-work-duration.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: `professor_id=${professorId}&date=${new Date().toISOString().split('T')[0]}`
        });
        
        if (!response.ok) throw new Error("Network response was not ok");
        
        const data = await response.json();
        if (data.status !== 'success') {
            console.error('Failed to update work duration:', data.message);
        }
    } catch (error) {
        console.error('Error updating work duration:', error);
    }
}

// Show success modal
function showSuccessModal(title, message) {
    const successMessage = document.getElementById("success-message");
    const successDetails = document.getElementById("success-details");
    const successModal = document.getElementById("successModal");

    if (successMessage && successDetails && successModal) {
        successMessage.textContent = title;
        successDetails.textContent = message;
        new bootstrap.Modal(successModal).show();
    }
}

// Show error modal
function showErrorModal(title, message) {
    const errorTitle = document.getElementById("error-title");
    const errorMessage = document.getElementById("error-message");
    const errorModal = document.getElementById("errorModal");

    if (errorTitle && errorMessage && errorModal) {
        errorTitle.textContent = title;
        errorMessage.textContent = message;
        new bootstrap.Modal(errorModal).show();
    }
}

// Show success toast
function showSuccessToast(title, message) {
    const toastContainer = document.getElementById("toast-container");
    if (!toastContainer) return;

    const toastId = `toast-${Date.now()}`;
    const toastHTML = `
        <div id="${toastId}" class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-success text-white">
                <strong class="me-auto">${title}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `;

    toastContainer.insertAdjacentHTML('beforeend', toastHTML);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        const toast = document.getElementById(toastId);
        if (toast) {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }
    }, 5000);
}

// Show error toast
function showErrorToast(title, message) {
    const toastContainer = document.getElementById("toast-container");
    if (!toastContainer) return;

    const toastId = `toast-${Date.now()}`;
    const toastHTML = `
        <div id="${toastId}" class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-danger text-white">
                <strong class="me-auto">${title}</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `;

    toastContainer.insertAdjacentHTML('beforeend', toastHTML);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        const toast = document.getElementById(toastId);
        if (toast) {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }
    }, 5000);
}

// Debounce function for search input
function debounce(func, wait, immediate) {
    let timeout;
    return function() {
        const context = this, args = arguments;
        const later = function() {
            timeout = null;
            if (!immediate) func.apply(context, args);
        };
        const callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
        if (callNow) func.apply(context, args);
    };
}

// Initialize clock and date
function initClockAndDate() {
    function update() {
        const now = new Date();

        // Update clock
        const clockElement = document.getElementById('clock');
        if (clockElement) {
            clockElement.textContent = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
        }

        // Update date
        const dateElement = document.getElementById('current-date');
        if (dateElement) {
            dateElement.textContent = now.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
    }
    update();
    setInterval(update, 1000);
}

// Initialize dropdowns
function initDropdowns() {
    const notificationToggle = document.getElementById('notificationDropdown');
    const profileToggle = document.getElementById('profileDropdown');

    if (!notificationToggle || !profileToggle) return;

    const notificationDropdown = new bootstrap.Dropdown(notificationToggle);
    const profileDropdown = new bootstrap.Dropdown(profileToggle);

    notificationToggle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        profileDropdown.hide();
        notificationDropdown.toggle();
    });

    profileToggle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        notificationDropdown.hide();
        profileDropdown.toggle();
    });

    document.addEventListener('click', function(e) {
        if (!notificationToggle.contains(e.target)) {
            notificationDropdown.hide();
        }
        if (!profileToggle.contains(e.target)) {
            profileDropdown.hide();
        }
    });

    window.addEventListener('scroll', function() {
        notificationDropdown.hide();
        profileDropdown.hide();
    });
}

// Initialize attendance chart
function initializeAttendanceChart() {
    const ctx = document.getElementById('attendanceChart')?.getContext('2d');
    if (!ctx) return;

    window.attendanceChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Present', 'Absent', 'Late'],
            datasets: [{
                data: [0, 0, 0],
                backgroundColor: [
                    '#28a745',
                    '#dc3545',
                    '#ffc107'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

// Update attendance chart with new data
function updateAttendanceChart(data) {
    if (!window.attendanceChart) return;

    window.attendanceChart.data.datasets[0].data = [
        data.present || 0,
        data.absent || 0,
        data.late || 0
    ];
    window.attendanceChart.update();
}

// Initialize on DOM load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize modals
    const timeOutModal = document.getElementById("timeOutModal");
    const successModal = document.getElementById("successModal");
    const errorModal = document.getElementById("errorModal");

    // Initialize clock and date
    initClockAndDate();

    // Initialize attendance chart if container exists
    if (document.getElementById("attendanceChart")) {
        initializeAttendanceChart();
    }

    // Load data
    loadAttendanceOverview();
    loadRecentHistory();
    checkProfessorSchedule();

    // Setup event listeners
    setupEventListeners();
    initDropdowns();

    // Success modal OK button
    const successOkBtn = document.getElementById("success-ok-btn");
    if (successOkBtn) {
        successOkBtn.addEventListener('click', function() {
            const modal = bootstrap.Modal.getInstance(successModal);
            if (modal) {
                modal.hide();
                setTimeout(() => location.reload(), 300);
            }
        });
    }
});

// Listen for online/offline status changes
window.addEventListener('online', () => {
    showSuccessToast("Connection Restored", "You're back online. Syncing data...");
    loadAttendanceOverview();
    loadRecentHistory();
});

window.addEventListener('offline', () => {
    showErrorToast("Connection Lost", "You're currently offline. Some features may not work.");
});

// Check service worker support for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then(registration => {
            console.log('ServiceWorker registration successful');
        }).catch(err => {
            console.log('ServiceWorker registration failed: ', err);
        });
    });
}