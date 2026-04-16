<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_package_management_schema($conn);

// Basic security check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$viewer_role = $_SESSION['role'] ?? '';
if (!in_array($viewer_role, ['writer', 'manager', 'superadmin'], true)) {
    http_response_code(403);
    die('Forbidden: You do not have permission to view reports.');
}

if (!isset($_GET['item_id']) || !is_numeric($_GET['item_id'])) {
    die("Invalid Report Item ID.");
}

$item_id = (int)$_GET['item_id'];

// Fetch all necessary report details from the database
$stmt = $conn->prepare(
    "SELECT 
        bi.report_content, bi.updated_at as report_date, COALESCE(bi.report_status, 'Pending') as report_status,
        b.id as bill_id,
        p.name as patient_name, p.age, p.sex,
        t.main_test_name, t.sub_test_name,
          COALESCE(NULLIF(bi.package_name, ''), tp.package_name) AS package_name,
        rd.doctor_name as referral_doctor_name
     FROM bill_items bi
     JOIN bills b ON bi.bill_id = b.id
     JOIN patients p ON b.patient_id = p.id
     JOIN tests t ON bi.test_id = t.id
      LEFT JOIN test_packages tp ON tp.id = bi.package_id
     LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
     WHERE bi.id = ?"
);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$report_result = $stmt->get_result();

if ($report_result->num_rows === 0) {
    die("Report not found.");
}
$report = $report_result->fetch_assoc();
$stmt->close();

if ($viewer_role === 'manager' && ($report['report_status'] ?? 'Pending') !== 'Completed') {
    http_response_code(403);
    die('Report is not uploaded yet. Manager access is available only after upload.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report for <?php echo htmlspecialchars($report['patient_name']); ?> - <?php echo htmlspecialchars($report['sub_test_name']); ?></title>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap');

        :root {
            --text-dark: #333;
            --text-light: #777;
            --border-color: #e0e0e0;
            --header-blue: #004a99;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            color: var(--text-dark);
        }

        .print-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #fff;
            padding: 10px 15px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 100;
        }

        .print-controls button, .print-controls a {
            background-color: var(--header-blue);
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
            margin-left: 10px;
        }
        .print-controls button:hover, .print-controls a:hover {
            background-color: #003366;
        }

        /* The main container, styled to look like an A4 paper */
        .report-container {
            width: 210mm; /* A4 width */
            min-height: 297mm; /* A4 height */
            margin: 20px auto;
            background: white;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            padding: 1in;
            box-sizing: border-box;
        }

        /* --- Report Header (Letterhead) --- */
        .report-header {
            text-align: center;
            border-bottom: 3px solid var(--header-blue);
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .report-header h1 {
            margin: 0;
            color: var(--header-blue);
            font-size: 28px;
            font-weight: 700;
        }
        .report-header p {
            margin: 5px 0 0;
            color: var(--text-light);
            font-size: 14px;
        }

        /* --- Patient Details Section --- */
        .patient-info {
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }
        .patient-info h2 {
            margin: 0;
            padding: 12px 15px;
            background-color: #f9f9f9;
            font-size: 16px;
            color: var(--text-dark);
            border-bottom: 1px solid var(--border-color);
        }
        .patient-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            padding: 15px;
            gap: 15px;
        }
        .patient-info-grid div {
            font-size: 14px;
        }
        .patient-info-grid strong {
            color: var(--text-light);
            display: inline-block;
            width: 120px; /* Aligns the values */
        }
        
        /* --- Main Report Body --- */
        .report-body {
            margin-top: 30px;
        }
        .report-body h2.report-title {
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            text-decoration: underline;
            margin-bottom: 25px;
        }
        .report-content {
            font-size: 14px;
            line-height: 1.6;
            text-align: justify;
        }
        /* Ensure content from the editor keeps its formatting */
        .report-content p { margin: 0 0 1em 0; }
        .report-content strong { font-weight: 700; }
        .report-content em { font-style: italic; }
        .report-content ul, .report-content ol { padding-left: 20px; }
        
        /* --- Report Footer (Signature) --- */
        .report-footer {
            margin-top: 80px;
            text-align: right;
        }
        .signature-area {
            display: inline-block;
            text-align: center;
            padding-top: 10px;
            border-top: 1px solid var(--text-dark);
            width: 250px;
        }
        .signature-area strong {
            font-size: 14px;
            font-weight: 500;
        }

        /* --- Special styles for printing the page --- */
        @media print {
            body {
                background: none;
            }
            .print-controls {
                display: none; /* Hide the print button when printing */
            }
            .report-container {
                margin: 0;
                width: 100%;
                min-height: 0;
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>

    <div class="print-controls">
        <button onclick="window.print()">Print Report</button>
        <a href="../writer/dashboard.php">Back to Dashboard</a>
    </div>

    <div class="report-container">
        <div class="report-header">
            <h1>Divya Imaging & Diagnostics</h1>
            <p>1st Floor, Near Clock Tower, Mahbubnagar | Phone: (123) 456-7890</p>
        </div>

        <div class="patient-info">
            <h2>Patient & Report Details</h2>
            <div class="patient-info-grid">
                <div><strong>Patient Name:</strong> <?php echo htmlspecialchars($report['patient_name']); ?></div>
                <div><strong>Bill No:</strong> <?php echo $report['bill_id']; ?></div>
                <div><strong>Age / Gender:</strong> <?php echo htmlspecialchars($report['age']); ?> / <?php echo htmlspecialchars($report['sex']); ?></div>
                <div><strong>Report Date:</strong> <?php echo date("F j, Y, g:i a", strtotime($report['report_date'])); ?></div>
                <?php if (!empty($report['package_name'])): ?>
                    <div style="grid-column: 1 / -1;"><strong>Package:</strong> <?php echo htmlspecialchars($report['package_name']); ?></div>
                <?php endif; ?>
                <div style="grid-column: 1 / -1;"><strong>Referred By:</strong> <?php echo htmlspecialchars($report['referral_doctor_name'] ?? 'Self'); ?></div>
            </div>
        </div>

        <div class="report-body">
            <h2 class="report-title"><?php echo strtoupper(htmlspecialchars($report['sub_test_name'])); ?><?php echo !empty($report['package_name']) ? ' (PACKAGE)' : ''; ?></h2>
            <div class="report-content">
                <?php echo $report['report_content']; // This displays the formatted content from the editor ?>
            </div>
        </div>

        <div class="report-footer">
            <div class="signature-area">
                <strong>Lab Director / Pathologist</strong>
            </div>
        </div>
    </div>

</body>
</html>