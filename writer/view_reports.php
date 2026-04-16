<?php
$page_title = "View Reports";
$required_role = "writer";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

function format_datetime_label(?string $value): ?string {
    if (empty($value)) {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }
    return date('d M Y, h:i A', $timestamp);
}

$reports = [];
$error_message = '';
$patient_uid_expression = get_patient_identifier_expression($conn, 'p');

$printLogTableSql = "CREATE TABLE IF NOT EXISTS writer_report_print_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_item_id INT UNSIGNED NOT NULL,
    printed_by INT UNSIGNED NOT NULL,
    printed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bill_item (bill_item_id),
    INDEX idx_printed_at (printed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($printLogTableSql);

$sql = "SELECT
            bi.id AS bill_item_id,
            b.id AS bill_id,
            {$patient_uid_expression} AS patient_uid,
            p.name AS patient_name,
            p.age AS patient_age,
            p.sex AS patient_sex,
            t.main_test_name,
            t.sub_test_name AS test_name,
            bi.updated_at AS completed_at,
            pr.print_count,
            pr.last_printed_at,
            pr.printed_history
        FROM bill_items bi
        JOIN bills b ON bi.bill_id = b.id
        JOIN patients p ON b.patient_id = p.id
        JOIN tests t ON bi.test_id = t.id
        LEFT JOIN (
            SELECT
                bill_item_id,
                COUNT(*) AS print_count,
                MAX(printed_at) AS last_printed_at,
                GROUP_CONCAT(printed_at ORDER BY printed_at ASC SEPARATOR '||') AS printed_history
            FROM writer_report_print_logs
            GROUP BY bill_item_id
        ) pr ON pr.bill_item_id = bi.id
        WHERE COALESCE(bi.report_status, 'Pending') = 'Completed'
          AND COALESCE(TRIM(bi.report_content), '') != ''
          AND b.bill_status != 'Void'
        ORDER BY bi.updated_at DESC, bi.id DESC
        LIMIT 250";

$result = $conn->query($sql);
if ($result instanceof mysqli_result) {
    while ($row = $result->fetch_assoc()) {
        $age = isset($row['patient_age']) ? trim((string)$row['patient_age']) : '';
        $sex = isset($row['patient_sex']) ? trim((string)$row['patient_sex']) : '';
        $age_gender = trim($age . ($age !== '' && $sex !== '' ? ' / ' : '') . $sex);

        $printedHistoryLabels = [];
        if (!empty($row['printed_history'])) {
            foreach (explode('||', $row['printed_history']) as $printEntry) {
                $label = format_datetime_label($printEntry);
                if ($label) {
                    $printedHistoryLabels[] = $label;
                }
            }
        }

        $itemId = (int)$row['bill_item_id'];
        $reports[] = [
            'bill_item_id' => $itemId,
            'patient_uid' => $row['patient_uid'],
            'patient_name' => $row['patient_name'],
            'test_name' => trim(($row['main_test_name'] ?? '') !== ''
                ? ($row['main_test_name'] . ' • ' . $row['test_name'])
                : $row['test_name']),
            'age_gender' => $age_gender,
            'completed_label' => format_datetime_label($row['completed_at']),
            'print_count' => isset($row['print_count']) ? (int)$row['print_count'] : 0,
            'last_printed_at' => $row['last_printed_at'],
            'last_printed_label' => format_datetime_label($row['last_printed_at']),
            'printed_history' => $printedHistoryLabels,
            'view_url' => '../templates/print_report.php?item_id=' . urlencode((string)$itemId),
            'edit_url' => 'fill_report.php?item_id=' . urlencode((string)$itemId),
        ];
    }
    $result->free();
} else {
    $error_message = 'Unable to load uploaded reports right now. Please refresh in a moment.';
}

require_once '../includes/header.php';
?>

<div class="main-content page-container writer-reports-view">
    <div class="dashboard-header">
        <div>
            <h1>Report Library</h1>
            <p class="description">Review uploaded reports saved in the in-site Word editor, open printable view, and edit anytime.</p>
        </div>
        <div class="page-actions">
            <a class="btn-secondary" href="dashboard.php">Back to Dashboard</a>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="reports-alert is-warning"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <?php if (empty($reports)): ?>
        <div class="reports-placeholder">
            No uploaded reports yet. Once a report is uploaded from the Word workspace, it will appear here.
        </div>
    <?php else: ?>
        <div class="report-table-card">
            <h2>Uploaded Reports</h2>
            <div class="report-table-wrapper">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Patient ID</th>
                            <th>Patient Name</th>
                            <th>Test Name</th>
                            <th>Age / Gender</th>
                            <th>Status & View</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td><span style="font-size:0.82rem;color:#666;"><?php echo htmlspecialchars($report['patient_uid'] ?? ''); ?></span></td>
                                <td><?php echo htmlspecialchars($report['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($report['test_name']); ?></td>
                                <td><?php echo htmlspecialchars($report['age_gender']); ?></td>
                                <td>
                                    <div class="status-stack">
                                        <span class="status-pill <?php echo $report['last_printed_at'] ? 'is-printed' : 'is-complete'; ?>">
                                            <?php echo $report['last_printed_at'] ? 'Printed' : 'Uploaded'; ?>
                                        </span>
                                        <?php if ($report['completed_label']): ?>
                                            <small>Uploaded: <?php echo htmlspecialchars($report['completed_label']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($report['last_printed_label']): ?>
                                            <small>Last printed: <?php echo htmlspecialchars($report['last_printed_label']); ?></small>
                                        <?php endif; ?>
                                        <div style="display:flex;gap:0.45rem;flex-wrap:wrap;">
                                            <a class="btn-view-report" href="<?php echo htmlspecialchars($report['view_url']); ?>" target="_blank" rel="noopener">View Report</a>
                                            <a class="btn-timeline" href="<?php echo htmlspecialchars($report['edit_url']); ?>">Edit in Word App</a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="btn-timeline"
                                        data-action="show-progress"
                                        data-patient="<?php echo htmlspecialchars($report['patient_name']); ?>"
                                        data-test="<?php echo htmlspecialchars($report['test_name']); ?>"
                                        data-age="<?php echo htmlspecialchars($report['age_gender']); ?>"
                                        data-completed="<?php echo htmlspecialchars($report['completed_label'] ?? ''); ?>"
                                        data-printed="<?php echo htmlspecialchars($report['last_printed_label'] ?? ''); ?>"
                                        data-print-count="<?php echo (int)$report['print_count']; ?>"
                                        data-printed-history="<?php echo htmlspecialchars(implode('||', $report['printed_history']), ENT_QUOTES); ?>">
                                        View Progress
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="timeline-modal" id="timelineModal" aria-hidden="true">
    <div class="timeline-modal__dialog">
        <button type="button" class="timeline-modal__close" data-action="close-progress" aria-label="Close timeline">&times;</button>
        <h3>Report Progress</h3>
        <div class="timeline-grid">
            <span>Patient</span>
            <strong id="timelinePatient"></strong>
            <span>Test</span>
            <strong id="timelineTest"></strong>
            <span>Age / Gender</span>
            <strong id="timelineAge"></strong>
        </div>
        <div class="timeline-steps">
            <div class="timeline-step">
                <div>
                    <h4>Uploaded In Word App</h4>
                    <p>Timestamp captured when the writer uploaded this report.</p>
                </div>
                <strong id="timelineCompleted">—</strong>
            </div>
            <div class="timeline-step">
                <div>
                    <h4>Report Printed</h4>
                    <p>Tracks each time the manager opened the print-ready view.</p>
                </div>
                <strong id="timelinePrinted">—</strong>
            </div>
        </div>
        <div class="timeline-history">
            <strong>Print history</strong>
            <ul id="timelineHistory"></ul>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('timelineModal');
    const patientEl = document.getElementById('timelinePatient');
    const testEl = document.getElementById('timelineTest');
    const ageEl = document.getElementById('timelineAge');
    const completedEl = document.getElementById('timelineCompleted');
    const printedEl = document.getElementById('timelinePrinted');
    const historyEl = document.getElementById('timelineHistory');

    const openModal = (button) => {
        patientEl.textContent = button.dataset.patient || '—';
        testEl.textContent = button.dataset.test || '—';
        ageEl.textContent = button.dataset.age || '—';
        completedEl.textContent = button.dataset.completed || 'Awaiting upload';
        printedEl.textContent = button.dataset.printed || 'Not printed yet';

        historyEl.innerHTML = '';
        const historyRaw = button.dataset.printedHistory || '';
        if (historyRaw.trim() !== '') {
            historyRaw.split('||').forEach((entry) => {
                if (!entry) { return; }
                const li = document.createElement('li');
                li.textContent = entry;
                historyEl.appendChild(li);
            });
        } else {
            const li = document.createElement('li');
            li.textContent = 'No print requests recorded yet.';
            historyEl.appendChild(li);
        }

        modal.classList.add('is-visible');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        modal.classList.remove('is-visible');
        modal.setAttribute('aria-hidden', 'true');
    };

    document.querySelectorAll('[data-action="show-progress"]').forEach((button) => {
        button.addEventListener('click', () => openModal(button));
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.querySelectorAll('[data-action="close-progress"]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-visible')) {
            closeModal();
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
