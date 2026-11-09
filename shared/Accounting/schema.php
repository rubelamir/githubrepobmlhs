<?php

declare(strict_types=1);

/**
 * Versioned DDL for the accounting module (acc_* tables).
 * Each step is idempotent and recorded in schema_migrations.
 */

const ACC_SCHEMA_VERSION = 2;

function accEnsureSchema(PDO $db): void
{
    static $completed = false;
    if ($completed) {
        return;
    }
    $completed = true;

    if (schemaMigrationApplied($db, 'acc_schema_v' . ACC_SCHEMA_VERSION)) {
        return;
    }

    for ($version = 1; $version <= ACC_SCHEMA_VERSION; $version++) {
        $name = 'acc_schema_v' . $version;
        if (schemaMigrationApplied($db, $name)) {
            continue;
        }
        ('accMigrationV' . $version)($db);
        recordSchemaMigration($db, $name);
    }
}

/** Phase 1: accounts officer appointments and the before/after audit trail. */
function accMigrationV1(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_officers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            active_teacher_id INT NULL DEFAULT NULL,
            appointed_by INT NOT NULL,
            appointed_at DATETIME NOT NULL,
            appointment_note VARCHAR(255) NOT NULL DEFAULT '',
            revoked_by INT NULL DEFAULT NULL,
            revoked_at DATETIME NULL DEFAULT NULL,
            revoke_reason VARCHAR(255) NULL DEFAULT NULL,
            UNIQUE KEY uniq_acc_officer_active (active_teacher_id),
            KEY idx_acc_officer_teacher (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id INT NULL DEFAULT NULL,
            actor_name VARCHAR(120) NOT NULL DEFAULT '',
            actor_role VARCHAR(40) NOT NULL DEFAULT '',
            action VARCHAR(60) NOT NULL,
            entity_type VARCHAR(60) NOT NULL,
            entity_id BIGINT NULL DEFAULT NULL,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            note VARCHAR(255) NULL DEFAULT NULL,
            ip_address VARCHAR(45) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_acc_audit_entity (entity_type, entity_id),
            KEY idx_acc_audit_actor (actor_id, created_at),
            KEY idx_acc_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Phase 2: fee heads, fee categories (general / stipend / scholarship) with
 * separate rates, student category assignments, concessions, invoices and waivers.
 */
function accMigrationV2(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_sequences (
            seq_key VARCHAR(20) NOT NULL,
            period VARCHAR(10) NOT NULL,
            next_no INT UNSIGNED NOT NULL,
            PRIMARY KEY (seq_key, period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_fee_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL,
            name_bn VARCHAR(100) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            requires_reference TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_acc_fee_category_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_fee_heads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL,
            name_bn VARCHAR(120) NOT NULL,
            billing_kind VARCHAR(20) NOT NULL,
            late_fine_allowed TINYINT(1) NOT NULL DEFAULT 1,
            income_account_id INT UNSIGNED NULL DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_acc_fee_head_code (code),
            KEY idx_acc_fee_head_kind (billing_kind, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_fee_structures (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fee_head_id INT UNSIGNED NOT NULL,
            session_year VARCHAR(20) NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL DEFAULT 0,
            fee_category_id INT UNSIGNED NOT NULL,
            effective_from DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            due_day TINYINT UNSIGNED NOT NULL DEFAULT 10,
            late_fine_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            grace_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_acc_fee_structure (fee_head_id, session_year, class_id, section_id, fee_category_id, effective_from),
            KEY idx_acc_fee_structure_class (session_year, class_id, section_id),
            CONSTRAINT fk_acc_fs_head FOREIGN KEY (fee_head_id) REFERENCES acc_fee_heads (id),
            CONSTRAINT fk_acc_fs_category FOREIGN KEY (fee_category_id) REFERENCES acc_fee_categories (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_student_fee_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL,
            fee_category_id INT UNSIGNED NOT NULL,
            reference_no VARCHAR(100) NOT NULL DEFAULT '',
            effective_from DATE NOT NULL,
            effective_to DATE NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            requested_by INT NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            decision_note VARCHAR(255) NULL DEFAULT NULL,
            KEY idx_acc_sfc_student (student_id, session_year, status),
            KEY idx_acc_sfc_status (status, requested_at),
            CONSTRAINT fk_acc_sfc_category FOREIGN KEY (fee_category_id) REFERENCES acc_fee_categories (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_concessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL,
            fee_head_id INT UNSIGNED NOT NULL,
            kind VARCHAR(10) NOT NULL,
            value DECIMAL(14,2) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            effective_from DATE NOT NULL,
            effective_to DATE NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            requested_by INT NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            decision_note VARCHAR(255) NULL DEFAULT NULL,
            KEY idx_acc_concession_student (student_id, session_year, fee_head_id, status),
            KEY idx_acc_concession_status (status, requested_at),
            CONSTRAINT fk_acc_concession_head FOREIGN KEY (fee_head_id) REFERENCES acc_fee_heads (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(30) NOT NULL,
            student_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL DEFAULT 0,
            roll_snapshot VARCHAR(20) NOT NULL DEFAULT '',
            name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
            billing_key VARCHAR(40) NOT NULL,
            billing_label VARCHAR(120) NOT NULL,
            issue_date DATE NOT NULL,
            due_date DATE NOT NULL,
            standard_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            category_concession DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            gross DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            fine DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            waived DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            net DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(20) NOT NULL,
            generated_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            cancelled_by INT NULL DEFAULT NULL,
            cancelled_at DATETIME NULL DEFAULT NULL,
            cancel_reason VARCHAR(255) NULL DEFAULT NULL,
            UNIQUE KEY uniq_acc_invoice_no (invoice_no),
            KEY idx_acc_invoice_student (student_id, session_year),
            KEY idx_acc_invoice_class (session_year, class_id, section_id, billing_key),
            KEY idx_acc_invoice_status (status, due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // uniq_key is "student:head:billing_key:line_kind" while the line is live and NULL once
    // its invoice is cancelled, so one fee can never be billed twice yet can be re-billed.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_invoice_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            student_id INT NOT NULL,
            billing_key VARCHAR(40) NOT NULL,
            fee_head_id INT UNSIGNED NOT NULL,
            line_kind VARCHAR(12) NOT NULL,
            uniq_key VARCHAR(120) NULL DEFAULT NULL,
            description VARCHAR(160) NOT NULL DEFAULT '',
            fee_category_id INT UNSIGNED NULL DEFAULT NULL,
            standard_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            category_concession DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            concession_id INT UNSIGNED NULL DEFAULT NULL,
            waived DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            net DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            due_date DATE NOT NULL,
            late_fine_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            grace_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            parent_item_id INT UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_acc_invoice_item (uniq_key),
            KEY idx_acc_item_invoice (invoice_id),
            KEY idx_acc_item_student (student_id, billing_key),
            CONSTRAINT fk_acc_item_invoice FOREIGN KEY (invoice_id) REFERENCES acc_invoices (id),
            CONSTRAINT fk_acc_item_head FOREIGN KEY (fee_head_id) REFERENCES acc_fee_heads (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_waivers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_item_id INT UNSIGNED NOT NULL,
            invoice_id INT UNSIGNED NOT NULL,
            student_id INT NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            requested_by INT NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            decision_note VARCHAR(255) NULL DEFAULT NULL,
            KEY idx_acc_waiver_status (status, requested_at),
            KEY idx_acc_waiver_item (invoice_item_id),
            CONSTRAINT fk_acc_waiver_item FOREIGN KEY (invoice_item_id) REFERENCES acc_invoice_items (id),
            CONSTRAINT fk_acc_waiver_invoice FOREIGN KEY (invoice_id) REFERENCES acc_invoices (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $category = $db->prepare(
        'INSERT IGNORE INTO acc_fee_categories (code, name_bn, description, is_default, requires_reference, sort_order, is_active, created_at, updated_at)
         VALUES (:code, :name, :description, :is_default, :requires_reference, :sort_order, 1, :now, :now2)'
    );
    foreach ([
        ['general', 'সাধারণ', 'বৃত্তি/উপবৃত্তি নেই এমন শিক্ষার্থী', 1, 0, 10],
        ['stipend', 'উপবৃত্তিপ্রাপ্ত', 'সরকারি উপবৃত্তিপ্রাপ্ত শিক্ষার্থী', 0, 1, 20],
        ['scholarship', 'বৃত্তিপ্রাপ্ত', 'বৃত্তিপ্রাপ্ত শিক্ষার্থী', 0, 1, 30],
    ] as [$code, $name, $description, $isDefault, $requiresReference, $sort]) {
        $category->execute([
            'code' => $code, 'name' => $name, 'description' => $description, 'is_default' => $isDefault,
            'requires_reference' => $requiresReference, 'sort_order' => $sort, 'now' => $now, 'now2' => $now,
        ]);
    }

    $head = $db->prepare(
        'INSERT IGNORE INTO acc_fee_heads (code, name_bn, billing_kind, late_fine_allowed, sort_order, is_active, created_at, updated_at)
         VALUES (:code, :name, :kind, :late_fine, :sort_order, 1, :now, :now2)'
    );
    foreach ([
        ['TUITION', 'মাসিক বেতন', 'monthly', 1, 10],
        ['ADMISSION', 'ভর্তি ফি', 'session', 0, 20],
        ['SESSION', 'সেশন ফি', 'session', 0, 30],
        ['EXAM', 'পরীক্ষার ফি', 'exam', 0, 40],
    ] as [$code, $name, $kind, $lateFine, $sort]) {
        $head->execute(['code' => $code, 'name' => $name, 'kind' => $kind, 'late_fine' => $lateFine, 'sort_order' => $sort, 'now' => $now, 'now2' => $now]);
    }
}
