<?php
$page_title = "Update Payment";
$required_role = "manager";
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

ensure_bill_payment_split_columns($conn);
ensure_payment_history_split_columns($conn);

$error_message = '';
$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

if (!$bill_id) {
    header("Location: view_due_bills.php");
    exit();
}

$stmt = $conn->prepare("SELECT b.*, p.name as patient_name FROM bills b JOIN patients p ON b.patient_id = p.id WHERE b.id = ? AND b.bill_status != 'Void'");
$stmt->bind_param("i", $bill_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header("Location: view_due_bills.php?error=Bill not found.");
    exit();
}
$bill = $result->fetch_assoc();
$stmt->close();

$current_payment_mode = format_payment_mode_display($bill, false);
if (!in_array($current_payment_mode, get_supported_payment_modes(), true)) {
    $current_payment_mode = sanitize_payment_mode_input($bill['payment_mode'] ?? 'Cash');
}

if ($bill['payment_status'] === 'Paid') {
    header("Location: view_due_bills.php?error=This bill is already fully paid.");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn->begin_transaction();
    try {
        $amount_now_paying = isset($_POST['amount_now_paying']) ? (float)$_POST['amount_now_paying'] : 0.0;
        $payment_mode = sanitize_payment_mode_input($_POST['payment_mode'] ?? 'Cash');
        $allowed_payment_modes = get_supported_payment_modes();

        if ($amount_now_paying <= 0) {
            throw new Exception("Amount must be greater than zero.");
        }
        if (!in_array($payment_mode, $allowed_payment_modes, true)) {
            throw new Exception("Please select a valid payment mode.");
        }
        if ($amount_now_paying > (float)$bill['balance_amount']) {
            throw new Exception("Amount cannot be greater than pending balance.");
        }

        $payment_split = build_payment_split_from_input($_POST, $payment_mode, $amount_now_paying);
        $txn_cash_amount = $payment_split['cash_amount'];
        $txn_card_amount = $payment_split['card_amount'];
        $txn_upi_amount = $payment_split['upi_amount'];
        $txn_other_amount = $payment_split['other_amount'];

        $previous_amount_paid = round((float)$bill['amount_paid'], 2);

        $new_amount_paid = round($previous_amount_paid + $amount_now_paying, 2);
        $new_balance_amount = round((float)$bill['net_amount'] - $new_amount_paid, 2);

        $new_cash_amount = round((float)($bill['cash_amount'] ?? 0) + $txn_cash_amount, 2);
        $new_card_amount = round((float)($bill['card_amount'] ?? 0) + $txn_card_amount, 2);
        $new_upi_amount = round((float)($bill['upi_amount'] ?? 0) + $txn_upi_amount, 2);
        $new_other_amount = round((float)($bill['other_amount'] ?? 0) + $txn_other_amount, 2);
        $new_payment_mode = resolve_payment_mode_from_split([
            'cash' => $new_cash_amount,
            'card' => $new_card_amount,
            'upi' => $new_upi_amount,
            'other' => $new_other_amount,
        ], $payment_mode);

        if ($new_amount_paid > ((float)$bill['net_amount'] + 0.0001)) {
            throw new Exception("Amount paid cannot exceed total bill amount.");
        }

        if ($new_balance_amount <= 0.0001) {
            $new_balance_amount = 0.00;
            $new_payment_status = 'Paid';
        } elseif ($new_amount_paid > 0) {
            $new_payment_status = 'Partial Paid';
        } else {
            $new_payment_status = 'Due';
        }

        $update_stmt = $conn->prepare("UPDATE bills SET amount_paid = ?, balance_amount = ?, payment_mode = ?, cash_amount = ?, card_amount = ?, upi_amount = ?, other_amount = ?, payment_status = ? WHERE id = ?");
        $update_stmt->bind_param("ddsddddsi", $new_amount_paid, $new_balance_amount, $new_payment_mode, $new_cash_amount, $new_card_amount, $new_upi_amount, $new_other_amount, $new_payment_status, $bill_id);
        $update_stmt->execute();
        $update_stmt->close();

        $history_stmt = $conn->prepare("INSERT INTO payment_history (bill_id, amount_paid_in_txn, previous_amount_paid, new_total_amount_paid, payment_mode, cash_amount, card_amount, upi_amount, other_amount, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$history_stmt) {
            throw new Exception("Unable to record payment transaction history.");
        }
        $user_id = (int)$_SESSION['user_id'];
        $history_stmt->bind_param("idddsddddi", $bill_id, $amount_now_paying, $previous_amount_paid, $new_amount_paid, $payment_mode, $txn_cash_amount, $txn_card_amount, $txn_upi_amount, $txn_other_amount, $user_id);
        $history_stmt->execute();
        $history_stmt->close();

        $conn->commit();
        log_system_action($conn, 'PAYMENT_UPDATED', $bill_id, "Manager updated payment for Bill #{$bill_id}. New status: {$new_payment_status}.");
        header("Location: view_due_bills.php?success=Payment for Bill #{$bill_id} updated successfully.");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Update failed: " . $e->getMessage();
    }
}

