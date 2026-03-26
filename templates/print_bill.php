<?php
require_once '../includes/db_connect.php';

if (!isset($_GET['bill_id']) || !is_numeric($_GET['bill_id'])) {
    die('Invalid Bill ID provided.');
}
$bill_id = (int) $_GET['bill_id'];

$stmt = $conn->prepare(
    "SELECT b.id AS invoice_number, b.gross_amount, b.discount, b.net_amount, b.created_at,
            p.uid AS patient_uid, p.name AS patient_name, p.age, p.sex, p.address, p.city, p.mobile_number,
            u.username AS receptionist_name,
            rd.doctor_name AS referral_doctor_name
     FROM bills b
     JOIN patients p ON b.patient_id = p.id
     JOIN users u ON b.receptionist_id = u.id
     LEFT JOIN referral_doctors rd ON b.referral_doctor_id = rd.id
     WHERE b.id = ?"
);
$stmt->bind_param('i', $bill_id);
$stmt->execute();
$bill_result = $stmt->get_result();

if ($bill_result->num_rows === 0) {
    die('Bill not found for the given ID.');
}
$bill = $bill_result->fetch_assoc();
$stmt->close();

$items_stmt = $conn->prepare(
    "SELECT bi.id AS bill_item_id, t.main_test_name, t.sub_test_name, t.price, COALESCE(bis.screening_amount, 0) AS screening_amount
     FROM bill_items bi
     JOIN tests t ON bi.test_id = t.id
     LEFT JOIN bill_item_screenings bis ON bis.bill_item_id = bi.id
     WHERE bi.bill_id = ? AND bi.item_status = 0
     ORDER BY bi.id ASC"
);
$items_stmt->bind_param('i', $bill_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$bill_items = $items_result->fetch_all(MYSQLI_ASSOC);
$items_stmt->close();

$display_items = [];
foreach ($bill_items as $item) {
    $nameParts = trim(preg_replace('/\s+/', ' ', $item['main_test_name'] . ' ' . $item['sub_test_name']));
    $label = 'Test ' . $nameParts;
    $display_items[] = [
        'label' => $label,
        'amount' => (float) $item['price']
    ];

    if ((float) $item['screening_amount'] > 0) {
        $display_items[] = [
            'label' => $nameParts . ' Screening',
            'amount' => (float) $item['screening_amount']
        ];
    }
}

function numberToWords(float $number): string
{
    if ($number == 0.0) {
        return 'Zero';
    }

    $num = floor($number);
    $decimal = (int) round(($number - $num) * 100);
    $words = [];
    $lookup = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen',
        17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
        60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    ];

    $convertLessThanHundred = function (int $n) use ($lookup): string {
        if ($n === 0) {
            return '';
        }
        if ($n < 20) {
            return $lookup[$n];
        }
        $tens = (int) (floor($n / 10) * 10);
        $units = $n % 10;
        return $lookup[$tens] . ($units > 0 ? ' ' . $lookup[$units] : '');
    };

    if ($num >= 10000000) {
        $crores = (int) floor($num / 10000000);
        $words[] = $convertLessThanHundred($crores) . ' Crore';
        $num %= 10000000;
    }
    if ($num >= 100000) {
        $lakhs = (int) floor($num / 100000);
        $words[] = $convertLessThanHundred($lakhs) . ' Lakh';
        $num %= 100000;
    }
    if ($num >= 1000) {
        $thousands = (int) floor($num / 1000);
        $words[] = $convertLessThanHundred($thousands) . ' Thousand';
        $num %= 1000;
    }
    if ($num >= 100) {
        $hundreds = (int) floor($num / 100);
        $words[] = $convertLessThanHundred($hundreds) . ' Hundred';
        $num %= 100;
    }
    if ($num > 0) {
        if (!empty($words)) {
            $words[] = 'and';
        }
        $words[] = $convertLessThanHundred((int) $num);
    }

    $rupees = trim(implode(' ', $words));
    $paiseWords = '';
    if ($decimal > 0) {
        $paiseWords = $convertLessThanHundred($decimal) . ' Paise';
    }

    if ($rupees !== '' && $paiseWords !== '') {
        return $rupees . ' Rupees and ' . $paiseWords;
    }
    if ($rupees !== '') {
        return $rupees . ' Rupees';
    }
    if ($paiseWords !== '') {
        return $paiseWords;
    }
    return 'Zero Rupees';
}

function formatCurrency($amount): string
{
    return number_format((float) $amount, 2);
}

