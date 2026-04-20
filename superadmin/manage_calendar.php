<?php
$page_title = "Manage Calendar";
$required_role = "superadmin";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';

$sa_active_page = 'global_settings.php';

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    event_date DATE NOT NULL,
    event_type VARCHAR(50),
    details TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$feedback = '';

// --- HANDLE ADD EVENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_event'])) {
    $title = trim($_POST['title']);
    $event_date = trim($_POST['event_date']);
    $event_type = trim($_POST['event_type']);
    $details = trim($_POST['details']);
    $created_by = $_SESSION['user_id'];

    if (!empty($title) && !empty($event_date) && !empty($event_type)) {
        $stmt = $conn->prepare("INSERT INTO calendar_events (title, event_date, event_type, details, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $title, $event_date, $event_type, $details, $created_by);
        if ($stmt->execute()) {
            $_SESSION['feedback'] = "<div class='success-banner'>Event added successfully.</div>";
        }
        $stmt->close();
    }
    header("Location: manage_calendar.php");
    exit();
}

// --- HANDLE EDIT EVENT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_event'])) {
    $event_id = (int)$_POST['event_id'];
    $title = trim($_POST['title']);
    $event_date = trim($_POST['event_date']);
    $event_type = trim($_POST['event_type']);
    $details = trim($_POST['details']);

    if ($event_id > 0 && !empty($title) && !empty($event_date)) {
        $stmt = $conn->prepare("UPDATE calendar_events SET title = ?, event_date = ?, event_type = ?, details = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $title, $event_date, $event_type, $details, $event_id);
        if ($stmt->execute()) {
            $_SESSION['feedback'] = "<div class='success-banner'>Event updated successfully.</div>";
        }
        $stmt->close();
    }
    header("Location: manage_calendar.php");
    exit();
}

$feedback = isset($_SESSION['feedback']) ? $_SESSION['feedback'] : '';
unset($_SESSION['feedback']);

require_once '../includes/header.php';
?>
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
<link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/superadmin_shell.css?v=<?php echo time(); ?>">

<?php require_once __DIR__ . '/components/shell_start.php'; ?>

<div class="page-container">
    <div class="dashboard-header">
        <h1>Manage Calendar Events</h1>
        <button id="openAddModalBtn" class="btn-submit">Add New Event</button>
    </div>
    <?php echo $feedback; ?>
    <div id="calendar"></div>
</div>

<div id="addEventModal" class="modal">
    <div class="modal-content">
        <form action="manage_calendar.php" method="POST">
            <div class="modal-header"><h2>Add New Event</h2><span class="modal-close-btn">&times;</span></div>
            <div class="modal-body">
                <div class="form-group"><label for="add_title">Event Title</label><input type="text" id="add_title" name="title" required></div>
                <div class="form-group"><label for="add_event_date">Date</label><input type="date" id="add_event_date" name="event_date" required></div>
                <div class="form-group"><label for="add_event_type">Event Type</label><select name="event_type" id="add_event_type" required><option value="">Select Type</option><option value="Doctor Event">🩺 Doctor Event</option><option value="Company Event">🏢 Company Event</option><option value="Holiday">🌴 Holiday</option><option value="Birthday">🎂 Birthday</option><option value="Anniversary">💍 Anniversary</option><option value="Other">✨ Other</option></select></div>
                <div class="form-group"><label for="add_details">Details</label><textarea id="add_details" name="details" rows="3"></textarea></div>
            </div>
            <div class="modal-footer"><button type="submit" name="add_event" class="btn btn-primary">Save Event</button></div>
        </form>
    </div>
</div>

