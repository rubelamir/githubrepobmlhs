<?php

declare(strict_types=1);

/**
 * Versioned DDL for the accounting module (acc_* tables).
 * Each step is idempotent and recorded in schema_migrations.
 */

const ACC_SCHEMA_VERSION = 6;

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
        ['TUITION', 'মাসিক বেতন', 'monthly', 0, 10],
        ['ADMISSION', 'ভর্তি ফি', 'session', 0, 20],
        ['SESSION', 'সেশন ফি', 'session', 0, 30],
        ['EXAM', 'পরীক্ষার ফি', 'exam', 0, 40],
    ] as [$code, $name, $kind, $lateFine, $sort]) {
        $head->execute(['code' => $code, 'name' => $name, 'kind' => $kind, 'late_fine' => $lateFine, 'sort_order' => $sort, 'now' => $now, 'now2' => $now]);
    }
}

/**
 * Phase 3: cash collection, receipts, advances and teacher-to-office handovers.
 * Payments are never deleted: a wrong receipt is reversed, which adds negative
 * allocation rows next to the original ones.
 */
function accMigrationV3(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            receipt_no VARCHAR(30) NOT NULL,
            idempotency_key CHAR(32) NOT NULL,
            student_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL DEFAULT 0,
            roll_snapshot VARCHAR(20) NOT NULL DEFAULT '',
            name_snapshot VARCHAR(255) NOT NULL DEFAULT '',
            amount DECIMAL(14,2) NOT NULL,
            method VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            custody VARCHAR(20) NOT NULL,
            collected_by INT NOT NULL,
            collected_at DATETIME NOT NULL,
            payer_name VARCHAR(120) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            handover_id INT UNSIGNED NULL DEFAULT NULL,
            gateway_txn_id INT UNSIGNED NULL DEFAULT NULL,
            voucher_id INT UNSIGNED NULL DEFAULT NULL,
            sms_status VARCHAR(20) NOT NULL DEFAULT '',
            reversed_by INT NULL DEFAULT NULL,
            reversed_at DATETIME NULL DEFAULT NULL,
            reversal_reason VARCHAR(255) NULL DEFAULT NULL,
            UNIQUE KEY uniq_acc_payment_receipt (receipt_no),
            UNIQUE KEY uniq_acc_payment_idempotency (idempotency_key),
            UNIQUE KEY uniq_acc_payment_gateway (gateway_txn_id),
            KEY idx_acc_payment_student (student_id, session_year),
            KEY idx_acc_payment_collector (collected_by, custody, handover_id),
            KEY idx_acc_payment_date (collected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // invoice_item_id NULL = money held as advance for the student.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_payment_allocations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payment_id INT UNSIGNED NOT NULL,
            student_id INT NOT NULL,
            invoice_item_id INT UNSIGNED NULL DEFAULT NULL,
            invoice_id INT UNSIGNED NULL DEFAULT NULL,
            amount DECIMAL(14,2) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_acc_alloc_payment (payment_id),
            KEY idx_acc_alloc_item (invoice_item_id),
            KEY idx_acc_alloc_student (student_id, invoice_item_id),
            CONSTRAINT fk_acc_alloc_payment FOREIGN KEY (payment_id) REFERENCES acc_payments (id),
            CONSTRAINT fk_acc_alloc_item FOREIGN KEY (invoice_item_id) REFERENCES acc_invoice_items (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_handovers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            handover_no VARCHAR(30) NOT NULL,
            idempotency_key CHAR(32) NOT NULL,
            teacher_id INT NOT NULL,
            total DECIMAL(14,2) NOT NULL,
            payment_count INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            submitted_at DATETIME NOT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            remark VARCHAR(255) NULL DEFAULT NULL,
            voucher_id INT UNSIGNED NULL DEFAULT NULL,
            UNIQUE KEY uniq_acc_handover_no (handover_no),
            UNIQUE KEY uniq_acc_handover_idempotency (idempotency_key),
            KEY idx_acc_handover_status (status, submitted_at),
            KEY idx_acc_handover_teacher (teacher_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // History of which receipts were in which handover (a rejected handover releases them).
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_handover_items (
            handover_id INT UNSIGNED NOT NULL,
            payment_id INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            PRIMARY KEY (handover_id, payment_id),
            KEY idx_acc_handover_item_payment (payment_id),
            CONSTRAINT fk_acc_hi_handover FOREIGN KEY (handover_id) REFERENCES acc_handovers (id),
            CONSTRAINT fk_acc_hi_payment FOREIGN KEY (payment_id) REFERENCES acc_payments (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Phase 4: chart of accounts, double-entry vouchers (manual with approval and
 * system postings from collections), attachments, bank deposits,
 * bank reconciliation and month closing.
 */
function accMigrationV4(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL,
            name_bn VARCHAR(120) NOT NULL,
            account_type VARCHAR(20) NOT NULL,
            money_kind VARCHAR(20) NOT NULL DEFAULT 'none',
            parent_id INT UNSIGNED NULL DEFAULT NULL,
            is_group TINYINT(1) NOT NULL DEFAULT 0,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            description VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_acc_account_code (code),
            KEY idx_acc_account_parent (parent_id),
            KEY idx_acc_account_type (account_type, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // voucher_no is assigned on approval so rejected/cancelled drafts leave no gaps.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_vouchers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            voucher_no VARCHAR(30) NULL DEFAULT NULL,
            voucher_type VARCHAR(20) NOT NULL,
            voucher_date DATE NOT NULL,
            period CHAR(7) NOT NULL,
            narration VARCHAR(255) NOT NULL,
            total DECIMAL(14,2) NOT NULL,
            status VARCHAR(20) NOT NULL,
            source_key VARCHAR(60) NULL DEFAULT NULL,
            idempotency_key CHAR(32) NULL DEFAULT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            submitted_at DATETIME NULL DEFAULT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            decision_remark VARCHAR(255) NULL DEFAULT NULL,
            reversal_of_id INT UNSIGNED NULL DEFAULT NULL,
            reversed_by_id INT UNSIGNED NULL DEFAULT NULL,
            reversal_reason VARCHAR(255) NULL DEFAULT NULL,
            UNIQUE KEY uniq_acc_voucher_no (voucher_no),
            UNIQUE KEY uniq_acc_voucher_source (source_key),
            UNIQUE KEY uniq_acc_voucher_idempotency (idempotency_key),
            UNIQUE KEY uniq_acc_voucher_reversal (reversal_of_id),
            KEY idx_acc_voucher_status (status, voucher_date),
            KEY idx_acc_voucher_period (period, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_voucher_lines (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            voucher_id INT UNSIGNED NOT NULL,
            line_no SMALLINT UNSIGNED NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            party_type VARCHAR(20) NOT NULL DEFAULT '',
            party_id INT NULL DEFAULT NULL,
            memo VARCHAR(160) NOT NULL DEFAULT '',
            KEY idx_acc_vline_voucher (voucher_id),
            KEY idx_acc_vline_account (account_id, voucher_id),
            CONSTRAINT fk_acc_vline_voucher FOREIGN KEY (voucher_id) REFERENCES acc_vouchers (id),
            CONSTRAINT fk_acc_vline_account FOREIGN KEY (account_id) REFERENCES acc_accounts (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_type VARCHAR(20) NOT NULL,
            owner_id INT UNSIGNED NOT NULL,
            stored_name VARCHAR(80) NOT NULL,
            original_name VARCHAR(150) NOT NULL,
            mime VARCHAR(60) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            sha256 CHAR(64) NOT NULL,
            uploaded_by INT NOT NULL,
            uploaded_at DATETIME NOT NULL,
            UNIQUE KEY uniq_acc_attachment_stored (stored_name),
            KEY idx_acc_attachment_owner (owner_type, owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_bank_deposits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            deposit_no VARCHAR(30) NOT NULL,
            idempotency_key CHAR(32) NOT NULL,
            bank_account_id INT UNSIGNED NOT NULL,
            deposit_date DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            slip_no VARCHAR(60) NOT NULL,
            status VARCHAR(20) NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            submitted_by INT NOT NULL,
            submitted_at DATETIME NOT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            remark VARCHAR(255) NULL DEFAULT NULL,
            voucher_id INT UNSIGNED NULL DEFAULT NULL,
            UNIQUE KEY uniq_acc_deposit_no (deposit_no),
            UNIQUE KEY uniq_acc_deposit_idempotency (idempotency_key),
            KEY idx_acc_deposit_status (status, deposit_date),
            CONSTRAINT fk_acc_deposit_account FOREIGN KEY (bank_account_id) REFERENCES acc_accounts (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_bank_reconciliations (
            voucher_line_id INT UNSIGNED NOT NULL PRIMARY KEY,
            statement_date DATE NOT NULL,
            reconciled_by INT NOT NULL,
            reconciled_at DATETIME NOT NULL,
            CONSTRAINT fk_acc_recon_line FOREIGN KEY (voucher_line_id) REFERENCES acc_voucher_lines (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_period_closings (
            period CHAR(7) NOT NULL PRIMARY KEY,
            status VARCHAR(20) NOT NULL,
            closed_by INT NOT NULL,
            closed_at DATETIME NOT NULL,
            close_note VARCHAR(255) NOT NULL DEFAULT '',
            reopened_by INT NULL DEFAULT NULL,
            reopened_at DATETIME NULL DEFAULT NULL,
            reopen_reason VARCHAR(255) NULL DEFAULT NULL,
            balances_json LONGTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!columnExists($db, 'acc_payment_allocations', 'voucher_id')) {
        $db->exec('ALTER TABLE acc_payment_allocations ADD COLUMN voucher_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_acc_alloc_voucher (voucher_id)');
    }
    // Where the cash went at the moment of collection (custody moves on handover).
    if (!columnExists($db, 'acc_payments', 'collected_custody')) {
        $db->exec("ALTER TABLE acc_payments ADD COLUMN collected_custody VARCHAR(20) NOT NULL DEFAULT '' AFTER custody");
        $db->exec(
            "UPDATE acc_payments p
             SET p.collected_custody = CASE
                 WHEN p.custody = 'teacher' OR EXISTS (SELECT 1 FROM acc_handover_items hi WHERE hi.payment_id = p.id) THEN 'teacher'
                 ELSE 'office' END"
        );
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $insert = $db->prepare(
        'INSERT IGNORE INTO acc_accounts (code, name_bn, account_type, money_kind, parent_id, is_group, is_system, is_active, created_at, updated_at)
         VALUES (:code, :name, :type, :money, (SELECT id FROM (SELECT id FROM acc_accounts WHERE code = :parent) AS p), :is_group, :is_system, 1, :now, :now2)'
    );
    foreach ([
        ['1000', 'সম্পদ', 'asset', 'none', '', 1],
        ['1100', 'নগদ ও ব্যাংক', 'asset', 'none', '1000', 1],
        ['1110', 'নগদ তহবিল (অফিস)', 'asset', 'cash', '1100', 0],
        ['1120', 'শিক্ষকের কাছে অনিষ্পন্ন আদায়', 'asset', 'teacher_custody', '1100', 0],
        ['1130', 'bKash মার্চেন্ট হিসাব', 'asset', 'bkash', '1100', 0],
        ['1140', 'Nagad মার্চেন্ট হিসাব', 'asset', 'nagad', '1100', 0],
        ['1150', 'ব্যাংক হিসাবসমূহ', 'asset', 'bank', '1100', 1],
        ['2000', 'দায়', 'liability', 'none', '', 1],
        ['2110', 'অগ্রিম প্রাপ্ত ফি', 'liability', 'none', '2000', 0],
        ['2120', 'রিফান্ড প্রদেয়', 'liability', 'none', '2000', 0],
        ['3000', 'তহবিল', 'fund', 'none', '', 1],
        ['3100', 'সাধারণ তহবিল', 'fund', 'none', '3000', 0],
        ['4000', 'আয়', 'income', 'none', '', 1],
        ['4100', 'ফি আয়', 'income', 'none', '4000', 1],
        ['4110', 'মাসিক বেতন', 'income', 'none', '4100', 0],
        ['4120', 'ভর্তি ফি', 'income', 'none', '4100', 0],
        ['4125', 'সেশন ফি', 'income', 'none', '4100', 0],
        ['4130', 'পরীক্ষার ফি', 'income', 'none', '4100', 0],
        ['4140', 'বিলম্ব জরিমানা', 'income', 'none', '4100', 0],
        ['4190', 'অন্যান্য ফি', 'income', 'none', '4100', 0],
        ['4200', 'সরকারি অনুদান', 'income', 'none', '4000', 0],
        ['4300', 'দান', 'income', 'none', '4000', 0],
        ['4400', 'ব্যাংক সুদ', 'income', 'none', '4000', 0],
        ['5000', 'ব্যয়', 'expense', 'none', '', 1],
        ['5110', 'বেতন ও সম্মানী', 'expense', 'none', '5000', 0],
        ['5120', 'বিদ্যুৎ ও পানি', 'expense', 'none', '5000', 0],
        ['5130', 'মুদ্রণ ও স্টেশনারি', 'expense', 'none', '5000', 0],
        ['5140', 'পরীক্ষা ব্যয়', 'expense', 'none', '5000', 0],
        ['5150', 'মেরামত ও রক্ষণাবেক্ষণ', 'expense', 'none', '5000', 0],
        ['5160', 'গেটওয়ে চার্জ', 'expense', 'none', '5000', 0],
        ['5170', 'ব্যাংক চার্জ', 'expense', 'none', '5000', 0],
        ['5190', 'বিবিধ ব্যয়', 'expense', 'none', '5000', 0],
    ] as [$code, $name, $type, $money, $parent, $isGroup]) {
        $insert->execute([
            'code' => $code, 'name' => $name, 'type' => $type, 'money' => $money, 'parent' => $parent,
            'is_group' => $isGroup, 'is_system' => 1, 'now' => $now, 'now2' => $now,
        ]);
    }

    $db->exec(
        "UPDATE acc_fee_heads h JOIN acc_accounts a ON a.code = CASE
             WHEN h.code = 'TUITION' OR h.billing_kind = 'monthly' THEN '4110'
             WHEN h.code = 'ADMISSION' THEN '4120'
             WHEN h.code = 'SESSION' OR h.billing_kind = 'session' THEN '4125'
             WHEN h.billing_kind = 'exam' THEN '4130'
             ELSE '4190' END
         SET h.income_account_id = a.id
         WHERE h.income_account_id IS NULL"
    );
}

/**
 * Phase 5: online payments (bKash, Nagad adapter), masked gateway logs,
 * refunds, gateway settlements and the guardian portal OTPs.
 */
function accMigrationV5(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_gateway_settings (
            gateway VARCHAR(20) NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            charge_bearer VARCHAR(10) NOT NULL DEFAULT 'school',
            charge_rate_bp INT UNSIGNED NOT NULL DEFAULT 0,
            updated_by INT NULL DEFAULT NULL,
            updated_at DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Tokens are stored encrypted with the app signing key.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_gateway_tokens (
            gateway VARCHAR(20) NOT NULL PRIMARY KEY,
            id_token TEXT NULL,
            refresh_token TEXT NULL,
            expires_at DATETIME NULL DEFAULT NULL,
            refresh_expires_at DATETIME NULL DEFAULT NULL,
            calls_json VARCHAR(255) NOT NULL DEFAULT '[]',
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_gateway_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gateway VARCHAR(20) NOT NULL,
            merchant_invoice_no VARCHAR(40) NOT NULL,
            idempotency_key CHAR(32) NOT NULL,
            student_id INT NOT NULL,
            invoice_ids VARCHAR(255) NOT NULL DEFAULT '',
            fee_amount DECIMAL(14,2) NOT NULL,
            charge_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            charge_bearer VARCHAR(10) NOT NULL,
            payable_amount DECIMAL(14,2) NOT NULL,
            status VARCHAR(20) NOT NULL,
            callback_status VARCHAR(20) NULL DEFAULT NULL,
            gateway_payment_id VARCHAR(80) NULL DEFAULT NULL,
            trx_id VARCHAR(40) NULL DEFAULT NULL,
            gateway_status VARCHAR(30) NULL DEFAULT NULL,
            paid_amount DECIMAL(14,2) NULL DEFAULT NULL,
            payer_account_masked VARCHAR(20) NULL DEFAULT NULL,
            error_code VARCHAR(20) NULL DEFAULT NULL,
            error_message VARCHAR(255) NULL DEFAULT NULL,
            payment_id INT UNSIGNED NULL DEFAULT NULL,
            refunded_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            source VARCHAR(20) NOT NULL DEFAULT 'guardian',
            client_ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            execute_attempted_at DATETIME NULL DEFAULT NULL,
            completed_at DATETIME NULL DEFAULT NULL,
            last_queried_at DATETIME NULL DEFAULT NULL,
            query_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_acc_gw_invoice (merchant_invoice_no),
            UNIQUE KEY uniq_acc_gw_idempotency (idempotency_key),
            UNIQUE KEY uniq_acc_gw_payment_ref (gateway, gateway_payment_id),
            UNIQUE KEY uniq_acc_gw_trx (gateway, trx_id),
            UNIQUE KEY uniq_acc_gw_payment (payment_id),
            KEY idx_acc_gw_status (status, created_at),
            KEY idx_acc_gw_student (student_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_gateway_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gateway VARCHAR(20) NOT NULL,
            gateway_txn_id INT UNSIGNED NULL DEFAULT NULL,
            refund_id INT UNSIGNED NULL DEFAULT NULL,
            operation VARCHAR(30) NOT NULL,
            endpoint VARCHAR(160) NOT NULL,
            http_status INT NOT NULL DEFAULT 0,
            request_masked TEXT NULL,
            response_masked TEXT NULL,
            error VARCHAR(255) NULL DEFAULT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_acc_gwlog_txn (gateway_txn_id),
            KEY idx_acc_gwlog_refund (refund_id),
            KEY idx_acc_gwlog_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_refunds (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            refund_no VARCHAR(30) NOT NULL,
            idempotency_key CHAR(32) NOT NULL,
            gateway_txn_id INT UNSIGNED NOT NULL,
            payment_id INT UNSIGNED NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            refund_trx_id VARCHAR(40) NULL DEFAULT NULL,
            gateway_error VARCHAR(255) NULL DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            requested_by INT NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            remark VARCHAR(255) NULL DEFAULT NULL,
            last_attempt_at DATETIME NULL DEFAULT NULL,
            completed_at DATETIME NULL DEFAULT NULL,
            voucher_id INT UNSIGNED NULL DEFAULT NULL,
            UNIQUE KEY uniq_acc_refund_no (refund_no),
            UNIQUE KEY uniq_acc_refund_idempotency (idempotency_key),
            UNIQUE KEY uniq_acc_refund_trx (refund_trx_id),
            KEY idx_acc_refund_txn (gateway_txn_id),
            KEY idx_acc_refund_status (status),
            CONSTRAINT fk_acc_refund_txn FOREIGN KEY (gateway_txn_id) REFERENCES acc_gateway_transactions (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_settlements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            settlement_no VARCHAR(30) NOT NULL,
            idempotency_key CHAR(32) NOT NULL,
            gateway VARCHAR(20) NOT NULL,
            settlement_date DATE NOT NULL,
            gross DECIMAL(14,2) NOT NULL,
            charge DECIMAL(14,2) NOT NULL DEFAULT 0,
            net DECIMAL(14,2) NOT NULL,
            bank_account_id INT UNSIGNED NOT NULL,
            reference VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            submitted_by INT NOT NULL,
            submitted_at DATETIME NOT NULL,
            decided_by INT NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            remark VARCHAR(255) NULL DEFAULT NULL,
            voucher_id INT UNSIGNED NULL DEFAULT NULL,
            UNIQUE KEY uniq_acc_settlement_no (settlement_no),
            UNIQUE KEY uniq_acc_settlement_idempotency (idempotency_key),
            KEY idx_acc_settlement_status (status, settlement_date),
            CONSTRAINT fk_acc_settlement_bank FOREIGN KEY (bank_account_id) REFERENCES acc_accounts (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS acc_guardian_otps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            code_hash CHAR(64) NOT NULL,
            client_ip VARCHAR(45) NOT NULL DEFAULT '',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            KEY idx_acc_otp_student (student_id, created_at),
            KEY idx_acc_otp_ip (client_ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // v4 already seeds these; repeat here so a database that applied v4 before the
    // gateway accounts existed still gets them.
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
    $account = $db->prepare(
        'INSERT IGNORE INTO acc_accounts (code, name_bn, account_type, money_kind, parent_id, is_group, is_system, is_active, created_at, updated_at)
         VALUES (:code, :name, :type, :money, (SELECT id FROM (SELECT id FROM acc_accounts WHERE code = :parent) AS p), 0, 1, 1, :now, :now2)'
    );
    foreach ([
        ['1130', 'bKash মার্চেন্ট হিসাব', 'asset', 'bkash', '1100'],
        ['1140', 'Nagad মার্চেন্ট হিসাব', 'asset', 'nagad', '1100'],
        ['2120', 'রিফান্ড প্রদেয়', 'liability', 'none', '2000'],
        ['5160', 'গেটওয়ে চার্জ', 'expense', 'none', '5000'],
        ['5170', 'ব্যাংক চার্জ', 'expense', 'none', '5000'],
    ] as [$code, $name, $type, $money, $parent]) {
        $account->execute(['code' => $code, 'name' => $name, 'type' => $type, 'money' => $money, 'parent' => $parent, 'now' => $now, 'now2' => $now]);
    }

    $insert = $db->prepare('INSERT IGNORE INTO acc_gateway_settings (gateway, enabled, charge_bearer, charge_rate_bp, updated_at) VALUES (:gateway, 0, :bearer, 0, :now)');
    foreach (['bkash', 'nagad'] as $gateway) {
        $insert->execute(['gateway' => $gateway, 'bearer' => 'school', 'now' => $now]);
    }
}

/**
 * Late fines are not used. Outstanding fine lines stop being collectable.
 * A fine that was already paid stays, so the receipt is unchanged.
 */
function accMigrationV6(PDO $db): void
{
    $db->exec('UPDATE acc_fee_heads SET late_fine_allowed = 0 WHERE late_fine_allowed <> 0');
    $db->exec('UPDATE acc_fee_structures SET late_fine_amount = 0, grace_days = 0 WHERE late_fine_amount <> 0 OR grace_days <> 0');
    $db->exec("UPDATE acc_invoice_items SET late_fine_amount = 0, grace_days = 0 WHERE late_fine_amount <> 0 OR grace_days <> 0");
    $db->exec(
        "UPDATE acc_invoice_items
         SET standard_amount = paid, amount = paid, net = paid
         WHERE line_kind = 'late_fine' AND (net > paid OR amount > paid)"
    );
    $db->exec(
        "UPDATE acc_invoices inv
         INNER JOIN (
            SELECT invoice_id,
                COALESCE(SUM(CASE WHEN line_kind = 'fee' THEN standard_amount ELSE 0 END), 0) AS standard_total,
                COALESCE(SUM(CASE WHEN line_kind = 'fee' THEN category_concession ELSE 0 END), 0) AS category_concession,
                COALESCE(SUM(CASE WHEN line_kind = 'fee' THEN amount ELSE 0 END), 0) AS gross,
                COALESCE(SUM(CASE WHEN line_kind = 'late_fine' THEN amount ELSE 0 END), 0) AS fine,
                COALESCE(SUM(discount), 0) AS discount,
                COALESCE(SUM(waived), 0) AS waived,
                COALESCE(SUM(net), 0) AS net,
                COALESCE(SUM(paid), 0) AS paid
            FROM acc_invoice_items
            GROUP BY invoice_id
         ) s ON s.invoice_id = inv.id
         INNER JOIN (
            SELECT DISTINCT invoice_id FROM acc_invoice_items WHERE line_kind = 'late_fine'
         ) f ON f.invoice_id = inv.id
         SET inv.standard_total = s.standard_total,
             inv.category_concession = s.category_concession,
             inv.gross = s.gross,
             inv.fine = s.fine,
             inv.discount = s.discount,
             inv.waived = s.waived,
             inv.net = s.net,
             inv.paid = s.paid,
             inv.status = CASE
                WHEN inv.status = 'cancelled' THEN 'cancelled'
                WHEN s.net <= s.paid THEN 'paid'
                WHEN s.paid > 0 THEN 'partial'
                ELSE 'unpaid'
             END"
    );
}
