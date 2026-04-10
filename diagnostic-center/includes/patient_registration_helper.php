<?php

/**
 * Ensure the patients table supports the patient registration module fields.
 * This is idempotent and safe to run on each request.
 */
function ensure_patient_registration_schema($conn) {
    $required_columns = [
        'patient_unique_id' => "VARCHAR(20) NULL AFTER id",
        'referring_doctor_name' => "VARCHAR(120) NULL AFTER mobile_number",
        'referring_doctor_contact' => "VARCHAR(20) NULL AFTER referring_doctor_name",
        'medical_history' => "TEXT NULL AFTER referring_doctor_contact",
        'emergency_contact_person' => "VARCHAR(120) NULL AFTER medical_history",
        'is_archived' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER emergency_contact_person",
        'archived_at' => "DATETIME NULL AFTER is_archived"
    ];

    $existing_columns = [];
    $result = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $existing_columns[$row['COLUMN_NAME']] = true;
        }
        $result->close();
    }

    foreach ($required_columns as $column_name => $column_definition) {
        if (!isset($existing_columns[$column_name])) {
            $sql = "ALTER TABLE patients ADD COLUMN {$column_name} {$column_definition}";
            if (!$conn->query($sql)) {
                throw new Exception("Could not update patients table ({$column_name}): " . $conn->error);
            }
        }
    }

    $index_exists = false;
    $index_result = $conn->query("SHOW INDEX FROM patients WHERE Key_name = 'uniq_patient_unique_id'");
    if ($index_result) {
        $index_exists = $index_result->num_rows > 0;
        $index_result->close();
    }

    if (!$index_exists) {
        if (!$conn->query("ALTER TABLE patients ADD UNIQUE KEY uniq_patient_unique_id (patient_unique_id)")) {
            throw new Exception("Could not add unique index for patient IDs: " . $conn->error);
        }
    }
}

/**
 * Ensure the patient archive table exists.
 */
function ensure_patient_archive_schema($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS patient_archive (
        archive_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        patient_unique_id VARCHAR(20) NULL,
        name VARCHAR(100) NOT NULL,
        sex ENUM('Male','Female','Other') NOT NULL,
        age INT NOT NULL,
        address TEXT DEFAULT NULL,
        city VARCHAR(100) DEFAULT NULL,
        mobile_number VARCHAR(15) DEFAULT NULL,
        referring_doctor_name VARCHAR(120) DEFAULT NULL,
        referring_doctor_contact VARCHAR(20) DEFAULT NULL,
        medical_history TEXT DEFAULT NULL,
        emergency_contact_person VARCHAR(120) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL,
        archived_reason VARCHAR(255) NOT NULL,
        archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_patient_id (patient_id),
        INDEX idx_patient_unique_id (patient_unique_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

    if (!$conn->query($sql)) {
        throw new Exception("Could not ensure patient archive table: " . $conn->error);
    }
}

/**
 * Archive any patient rows that do not use the required DCYYYYNNNN format.
 */
function archive_invalid_patient_rows($conn, $reason = 'Invalid patient_unique_id format') {
    ensure_patient_registration_schema($conn);
    ensure_patient_archive_schema($conn);

    $invalid_filter = "(patient_unique_id IS NULL OR patient_unique_id = '' OR patient_unique_id NOT REGEXP '^DC[0-9]{8}$')";

    $insert_sql = "INSERT INTO patient_archive (
        patient_id,
        patient_unique_id,
        name,
        sex,
        age,
        address,
        city,
        mobile_number,
        referring_doctor_name,
        referring_doctor_contact,
        medical_history,
        emergency_contact_person,
        created_at,
        archived_reason,
        archived_at
    )
    SELECT
        id,
        patient_unique_id,
        name,
        sex,
        age,
        address,
        city,
        mobile_number,
        referring_doctor_name,
        referring_doctor_contact,
        medical_history,
        emergency_contact_person,
        created_at,
        ?,
        NOW()
    FROM patients
    WHERE is_archived = 0 AND {$invalid_filter}";

    $stmt_insert = $conn->prepare($insert_sql);
    if (!$stmt_insert) {
        throw new Exception('Could not prepare patient archive insert: ' . $conn->error);
    }

    $stmt_insert->bind_param('s', $reason);
    $stmt_insert->execute();
    $inserted_rows = $stmt_insert->affected_rows;
    $stmt_insert->close();

    $update_sql = "UPDATE patients SET is_archived = 1, archived_at = NOW() WHERE is_archived = 0 AND {$invalid_filter}";
    if (!$conn->query($update_sql)) {
        throw new Exception('Could not archive invalid patients: ' . $conn->error);
    }

    return $inserted_rows;
}

/**
 * Generate the next patient unique ID in DCYYYYNNNN format.
 */
function generate_next_patient_unique_id($conn, $year = null) {
    $current_year = $year ?: date('Y');
    $prefix = 'DC' . $current_year;
    $like_prefix = $prefix . '%';

    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(patient_unique_id, 7) AS UNSIGNED)) AS max_seq FROM patients WHERE patient_unique_id LIKE ?");
    if (!$stmt) {
        throw new Exception("Could not prepare patient ID lookup: " . $conn->error);
    }

    $stmt->bind_param("s", $like_prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $max_seq = ($row && isset($row['max_seq'])) ? (int)$row['max_seq'] : 0;
    $next_seq = $max_seq + 1;

    return $prefix . str_pad((string)$next_seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Normalize patient unique ID input for flexible user entry.
 * Examples: "dc20260001" -> "DC20260001", "20260001" -> "DC20260001".
 */
function normalize_patient_unique_id($raw_value) {
    $value = strtoupper(trim((string)$raw_value));
    if ($value === '') {
        return '';
    }

    if (strpos($value, 'DC') === 0) {
        return $value;
    }

    return 'DC' . $value;
}

/**
 * Validate patient ID format: DCYYYYNNNN (example: DC20260001).
 */
function is_valid_patient_unique_id($patient_unique_id) {
    return (bool)preg_match('/^DC\d{8}$/', (string)$patient_unique_id);
}

?>