<div id="viewEventModal" class="modal">
    <div class="modal-content">
        <form action="manage_calendar.php" method="POST">
            <input type="hidden" id="edit_event_id" name="event_id">
            <div class="modal-header"><h2>Event Details</h2><span class="modal-close-btn">&times;</span></div>
            <div class="modal-body">
                <div id="eventDetailsView" class="event-details-view">
                    <p><strong>Title:</strong> <span id="viewEventTitle"></span></p>
                    <p><strong>Date:</strong> <span id="viewEventDate"></span></p>
                    <p><strong>Type:</strong> <span id="viewEventType"></span></p>
                    <p><strong>Details:</strong><br><span id="viewEventDetails"></span></p>
                </div>
                <div id="eventEditForm" style="display: none;">
                    <div class="form-group"><label for="edit_title">Event Title</label><input type="text" id="edit_title" name="title" required></div>
                    <div class="form-group"><label for="edit_event_date">Date</label><input type="date" id="edit_event_date" name="event_date" required></div>
                    <div class="form-group"><label for="edit_event_type">Event Type</label><select name="event_type" id="edit_event_type" required><option value="">Select Type</option><option value="Doctor Event">🩺 Doctor Event</option><option value="Company Event">🏢 Company Event</option><option value="Holiday">🌴 Holiday</option><option value="Birthday">🎂 Birthday</option><option value="Anniversary">💍 Anniversary</option><option value="Other">✨ Other</option></select></div>
                    <div class="form-group"><label for="edit_details">Details</label><textarea id="edit_details" name="details" rows="3"></textarea></div>
                </div>
            </div>
            <div class="modal-footer" id="viewModalFooter">
                <button type="button" id="deleteEventBtn" class="btn btn-danger">Delete</button>
                <button type="button" id="editEventBtn" class="btn btn-edit">Edit</button>
                <button type="submit" name="edit_event" id="saveChangesBtn" class="btn btn-primary" style="display:none;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Calendar Initialization ---
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        height: 600,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
        events: 'ajax_calendar_events.php',
        eventClick: function(info) {
            openViewModal(info.event);
        },
        dateClick: function(info) {
            openAddModal(info.dateStr);
        }
    });
    calendar.render();

    // --- Modal Handling ---
    const addModal = document.getElementById('addEventModal');
    const viewModal = document.getElementById('viewEventModal');
    
    document.getElementById('openAddModalBtn').addEventListener('click', () => openAddModal());

    // Generic function to open Add Modal
    function openAddModal(date = '') {
        addModal.querySelector('form').reset();
        if (date) {
            addModal.querySelector('#add_event_date').value = date;
        }
        addModal.style.display = 'block';
    }

    // Function to open View/Edit Modal
    function openViewModal(event) {
        // Fetch full event details including description
        fetch('ajax_check_events_popup.php')
            .then(response => response.json())
            .then(allEvents => {
                const eventData = allEvents.find(e => e.id == event.id);
                if (!eventData) return;

                // Populate and show view state
                document.getElementById('eventDetailsView').style.display = 'block';
                document.getElementById('viewEventTitle').textContent = eventData.title;
                document.getElementById('viewEventDate').textContent = new Date(eventData.start).toLocaleDateString('en-CA');
                document.getElementById('viewEventType').textContent = eventData.event_type;
                document.getElementById('viewEventDetails').textContent = eventData.details || 'N/A';
                
                // Populate edit form fields (for later)
                document.getElementById('edit_event_id').value = eventData.id;
                document.getElementById('edit_title').value = eventData.title;
                document.getElementById('edit_event_date').value = eventData.start;
                document.getElementById('edit_event_type').value = eventData.event_type;
                document.getElementById('edit_details').value = eventData.details;

                // Reset button states
                document.getElementById('eventEditForm').style.display = 'none';
                document.getElementById('editEventBtn').style.display = 'inline-block';
                document.getElementById('deleteEventBtn').style.display = 'inline-block';
                document.getElementById('saveChangesBtn').style.display = 'none';

                viewModal.style.display = 'block';
            });
    }

    // Close buttons for all modals
    document.querySelectorAll('.modal-close-btn').forEach(btn => {
        btn.onclick = () => {
            addModal.style.display = 'none';
            viewModal.style.display = 'none';
        }
    });
    window.onclick = (event) => {
        if (event.target == addModal || event.target == viewModal) {
            addModal.style.display = 'none';
            viewModal.style.display = 'none';
        }
    };

    // --- Modal Button Logic ---
    document.getElementById('editEventBtn').addEventListener('click', function() {
        // Switch to edit mode
        document.getElementById('eventDetailsView').style.display = 'none';
        document.getElementById('eventEditForm').style.display = 'block';
        this.style.display = 'none'; // Hide Edit button
        document.getElementById('deleteEventBtn').style.display = 'none'; // Hide Delete button
        document.getElementById('saveChangesBtn').style.display = 'inline-block'; // Show Save button
    });

    document.getElementById('deleteEventBtn').addEventListener('click', function() {
        const eventId = document.getElementById('edit_event_id').value;
        if (confirm('Are you sure you want to delete this event?')) {
            fetch('ajax_delete_event.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'event_id=' + eventId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    viewModal.style.display = 'none';
                    calendar.refetchEvents();
                } else {
                    alert('Error: ' + (data.message || 'Could not delete event.'));
                }
            });
        }
    });
});
</script>

<?php require_once __DIR__ . '/components/shell_end.php'; ?>
<?php require_once '../includes/footer.php'; ?>