require_once '../includes/header.php';
?>

<div class="form-container">
    <h1>Update Payment for Bill #<?php echo $bill_id; ?></h1>
    <p><strong>Patient:</strong> <?php echo htmlspecialchars($bill['patient_name']); ?></p>

    <?php if ($error_message): ?><div class="error-banner"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <div class="payment-summary">
        <div><strong>Total Bill Amount:</strong><span>₹ <?php echo number_format($bill['net_amount'], 2); ?></span></div>
        <div><strong>Paid Till Now:</strong><span>₹ <?php echo number_format($bill['amount_paid'], 2); ?></span></div>
        <div class="balance"><strong>Pending Balance:</strong><span id="pending-balance-display">₹ <?php echo number_format($bill['balance_amount'], 2); ?></span></div>
        <div><strong>Current Payment Mode:</strong><span><?php echo htmlspecialchars(format_payment_mode_display($bill)); ?></span></div>
    </div>

    <form action="update_payment_manager.php?bill_id=<?php echo $bill_id; ?>" method="POST" id="update-payment-form">
        <fieldset>
            <legend>Update Payment</legend>
            <div class="form-group">
                <label for="amount_now_paying">Additional Payment Amount</label>
                <input type="number" name="amount_now_paying" id="amount_now_paying" step="0.01" min="0.01" max="<?php echo htmlspecialchars($bill['balance_amount']); ?>" required>
            </div>
            <div class="form-group">
                <label for="payment_mode">Payment Mode</label>
                <select id="payment_mode" name="payment_mode" required>
                    <option value="Cash" <?php echo $current_payment_mode === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="Card" <?php echo $current_payment_mode === 'Card' ? 'selected' : ''; ?>>Card</option>
                    <option value="UPI" <?php echo $current_payment_mode === 'UPI' ? 'selected' : ''; ?>>UPI</option>
                    <option value="Cash + Card" <?php echo $current_payment_mode === 'Cash + Card' ? 'selected' : ''; ?>>Cash + Card</option>
                    <option value="UPI + Cash" <?php echo $current_payment_mode === 'UPI + Cash' ? 'selected' : ''; ?>>UPI + Cash</option>
                    <option value="Card + UPI" <?php echo $current_payment_mode === 'Card + UPI' ? 'selected' : ''; ?>>Card + UPI</option>
                </select>
            </div>
            <div class="form-row split-payment-details" id="split-payment-details" style="display: none;">
                <div class="form-group" id="split-cash-group" style="display: none;">
                    <label for="split_cash_amount">Cash Amount</label>
                    <input type="number" id="split_cash_amount" name="split_cash_amount" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                </div>
                <div class="form-group" id="split-card-group" style="display: none;">
                    <label for="split_card_amount">Card Amount</label>
                    <input type="number" id="split_card_amount" name="split_card_amount" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                </div>
                <div class="form-group" id="split-upi-group" style="display: none;">
                    <label for="split_upi_amount">UPI Amount</label>
                    <input type="number" id="split_upi_amount" name="split_upi_amount" step="0.01" min="0" inputmode="decimal" placeholder="0.00">
                </div>
            </div>
            <div class="split-payment-note" id="split-payment-note" style="display: none;">
                Split Total: <strong id="split-total-display">₹0.00</strong>
            </div>
        </fieldset>

        <button type="submit" class="btn-submit">Save Payment Update</button>
        <a href="view_due_bills.php" class="btn-cancel">Cancel</a>
    </form>
