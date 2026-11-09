<?php

declare(strict_types=1);

/**
 * Versioned DDL for the accounting module (acc_* tables).
 * Each step is idempotent and recorded in schema_migrations.
 */

const ACC_SCHEMA_VERSION = 1;

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

    accMigrationV1($db);
    recordSchemaMigration($db, 'acc_schema_v' . ACC_SCHEMA_VERSION);
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