$amountInWords = numberToWords((float) $bill['net_amount']);
$formattedDateTime = date('d-m-Y H:i:s', strtotime($bill['created_at']));
$age = trim((string) $bill['age']);
$gender = trim((string) $bill['sex']);
$ageDisplay = $age !== '' ? $age : 'N/A';
$genderDisplay = $gender !== '' ? ucwords(strtolower($gender)) : 'N/A';
$ageGenderDisplay = $ageDisplay . ' / ' . $genderDisplay;
$referralName = trim((string) $bill['referral_doctor_name']);
if ($referralName !== '') {
    $hasSalutation = preg_match('/^dr\b/i', $referralName) === 1;
    $referralDisplay = $hasSalutation ? $referralName : 'Dr. ' . $referralName;
} else {
    $referralDisplay = 'Self';
}
$patientCity = trim((string) $bill['city']);
$patientMobile = trim((string) $bill['mobile_number']);
$minItemRows = 12;
$emptyRows = max(0, $minItemRows - count($display_items));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Bill Receipt - #<?php echo htmlspecialchars($bill['invoice_number']); ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <style>
        :root {
            --default-font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                Ubuntu, 'Helvetica Neue', Helvetica, Arial, sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            margin: 0;
            background: #d6d6d6;
            font-family: var(--default-font-family);
            color: #000;
            -webkit-print-color-adjust: exact;
        }

        .page {
            min-height: 100%;
            display: flex;
            justify-content: center;
            align-items: flex-end;
            padding: 0;
        }

        .bill-sheet {
            position: relative;
            width: 271.57mm;
            height: 191.39mm;
            margin-bottom: 0;
            transform: translateY(-10mm);
            transform-origin: top center;
            background: url('../assets/images/billreceipt.png') no-repeat center top;
            background-size: 100% 100%;
        }

        .bill-sheet span,
        .bill-sheet div,
        .bill-sheet table {
            font-family: var(--default-font-family);
        }

        .bill-receipt-title {
            position: absolute;
            top: calc(13% + 38mm);
            left: 50%;
            transform: translateX(-50%);
            font-size: 19.45px;
            font-weight: 700;
            letter-spacing: 1.04px;
        }

        .field-row {
            position: absolute;
            left: 8%;
            width: 84%;
            display: flex;
            justify-content: space-between;
            font-size: 13.55px;
            line-height: 1.1;
        }

        .field-row.two-column .field-group:last-child {
            justify-content: flex-end;
        }

        .field-row.single {
            justify-content: flex-start;
            width: 55%;
        }

        .field-group {
            display: flex;
            gap: 5.18px;
            font-weight: 700;
            align-items: flex-start;
            max-width: 48%;
        }

        .field-group .field-value {
            font-weight: 500;
            max-width: 336.23px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .field-row.single .field-group {
            max-width: none;
        }

    .row-bill { top: 20%; }
    .row-patient { top: 24%; }
    .row-age { top: 27.5%; }
    .row-ref { top: 31%; }

        .items-container {
            position: absolute;
            top: calc(36.5% + 15mm);
            left: 8.2%;
            width: 83.5%;
            height: 35%;
            padding: 0 5.18px;
        }

        .items-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
            font-size: 12.93px;
            table-layout: fixed;
        }

        .items-table thead {
            display: none;
        }

        .items-table .col-sno {
            width: 11%;
            text-align: center;
            padding-right: 5.18px;
            position: relative;
            left: -10mm;
        }

        .items-table .col-item {
            width: 64%;
            padding-left: 5.18px;
            padding-right: 7.25px;
            position: relative;
            left: -10mm;
        }

        .items-table .col-amount {
            width: 25%;
            text-align: right;
            padding-right: 7.25px;
        }

        .items-table tbody tr {
            height: 20.69px;
        }

        .items-table tbody td {
            padding: 0;
            vertical-align: middle;
            font-weight: 500;
            background: transparent;
        }

        .items-table td.col-item {
            word-break: break-word;
        }

        .amount-in-words {
            position: absolute;
            left: 8%;
            top: calc(74% + 10mm);
            width: 55%;
            font-size: 12.93px;
            font-weight: 700;
        }

        .dispute-text {
            position: absolute;
            left: 8%;
            top: calc(78% + 10mm);
            width: 55%;
            font-size: 11.69px;
            font-weight: 700;
        }

        .address-info {
            position: absolute;
            left: 8%;
            top: calc(82% + 10mm);
            width: 58%;
            font-size: 10.66px;
            line-height: 1.2;
        }

        .totals-container {
            position: absolute;
            right: 8.5%;
            top: calc(77% + 4mm);
            width: 25%;
            font-size: 12.93px;
        }

        .totals-container .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 7.77px;
        }

        .totals-container .total-row .label {
            font-weight: 500;
        }

        .totals-container .total-row .value {
            font-weight: 700;
        }

        .totals-container .grand-total {
            border-top: 1px solid #000;
            padding-top: 7.77px;
            margin-top: 7.77px;
            font-weight: 700;
        }

        .auth-signature {
            position: absolute;
            right: 9%;
            bottom: calc(6% - 5mm);
            font-size: 11.69px;
            font-weight: 700;
        }

        .print-toolbar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            z-index: 100;
        }

        .print-toolbar button {
            padding: 10px 18px;
            font-size: 14px;
            cursor: pointer;
            border: 1px solid #bbb;
            border-radius: 4px;
            background: #ffffff;
        }

        @media print {
            body {
                background: #ffffff;
            }

            .page {
                padding: 0;
            }

            .print-toolbar {
                display: none;
            }

            @page {
                size: 271.57mm 191.39mm;
                margin: 6mm;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="bill-sheet">
            <span class="bill-receipt-title">BILL RECEIPT</span>

            <div class="field-row two-column row-bill">
                <div class="field-group">
                    <span class="field-label">BILL NO</span>
                    <span class="field-colon">:</span>
                    <span class="field-value"><?php echo htmlspecialchars($bill['invoice_number']); ?></span>
                </div>
                <div class="field-group">
                    <span class="field-label">BILL DATE</span>
                    <span class="field-colon">:</span>
                    <span class="field-value"><?php echo htmlspecialchars($formattedDateTime); ?></span>
                </div>
            </div>

            <div class="field-row two-column" style="top: 22%;">
                <div class="field-group">
                    <span class="field-label">UID</span>
                    <span class="field-colon">:</span>
                    <span class="field-value"><?php echo htmlspecialchars($bill['patient_uid']); ?></span>
                </div>
            </div>

            <div class="field-row two-column row-patient">
                <div class="field-group">
                    <span class="field-label">Patient Name</span>
                    <span class="field-colon">:</span>
                    <span class="field-value"><?php echo htmlspecialchars($bill['patient_name']); ?></span>
                </div>
                <div class="field-group">
                    <span class="field-label">Mobile No</span>
                    <span class="field-colon">:</span>
                    <span class="field-value"><?php echo htmlspecialchars($patientMobile !== '' ? $patientMobile : '-'); ?></span>
                </div>
            </div>

            <div class="field-row two-column row-age">
                <div class="field-group">
                    <span class="field-label">Age &amp; Gender</span>
                    <span class="field-colon">:</span>
                    <span class="field-value"><?php echo htmlspecialchars($ageGenderDisplay); ?></span>
                </div>
                <div class="field-group">
                    <span class="field-label">City</span>
                    <span class="field-colon">:</span>
                    <span class="field-value"><?php echo htmlspecialchars($patientCity !== '' ? $patientCity : '-'); ?></span>
                </div>
            </div>

            <div class="field-row single row-ref">
                <div class="field-group">
                    <span class="field-label">Ref. Physician</span>
                    <span class="field-colon">:</span>
                    <span class="field-value"><?php echo htmlspecialchars($referralDisplay); ?></span>
                </div>
            </div>

            <div class="items-container">
                <table class="items-table">
                    <tbody>
                        <?php foreach ($display_items as $index => $entry): ?>
                            <tr>
                                <td class="col-sno"><?php echo $index + 1; ?></td>
                                <td class="col-item"><?php echo htmlspecialchars($entry['label']); ?></td>
                                <td class="col-amount"><?php echo formatCurrency($entry['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php for ($i = 0; $i < $emptyRows; $i++): ?>
                            <tr class="empty">
                                <td class="col-sno">&nbsp;</td>
                                <td class="col-item"></td>
                                <td class="col-amount"></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div class="amount-in-words">(Rs <?php echo htmlspecialchars($amountInWords); ?> Only)</div>

            <div class="dispute-text">All Disputes are subject to Vijayawada Jurisdiction Only. E.&amp;O.E</div>

            <div class="address-info">
                #57-7-3, Kakatheeya Road, Near Sonovision, Patamata, Vijayawada.<br />
                MRI/CT: 63091 02019, Ultrasound: 88836 89689, Reception &amp; X-Ray: 91171 22022,<br />
                Laboratory Services: 83281 81932
            </div>

            <div class="totals-container">
                <div class="total-row">
                    <span class="label">Sub Total</span>
                    <span class="value"><?php echo formatCurrency($bill['gross_amount']); ?></span>
                </div>
                <div class="total-row">
                    <span class="label">Disc Amt</span>
                    <span class="value"><?php echo formatCurrency($bill['discount']); ?></span>
                </div>
                <div class="total-row grand-total">
                    <span class="label">TOTAL</span>
                    <span class="value"><?php echo formatCurrency($bill['net_amount']); ?></span>
                </div>
            </div>

            <span class="auth-signature">Authorised signature</span>
        </div>
    </div>

    <div class="print-toolbar">
        <button type="button" onclick="window.print();">Print Bill</button>
        <button type="button" onclick="window.history.back();">Back</button>
    </div>

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>