</div>

<script>
(function() {
    var form = document.getElementById('update-payment-form');
    var amountInput = document.getElementById('amount_now_paying');
    var pendingDisplay = document.getElementById('pending-balance-display');
    var paymentModeSelect = document.getElementById('payment_mode');
    var splitPaymentDetails = document.getElementById('split-payment-details');
    var splitPaymentNote = document.getElementById('split-payment-note');
    var splitTotalDisplay = document.getElementById('split-total-display');
    var splitCashGroup = document.getElementById('split-cash-group');
    var splitCardGroup = document.getElementById('split-card-group');
    var splitUpiGroup = document.getElementById('split-upi-group');
    var splitCashInput = document.getElementById('split_cash_amount');
    var splitCardInput = document.getElementById('split_card_amount');
    var splitUpiInput = document.getElementById('split_upi_amount');
    var originalPending = <?php echo json_encode((float)$bill['balance_amount']); ?>;

    if (!amountInput || !pendingDisplay || !paymentModeSelect) {
        return;
    }

    function parseMoney(value) {
        var parsed = parseFloat(value);
        if (!isFinite(parsed) || parsed < 0) {
            return 0;
        }
        return parsed;
    }

    function formatInr(value) {
        return value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function isCombinedMode(mode) {
        return mode === 'Cash + Card' || mode === 'UPI + Cash' || mode === 'Card + UPI';
    }

    var splitFieldMap = {
        cash: { group: splitCashGroup, input: splitCashInput, label: 'Cash Amount' },
        card: { group: splitCardGroup, input: splitCardInput, label: 'Card Amount' },
        upi: { group: splitUpiGroup, input: splitUpiInput, label: 'UPI Amount' }
    };

    function getSplitModeConfig(mode) {
        if (mode === 'Cash + Card') {
            return { keys: ['cash', 'card'] };
        }
        if (mode === 'UPI + Cash') {
            return { keys: ['upi', 'cash'] };
        }
        if (mode === 'Card + UPI') {
            return { keys: ['card', 'upi'] };
        }
        return null;
    }

    function getSplitModeFields(mode) {
        var config = getSplitModeConfig(mode);
        if (!config) {
            return null;
        }

        var first = splitFieldMap[config.keys[0]];
        var second = splitFieldMap[config.keys[1]];
        if (!first || !second || !first.input || !second.input) {
            return null;
        }

        return {
            keys: config.keys,
            first: first,
            second: second
        };
    }

    function getExpectedAmount() {
        var entered = parseMoney(amountInput.value);
        if (entered > originalPending) {
            entered = originalPending;
        }
        return entered;
    }

    function resetSplitInput(inputEl) {
        if (!inputEl) return;
        inputEl.required = false;
        inputEl.value = '';
        inputEl.setCustomValidity('');
        inputEl.removeAttribute('max');
    }

    function toggleSplitField(groupEl, inputEl, shouldShow, expectedAmount) {
        if (!groupEl || !inputEl) return;
        groupEl.style.display = shouldShow ? 'block' : 'none';
        inputEl.required = shouldShow;
        inputEl.setCustomValidity('');
        if (shouldShow) {
            inputEl.max = expectedAmount.toFixed(2);
        } else {
            inputEl.value = '';
            inputEl.removeAttribute('max');
        }
    }

    function updateSplitTotals() {
        if (!splitTotalDisplay) {
            return;
        }
        var total = parseMoney(splitCashInput ? splitCashInput.value : 0)
            + parseMoney(splitCardInput ? splitCardInput.value : 0)
            + parseMoney(splitUpiInput ? splitUpiInput.value : 0);
        var expected = getExpectedAmount();
        splitTotalDisplay.textContent = '₹' + total.toFixed(2) + ' (Required: ₹' + expected.toFixed(2) + ')';
    }

    function autoBalanceSplitFields(changedKey, showValidation) {
        var modeFields = getSplitModeFields(paymentModeSelect ? paymentModeSelect.value : '');
        if (!modeFields || modeFields.keys.indexOf(changedKey) === -1) {
            updateSplitTotals();
            return;
        }

        var expectedAmount = getExpectedAmount();
        if (expectedAmount <= 0.0001) {
            updateSplitTotals();
            return;
        }

        var sourceField = splitFieldMap[changedKey];
        var companionKey = modeFields.keys[0] === changedKey ? modeFields.keys[1] : modeFields.keys[0];
        var companionField = splitFieldMap[companionKey];
        if (!sourceField || !companionField || !sourceField.input || !companionField.input) {
            updateSplitTotals();
            return;
        }

        var raw = sourceField.input.value.trim();
        sourceField.input.setCustomValidity('');

        if (raw === '') {
            companionField.input.value = '';
            updateSplitTotals();
            return;
        }

        var entered = parseFloat(raw);
        if (!isFinite(entered) || entered < 0) {
            sourceField.input.setCustomValidity(sourceField.label + ' must be a valid non-negative amount.');
            if (showValidation) {
                sourceField.input.reportValidity();
            }
            companionField.input.value = '';
            updateSplitTotals();
            return;
        }

        if (entered > expectedAmount + 0.01) {
            sourceField.input.setCustomValidity(sourceField.label + ' cannot exceed the required payable amount.');
            if (showValidation) {
                sourceField.input.reportValidity();
            }
            companionField.input.value = '';
            updateSplitTotals();
            return;
        }

        var remaining = Math.max(expectedAmount - entered, 0);
        companionField.input.setCustomValidity('');
        companionField.input.value = remaining.toFixed(2);
        updateSplitTotals();
    }

    function syncSplitFields() {
        if (!splitPaymentDetails) {
            return;
        }

        var mode = paymentModeSelect.value;
        var expectedAmount = getExpectedAmount();
        var modeFields = getSplitModeFields(mode);
        var shouldShowCombined = !!modeFields && expectedAmount > 0.0001;

        if (!shouldShowCombined) {
            splitPaymentDetails.style.display = 'none';
            if (splitPaymentNote) {
                splitPaymentNote.style.display = 'none';
            }
            Object.keys(splitFieldMap).forEach(function(key) {
                var field = splitFieldMap[key];
                resetSplitInput(field.input);
                if (field.group) {
                    field.group.style.display = 'none';
                }
            });
            updateSplitTotals();
            return;
        }

        splitPaymentDetails.style.display = 'flex';
        if (splitPaymentNote) {
            splitPaymentNote.style.display = 'block';
        }

        var orderedKeys = modeFields.keys;
        Object.keys(splitFieldMap).forEach(function(key) {
            var field = splitFieldMap[key];
            var shouldShow = orderedKeys.indexOf(key) !== -1;
            toggleSplitField(field.group, field.input, shouldShow, expectedAmount);
            if (shouldShow && field.group) {
                field.group.style.order = String(orderedKeys.indexOf(key) + 1);
            }
        });

        var firstKey = orderedKeys[0];
        var secondKey = orderedKeys[1];
        var firstInput = splitFieldMap[firstKey] ? splitFieldMap[firstKey].input : null;
        var secondInput = splitFieldMap[secondKey] ? splitFieldMap[secondKey].input : null;
        var active = document.activeElement;

        if (active === firstInput) {
            autoBalanceSplitFields(firstKey, false);
        } else if (active === secondInput) {
            autoBalanceSplitFields(secondKey, false);
        } else if (firstInput && firstInput.value.trim() !== '') {
            autoBalanceSplitFields(firstKey, false);
        } else if (secondInput && secondInput.value.trim() !== '') {
            autoBalanceSplitFields(secondKey, false);
        } else {
            updateSplitTotals();
        }
    }

    function validateSplit() {
        if (!isCombinedMode(paymentModeSelect.value)) {
            return true;
        }

        var expectedAmount = getExpectedAmount();
        if (expectedAmount <= 0.0001) {
            return true;
        }

        var modeFields = getSplitModeFields(paymentModeSelect.value);
        if (!modeFields) {
            return true;
        }

        var firstInput = modeFields.first.input;
        var secondInput = modeFields.second.input;
        var firstLabel = modeFields.first.label;
        var secondLabel = modeFields.second.label;

        if (!firstInput || !secondInput) {
            return true;
        }

        firstInput.setCustomValidity('');
        secondInput.setCustomValidity('');

        if (firstInput.value.trim() === '' || secondInput.value.trim() === '') {
            secondInput.setCustomValidity('Enter both ' + firstLabel + ' and ' + secondLabel + ' split amounts.');
            secondInput.reportValidity();
            return false;
        }

        var firstVal = parseFloat(firstInput.value);
        var secondVal = parseFloat(secondInput.value);
        if (!isFinite(firstVal) || !isFinite(secondVal) || firstVal < 0 || secondVal < 0) {
            secondInput.setCustomValidity('Split amounts must be valid non-negative numbers.');
            secondInput.reportValidity();
            return false;
        }

        if (firstVal <= 0 || secondVal <= 0) {
            secondInput.setCustomValidity(firstLabel + ' and ' + secondLabel + ' must be greater than zero.');
            secondInput.reportValidity();
            return false;
        }

        if (firstVal > expectedAmount + 0.01 || secondVal > expectedAmount + 0.01) {
            secondInput.setCustomValidity('Split amount in a single field cannot exceed the required payable amount.');
            secondInput.reportValidity();
            return false;
        }

        var total = firstVal + secondVal;
        if (total > expectedAmount + 0.01) {
            secondInput.setCustomValidity('Split total cannot exceed the pending amount.');
            secondInput.reportValidity();
            return false;
        }

        if (Math.abs(total - expectedAmount) > 0.01) {
            secondInput.setCustomValidity('Split total must exactly match the payment amount.');
            secondInput.reportValidity();
            return false;
        }

        return true;
    }

    function refreshPendingBalance() {
        var entered = parseMoney(amountInput.value);

        if (entered > originalPending) {
            amountInput.setCustomValidity('Amount cannot be greater than pending balance.');
        } else {
            amountInput.setCustomValidity('');
        }

        var livePending = originalPending - entered;
        if (livePending < 0) {
            livePending = 0;
        }

        pendingDisplay.textContent = '₹ ' + formatInr(livePending);
        syncSplitFields();
    }

    amountInput.addEventListener('input', refreshPendingBalance);
    amountInput.addEventListener('change', refreshPendingBalance);
    paymentModeSelect.addEventListener('change', syncSplitFields);
    Object.keys(splitFieldMap).forEach(function(key) {
        var field = splitFieldMap[key];
        if (!field || !field.input) return;
        field.input.addEventListener('input', function() {
            field.input.setCustomValidity('');
            autoBalanceSplitFields(key, true);
        });
        field.input.addEventListener('change', function() {
            autoBalanceSplitFields(key, true);
        });
    });
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!validateSplit()) {
                e.preventDefault();
            }
        });
    }
    refreshPendingBalance();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
