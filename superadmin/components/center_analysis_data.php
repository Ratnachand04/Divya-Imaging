<?php

if (!function_exists('sa_table_exists')) {
    function sa_table_exists(mysqli $conn, string $tableName): bool
    {
        $safe = $conn->real_escape_string($tableName);
        $result = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $result && $result->num_rows > 0;
    }
}

if (!function_exists('sa_sum_values')) {
    function sa_sum_values(array $values): float
    {
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += (float)$value;
        }
        return $sum;
    }
}

if (!function_exists('sa_build_period_payload')) {
    function sa_build_period_payload(mysqli $conn, string $mode, array $labels, array $keys, string $from, string $to): array
    {
        $revenue = array_fill(0, count($labels), 0.0);
        $expenses = array_fill(0, count($labels), 0.0);
        $discounts = array_fill(0, count($labels), 0.0);
        $tests = array_fill(0, count($labels), 0);
        $patients = array_fill(0, count($labels), 0);
        $bills = array_fill(0, count($labels), 0);
        $employees = array_fill(0, count($labels), 0);
        $notifications = array_fill(0, count($labels), 0);

        $indexByKey = [];
        foreach ($keys as $i => $k) {
            $indexByKey[$k] = $i;
        }

        $periodExprBills = "DATE(created_at)";
        $periodExprExpenses = "DATE(created_at)";
        $periodExprTests = "DATE(b.created_at)";
        $periodExprUsers = "DATE(created_at)";
        $periodExprNotif = "DATE(created_at)";

        if ($mode === 'daily') {
            $periodExprBills = "HOUR(created_at)";
            $periodExprExpenses = "HOUR(created_at)";
            $periodExprTests = "HOUR(b.created_at)";
            $periodExprUsers = "HOUR(created_at)";
            $periodExprNotif = "HOUR(created_at)";
        } elseif ($mode === 'monthly') {
            $periodExprBills = "DATE_FORMAT(created_at, '%Y-%m')";
            $periodExprExpenses = "DATE_FORMAT(created_at, '%Y-%m')";
            $periodExprTests = "DATE(b.created_at)";
            $periodExprUsers = "DATE(created_at)";
            $periodExprNotif = "DATE(created_at)";
        } elseif ($mode === 'yearly') {
            $periodExprBills = "DATE_FORMAT(created_at, '%Y-%m')";
            $periodExprExpenses = "DATE_FORMAT(created_at, '%Y-%m')";
            $periodExprTests = "DATE_FORMAT(b.created_at, '%Y-%m')";
            $periodExprUsers = "DATE_FORMAT(created_at, '%Y-%m')";
            $periodExprNotif = "DATE_FORMAT(created_at, '%Y-%m')";
        }

        $sqlBills = "
            SELECT
                {$periodExprBills} AS period_key,
                COALESCE(SUM(net_amount), 0) AS revenue,
                COALESCE(SUM(discount), 0) AS discount_total,
                COUNT(*) AS bill_count,
                COUNT(DISTINCT patient_id) AS patient_count
            FROM bills
            WHERE bill_status != 'Void'
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY period_key
            ORDER BY period_key ASC
        ";
        $stmt = $conn->prepare($sqlBills);
        if ($stmt) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $k = (string)$row['period_key'];
                if (!array_key_exists($k, $indexByKey)) {
                    continue;
                }
                $i = $indexByKey[$k];
                $revenue[$i] = (float)$row['revenue'];
                $discounts[$i] = (float)$row['discount_total'];
                $bills[$i] = (int)$row['bill_count'];
                $patients[$i] = (int)$row['patient_count'];
            }
            $stmt->close();
        }

        if (sa_table_exists($conn, 'expenses')) {
            $sqlExpenses = "
                SELECT
                    {$periodExprExpenses} AS period_key,
                    COALESCE(SUM(amount), 0) AS total_expense
                FROM expenses
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY period_key
                ORDER BY period_key ASC
            ";
            $stmt = $conn->prepare($sqlExpenses);
            if ($stmt) {
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $k = (string)$row['period_key'];
                    if (!array_key_exists($k, $indexByKey)) {
                        continue;
                    }
                    $expenses[$indexByKey[$k]] = (float)$row['total_expense'];
                }
                $stmt->close();
            }
        }

        $sqlTests = "
            SELECT
                {$periodExprTests} AS period_key,
                COUNT(bi.id) AS test_count
            FROM bill_items bi
            JOIN bills b ON b.id = bi.bill_id
            WHERE bi.item_status = 0
              AND b.bill_status != 'Void'
              AND DATE(b.created_at) BETWEEN ? AND ?
            GROUP BY period_key
            ORDER BY period_key ASC
        ";
        $stmt = $conn->prepare($sqlTests);
        if ($stmt) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $k = (string)$row['period_key'];
                if (!array_key_exists($k, $indexByKey)) {
                    continue;
                }
                $tests[$indexByKey[$k]] = (int)$row['test_count'];
            }
            $stmt->close();
        }

        $sqlUsers = "
            SELECT
                {$periodExprUsers} AS period_key,
                COUNT(*) AS employee_count
            FROM users
            WHERE role NOT IN ('superadmin', 'platform_admin', 'developer')
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY period_key
            ORDER BY period_key ASC
        ";
        $stmt = $conn->prepare($sqlUsers);
        if ($stmt) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $k = (string)$row['period_key'];
                if (!array_key_exists($k, $indexByKey)) {
                    continue;
                }
                $employees[$indexByKey[$k]] = (int)$row['employee_count'];
            }
            $stmt->close();
        }

        if (sa_table_exists($conn, 'notification_queue')) {
            $sqlNotif = "
                SELECT
                    {$periodExprNotif} AS period_key,
                    COUNT(*) AS notification_count
                FROM notification_queue
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY period_key
                ORDER BY period_key ASC
            ";
            $stmt = $conn->prepare($sqlNotif);
            if ($stmt) {
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $k = (string)$row['period_key'];
                    if (!array_key_exists($k, $indexByKey)) {
                        continue;
                    }
                    $notifications[$indexByKey[$k]] = (int)$row['notification_count'];
                }
                $stmt->close();
            }
        }

        $roleLabels = [];
        $roleValues = [];
        $sqlRoles = "
            SELECT role, COUNT(*) AS role_count
            FROM users
            WHERE role NOT IN ('superadmin', 'platform_admin', 'developer')
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY role
            ORDER BY role_count DESC, role ASC
        ";
        $stmt = $conn->prepare($sqlRoles);
        if ($stmt) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $roleLabels[] = ucfirst((string)$row['role']);
                $roleValues[] = (int)$row['role_count'];
            }
            $stmt->close();
        }

        $totalRevenue = sa_sum_values($revenue);
        $totalExpenses = sa_sum_values($expenses);
        $totalDiscounts = sa_sum_values($discounts);
        $totalTests = (int)array_sum($tests);

        $testLabels = [];
        $testRevenue = [];
        $testDiscounts = [];
        $testCounts = [];

        $sqlGrowthByTest = "
            SELECT
                COALESCE(NULLIF(t.sub_test_name, ''), NULLIF(t.main_test_name, ''), CONCAT('Test #', bi.test_id)) AS test_name,
                COUNT(bi.id) AS test_count,
                COALESCE(SUM(COALESCE(t.price, 0)), 0) AS gross_revenue,
                COALESCE(SUM(COALESCE(bi.discount_amount, 0)), 0) AS discount_total
            FROM bill_items bi
            JOIN bills b ON b.id = bi.bill_id
            LEFT JOIN tests t ON t.id = bi.test_id
            WHERE bi.item_status = 0
              AND b.bill_status != 'Void'
              AND DATE(b.created_at) BETWEEN ? AND ?
            GROUP BY bi.test_id, test_name
            HAVING COUNT(bi.id) > 0
            ORDER BY gross_revenue DESC, test_count DESC
            LIMIT 12
        ";
        $stmt = $conn->prepare($sqlGrowthByTest);
        if ($stmt) {
            $stmt->bind_param('ss', $from, $to);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $testLabels[] = (string)$row['test_name'];
                $testRevenue[] = (float)$row['gross_revenue'];
                $testDiscounts[] = (float)$row['discount_total'];
                $testCounts[] = (int)$row['test_count'];
            }
            $stmt->close();
        }

        $expenseTypes = [];
        $expenseDetailsByType = [];

        if (sa_table_exists($conn, 'expenses')) {
            $sqlExpenseTypes = "
                SELECT
                    expense_type,
                    COALESCE(SUM(amount), 0) AS total_amount,
                    COUNT(*) AS item_count
                FROM expenses
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY expense_type
                ORDER BY total_amount DESC, expense_type ASC
            ";
            $stmt = $conn->prepare($sqlExpenseTypes);
            if ($stmt) {
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $expenseType = trim((string)$row['expense_type']);
                    if ($expenseType === '') {
                        $expenseType = 'Uncategorized';
                    }
                    $expenseTypes[] = [
                        'type' => $expenseType,
                        'total' => (float)$row['total_amount'],
                        'count' => (int)$row['item_count']
                    ];
                }
                $stmt->close();
            }

            $sqlExpenseDetails = "
                SELECT
                    e.expense_type,
                    e.amount,
                    e.status,
                    e.proof_path,
                    e.created_at,
                    COALESCE(NULLIF(u.username, ''), '-') AS accountant_name
                FROM expenses e
                LEFT JOIN users u ON u.id = e.accountant_id
                WHERE DATE(e.created_at) BETWEEN ? AND ?
                ORDER BY e.expense_type ASC, e.created_at DESC
            ";
            $stmt = $conn->prepare($sqlExpenseDetails);
            if ($stmt) {
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $expenseType = trim((string)$row['expense_type']);
                    if ($expenseType === '') {
                        $expenseType = 'Uncategorized';
                    }
                    if (!isset($expenseDetailsByType[$expenseType])) {
                        $expenseDetailsByType[$expenseType] = [];
                    }
                    $expenseDetailsByType[$expenseType][] = [
                        'date' => (string)$row['created_at'],
                        'amount' => (float)$row['amount'],
                        'status' => (string)$row['status'],
                        'accountant' => (string)$row['accountant_name'],
                        'proof_path' => (string)($row['proof_path'] ?? '')
                    ];
                }
                $stmt->close();
            }
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'discounts' => $discounts,
            'tests' => $tests,
            'patients' => $patients,
            'bills' => $bills,
            'employees' => $employees,
            'notifications' => $notifications,
            'roleLabels' => $roleLabels,
            'roleValues' => $roleValues,
            'testLabels' => $testLabels,
            'testRevenue' => $testRevenue,
            'testDiscounts' => $testDiscounts,
            'testCounts' => $testCounts,
            'expenseTypes' => $expenseTypes,
            'expenseDetailsByType' => $expenseDetailsByType,
            'kpis' => [
                'revenue' => $totalRevenue,
                'expenses' => $totalExpenses,
                'discounts' => $totalDiscounts,
                'net' => $totalRevenue - $totalExpenses,
                'tests' => $totalTests,
                'revenue_per_test' => $totalTests > 0 ? ($totalRevenue / $totalTests) : 0,
                'patients' => (int)array_sum($patients),
                'bills' => (int)array_sum($bills),
                'employees' => (int)array_sum($employees),
                'notifications' => (int)array_sum($notifications)
            ],
            'from' => $from,
            'to' => $to
        ];
    }
}

if (!function_exists('sa_daily_payload')) {
    function sa_daily_payload(mysqli $conn, string $day): array
    {
        $labels = [];
        $keys = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $labels[] = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00';
            $keys[] = (string)$hour;
        }
        return sa_build_period_payload($conn, 'daily', $labels, $keys, $day, $day);
    }
}

if (!function_exists('sa_monthly_payload')) {
    function sa_monthly_payload(mysqli $conn, string $month): array
    {
        $start = DateTime::createFromFormat('Y-m', $month);
        if (!$start) {
            $start = new DateTime('first day of this month');
        }
        $start->modify('first day of this month');
        $end = clone $start;
        $end->modify('last day of this month');

        $labels = [];
        $keys = [];
        $cursor = clone $start;
        while ($cursor <= $end) {
            $labels[] = $cursor->format('d M');
            $keys[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }

        return sa_build_period_payload($conn, 'monthly', $labels, $keys, $start->format('Y-m-d'), $end->format('Y-m-d'));
    }
}

if (!function_exists('sa_yearly_payload')) {
    function sa_yearly_payload(mysqli $conn, string $year): array
    {
        $labels = [];
        $keys = [];
        for ($m = 1; $m <= 12; $m++) {
            $labels[] = DateTime::createFromFormat('!m', (string)$m)->format('M');
            $keys[] = $year . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
        }
        return sa_build_period_payload($conn, 'yearly', $labels, $keys, $year . '-01-01', $year . '-12-31');
    }
}
