<?php
declare(strict_types=1);

const SCHOOL_SCHEMA_VERSION = 22;

function schemaMigrationApplied(PDO $db, string $name): bool
{
    if (!tableExists($db, 'schema_migrations')) {
        return false;
    }
    $rows = fetchRows($db, 'SELECT name FROM schema_migrations WHERE name = :name LIMIT 1', ['name' => $name]);

    return $rows !== [];
}

function recordSchemaMigration(PDO $db, string $name): void
{
    if (!tableExists($db, 'schema_migrations')) {
        return;
    }
    $db->prepare(
        'INSERT IGNORE INTO schema_migrations (name) VALUES (:name)'
    )->execute(['name' => $name]);
}

function schemaVersionIsCurrent(PDO $db): bool
{
    try {
        $statement = $db->query(
            'SELECT 1 FROM schema_migrations WHERE name = ' . $db->quote('schema_v' . SCHOOL_SCHEMA_VERSION) . ' LIMIT 1'
        );

        return $statement !== false && $statement->fetchColumn() !== false;
    } catch (PDOException $exception) {
        return false;
    }
}

function ensureContactMessagesTable(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS contact_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_name VARCHAR(120) NOT NULL,
            mobile VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_contact_unread (is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureSchoolSchema(PDO $db): void
{
    static $completed = false;
    if ($completed) {
        return;
    }
    $completed = true;

    // Idempotent DDL checks are slow (information_schema, metadata locks); run them once per schema.php revision.
    $marker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'school_schema_'
        . md5(__FILE__ . '|' . (string) $db->query('SELECT DATABASE()')->fetchColumn()) . '_'
        . (string) filemtime(__FILE__) . '_' . SCHOOL_SCHEMA_VERSION;
    if (is_file($marker)) {
        if (!tableExists($db, 'contact_messages')) {
            ensureContactMessagesTable($db);
        }

        return;
    }

    // Additive columns must run even when schema version is already current.
    if (tableExists($db, 'school_settings')) {
        ensureColumn($db, 'school_settings', 'short_name_en', "VARCHAR(40) DEFAULT NULL");
        ensureColumn($db, 'school_settings', 'address_en', "VARCHAR(500) NOT NULL DEFAULT ''");
        ensureColumn($db, 'school_settings', 'notify_from_email', 'VARCHAR(120) DEFAULT NULL');
        ensureColumn($db, 'school_settings', 'notify_parent_emails', 'TEXT');
        ensureColumn($db, 'school_settings', 'notify_sms_url', 'VARCHAR(500) DEFAULT NULL');
        ensureColumn($db, 'school_settings', 'sms_provider', "VARCHAR(20) NOT NULL DEFAULT ''");
        ensureColumn($db, 'school_settings', 'sms_api_token', "VARCHAR(255) NOT NULL DEFAULT ''");
        ensureColumn($db, 'school_settings', 'sms_sid', "VARCHAR(80) NOT NULL DEFAULT ''");
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS sms_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            context_key VARCHAR(40) NOT NULL DEFAULT '',
            recipient VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            detail VARCHAR(180) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sms_log_context (context_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (tableExists($db, 'exam_details')) {
        ensureColumn($db, 'exam_details', 'public_results_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (tableExists($db, 'students_info')) {
        ensureColumn($db, 'students_info', 'village_en', "VARCHAR(255) NOT NULL DEFAULT ''");
        ensureColumn($db, 'students_info', 'post_office_en', "VARCHAR(255) NOT NULL DEFAULT ''");
    }
    if (tableExists($db, 'student_promotions')) {
        ensureColumn($db, 'student_promotions', 'promotion_batch', 'VARCHAR(40) DEFAULT NULL');
    }

    // New tables / SSC candidate fields must run even when schema version is already current.
    ensureExamRoutineSchema($db);
    ensureSscCandidateSchema($db);
    ensureStudentEnrollmentsSchema($db);
    ensureMarkSubmissionSchema($db);
    ensureRoutineSlotIndex($db);
    ensureContactMessagesTable($db);

    if (schemaVersionIsCurrent($db)) {
        @file_put_contents($marker, (string) time());

        return;
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(120) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    ensureTeacherPanelSchema($db);
    ensureTeacherSortOrder($db);
    ensureEventSortOrder($db);
    ensureCarouselSortOrder($db);
    ensureAttendanceNavMenu($db);
    dropUnionAddressFields($db);
    dropUnionsLookupTable($db);
    dropPreviousSchoolExamFields($db);
    dropProfessionGuardianFields($db);
    ensureAdmissionTokenSchema($db);
    convertLastClassToText($db);
    ensureSchoolMessagesSchema($db);
    ensureCommitteeSchema($db);
    ensureNullableNoticeAttachment($db);
    ensureNoticeTextColumn($db);
    ensureEventImagesSchema($db);
    ensureRetirementTeacherSchema($db);
    ensureRoutineSchema($db);
    ensureExamRoutineSchema($db);
    ensureStudentSearchIndexes($db);
    ensureStudentAdmissionTokenIndex($db);
    ensurePublicFeaturesSchema($db);
    ensureColumn($db, 'school_settings', 'short_name_en', "VARCHAR(40) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'address_en', "VARCHAR(500) NOT NULL DEFAULT ''");
    if (tableExists($db, 'students_info')) {
        ensureColumn($db, 'students_info', 'village_en', "VARCHAR(255) NOT NULL DEFAULT ''");
        ensureColumn($db, 'students_info', 'post_office_en', "VARCHAR(255) NOT NULL DEFAULT ''");
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS school_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_name_bn VARCHAR(255) NOT NULL,
            school_name_en VARCHAR(255) NOT NULL,
            address VARCHAR(500) NOT NULL,
            established VARCHAR(100) NOT NULL,
            eiin VARCHAR(50) NOT NULL,
            mpo_code VARCHAR(50) NOT NULL,
            mobile VARCHAR(50) NOT NULL,
            email VARCHAR(150) NOT NULL,
            logo VARCHAR(255) DEFAULT NULL,
            about_short TEXT,
            facebook VARCHAR(255) DEFAULT NULL,
            map_embed TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS student_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            status ENUM('present','absent','late') NOT NULL DEFAULT 'present',
            remarks VARCHAR(255) DEFAULT NULL,
            marked_by VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_date (student_id, attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS main_notice (
            id INT AUTO_INCREMENT PRIMARY KEY,
            main_notice_text TEXT NOT NULL,
            main_notice_file VARCHAR(255) DEFAULT NULL,
            main_notice_status TINYINT(1) NOT NULL DEFAULT 1,
            main_notice_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS notice_headlines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notice_headlines VARCHAR(500) NOT NULL,
            notice_headlines_status TINYINT(1) NOT NULL DEFAULT 1,
            notice_headlines_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            events_headlines VARCHAR(255) NOT NULL,
            events_details TEXT,
            events_file VARCHAR(255) DEFAULT NULL,
            events_status TINYINT(1) NOT NULL DEFAULT 1,
            events_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS carousel (
            id INT AUTO_INCREMENT PRIMARY KEY,
            carousel_text VARCHAR(255) NOT NULL,
            carousel_file VARCHAR(255) DEFAULT NULL,
            carousel_status TINYINT(1) NOT NULL DEFAULT 1,
            carousel_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            files_text VARCHAR(255) NOT NULL,
            files_file VARCHAR(255) DEFAULT NULL,
            files_status TINYINT(1) NOT NULL DEFAULT 1,
            files_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS image_gallery (
            id INT AUTO_INCREMENT PRIMARY KEY,
            image_gallery_caption VARCHAR(255) NOT NULL,
            image_gallery_file VARCHAR(255) DEFAULT NULL,
            image_gallery_status TINYINT(1) NOT NULL DEFAULT 1,
            image_gallery_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS school_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_history TEXT NOT NULL,
            school_history_status TINYINT(1) NOT NULL DEFAULT 1,
            school_history_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS comitee (
            id INT AUTO_INCREMENT PRIMARY KEY,
            comitee_name VARCHAR(255) NOT NULL,
            comitee_designation VARCHAR(50) NOT NULL,
            comitee_mobile VARCHAR(50) DEFAULT NULL,
            comitee_picture VARCHAR(255) DEFAULT NULL,
            comitee_status TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS class_routine (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            day_of_week TINYINT NOT NULL,
            period_no TINYINT NOT NULL,
            period_time VARCHAR(50) DEFAULT NULL,
            subject_name VARCHAR(255) NOT NULL,
            teacher_mobile VARCHAR(50) DEFAULT NULL,
            room VARCHAR(50) DEFAULT NULL,
            KEY idx_routine_slot (class_id, section_id, day_of_week, period_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS student_promotions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            from_class INT DEFAULT NULL,
            from_section VARCHAR(20) DEFAULT NULL,
            from_session VARCHAR(20) DEFAULT NULL,
            from_roll INT DEFAULT NULL,
            to_class INT DEFAULT NULL,
            to_section VARCHAR(20) DEFAULT NULL,
            to_session VARCHAR(20) DEFAULT NULL,
            to_roll INT DEFAULT NULL,
            remarks VARCHAR(255) DEFAULT NULL,
            promoted_by VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS student_certificates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            cert_type VARCHAR(50) NOT NULL,
            serial_no VARCHAR(80) NOT NULL,
            issue_date DATE NOT NULL,
            remarks TEXT,
            issued_by VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS nav_menus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label_bn VARCHAR(255) NOT NULL,
            label_en VARCHAR(255) DEFAULT NULL,
            url VARCHAR(500) NOT NULL,
            parent_id INT DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            target VARCHAR(20) NOT NULL DEFAULT '_self',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_nav_parent (parent_id),
            KEY idx_nav_sort (sort_order),
            KEY idx_nav_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS homepage_info_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            url VARCHAR(500) NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_info_cards_sort (sort_order, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS homepage_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            link_group VARCHAR(30) NOT NULL,
            label VARCHAR(255) NOT NULL,
            url VARCHAR(500) NOT NULL,
            target VARCHAR(20) NOT NULL DEFAULT '_self',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_home_links_group (link_group, sort_order, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // Replaces the fixed s1..s14 columns of the legacy `subjects` table, so a
        // class/section can carry any number of subjects in any order.
        "CREATE TABLE IF NOT EXISTS class_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            subject_name VARCHAR(150) NOT NULL,
            subject_name_ban VARCHAR(150) NOT NULL,
            subject_code VARCHAR(20) DEFAULT NULL,
            has_mcq TINYINT(1) NOT NULL DEFAULT 1,
            has_cq TINYINT(1) NOT NULL DEFAULT 1,
            has_practical TINYINT(1) NOT NULL DEFAULT 0,
            full_marks SMALLINT NOT NULL DEFAULT 100,
            pass_marks SMALLINT NOT NULL DEFAULT 33,
            is_optional TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            legacy_slot TINYINT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_class_subject_slot (class_id, section_id, legacy_slot),
            KEY idx_class_subject_lookup (class_id, section_id, sort_order, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // One row per student, exam and subject, instead of one wide row holding
        // s1_mcq .. s14_cq for every paper at once.
        "CREATE TABLE IF NOT EXISTS student_marks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT DEFAULT NULL,
            cls_roll VARCHAR(20) NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL DEFAULT '',
            exam_id INT NOT NULL,
            class_subject_id INT NOT NULL,
            mcq DECIMAL(6,2) DEFAULT NULL,
            cq DECIMAL(6,2) DEFAULT NULL,
            practical DECIMAL(6,2) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_subject_mark (cls_roll, class_id, section_id, session_year, exam_id, class_subject_id),
            KEY idx_student_marks_student (student_id),
            KEY idx_student_marks_sheet (class_id, section_id, session_year, exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS class_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_no TINYINT NOT NULL,
            period_time VARCHAR(50) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_period_no (period_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        // `marks.remarks` was per student and exam, not per subject, so it cannot
        // live on student_marks.
        "CREATE TABLE IF NOT EXISTS student_exam_remarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cls_roll VARCHAR(20) NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL DEFAULT '',
            exam_id INT NOT NULL,
            remarks VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_exam_remarks (cls_roll, class_id, section_id, session_year, exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS ssc_exam_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_year INT NOT NULL,
            gpa5 INT NOT NULL DEFAULT 0,
            gpa4 INT NOT NULL DEFAULT 0,
            gpa3 INT NOT NULL DEFAULT 0,
            gpa2 INT NOT NULL DEFAULT 0,
            gpa1 INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_ssc_year (exam_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($statements as $sql) {
        $db->exec($sql);
    }

    ensureColumn($db, 'marks', 'session_year', "VARCHAR(20) DEFAULT NULL AFTER exam_details_id");
    ensureColumn($db, 'student_attendance', 'remarks', 'VARCHAR(255) DEFAULT NULL');

    // `cls` and `sections` predate the admin panel and carry no ordering or
    // enabled flag, so the academic sequence used to live in hardcoded PHP maps.
    ensureColumn($db, 'cls', 'sort_order', 'INT NOT NULL DEFAULT 0');
    ensureColumn($db, 'cls', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensureColumn($db, 'sections', 'sort_order', 'INT NOT NULL DEFAULT 0');
    ensureColumn($db, 'sections', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensureColumn($db, 'cls', 'open_for_admission', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensureColumn($db, 'class_subjects', 'teacher_mobile', 'VARCHAR(50) DEFAULT NULL');
    ensureColumn($db, 'exam_details', 'sort_order', 'INT NOT NULL DEFAULT 0');
    ensureColumn($db, 'exam_details', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensureColumn($db, 'exam_details', 'public_results_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
    seedAcademicOrdering($db);
    seedClassPeriods($db);
    migrateLegacySubjectsAndMarks($db);
    ensureColumn($db, 'school_settings', 'nav_cta_label', "VARCHAR(100) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'nav_cta_url', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'footer_credit', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_history', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_info', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_events', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_ssc', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_teachers', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_president', "VARCHAR(100) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_head_teacher', "VARCHAR(100) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_asst_head', "VARCHAR(100) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_eservices', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_important_links', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'title_messages', "VARCHAR(255) DEFAULT NULL");
    ensureColumn($db, 'school_settings', 'short_name_en', "VARCHAR(40) DEFAULT NULL");
    if (tableExists($db, 'school_settings') && columnExists($db, 'school_settings', 'short_name_en')) {
        $db->exec(
            "UPDATE school_settings
             SET short_name_en = 'BMLHS'
             WHERE short_name_en IS NULL OR TRIM(short_name_en) = ''"
        );
    }

    if ((int) $db->query('SELECT COUNT(*) FROM school_settings')->fetchColumn() === 0) {
        $db->exec(
            "INSERT INTO school_settings
             (school_name_bn, school_name_en, address, established, eiin, mpo_code, mobile, email, logo, about_short)
             VALUES
             ('ভবানীগঞ্জ বহুমুখী উচ্চ বিদ্যালয়', 'Bhabanigonj ML High School',
              'ডাকঘর: ভবানীগঞ্জ, উপজেলা: লক্ষ্মীপুর সদর, জেলা: লক্ষ্মীপুর।',
              '১৯৪৭ইং', '106888', '1001181302', '01309-106888', 'bbhs1947@gmail.com',
              'image/school_logo.png',
              'শিক্ষা, নৈতিকতা ও সুনাগরিক গড়ার প্রতিশ্রুতিতে পরিচালিত একটি ঐতিহ্যবাহী মাধ্যমিক বিদ্যালয়।')"
        );
    }

    seedDefaultNavMenus($db);
    seedHomepageCms($db);
    ensureTeacherPanelSchema($db);
    ensureTeacherSortOrder($db);
    ensureEventSortOrder($db);
    ensureCarouselSortOrder($db);
    ensureAttendanceNavMenu($db);
    recordSchemaMigration($db, 'schema_v' . SCHOOL_SCHEMA_VERSION);
}

function dropUnionAddressFields(PDO $db): void
{
    if (schemaMigrationApplied($db, 'drop_union_address_fields_v1')) {
        return;
    }

    dropColumn($db, 'students_info', 'unions');
    dropColumn($db, 'teacher_info', 'teacher_unions');
    recordSchemaMigration($db, 'drop_union_address_fields_v1');
}

function dropUnionsLookupTable(PDO $db): void
{
    if (schemaMigrationApplied($db, 'drop_unions_lookup_table_v1')) {
        return;
    }

    $db->exec('DROP TABLE IF EXISTS `unions`');
    recordSchemaMigration($db, 'drop_unions_lookup_table_v1');
}

function dropPreviousSchoolExamFields(PDO $db): void
{
    if (schemaMigrationApplied($db, 'drop_previous_school_exam_fields_v1')) {
        return;
    }

    dropColumn($db, 'students_info', 'last_exam');
    dropColumn($db, 'students_info', 'exam_result');
    recordSchemaMigration($db, 'drop_previous_school_exam_fields_v1');
}

function dropProfessionGuardianFields(PDO $db): void
{
    if (schemaMigrationApplied($db, 'drop_profession_guardian_fields_v1')) {
        return;
    }

    dropColumn($db, 'students_info', 'fprofession');
    dropColumn($db, 'students_info', 'mprofession');
    dropColumn($db, 'students_info', 'guardian_name');
    dropColumn($db, 'students_info', 'guardian_relation');
    dropColumn($db, 'students_info', 'guardian_mobile');
    recordSchemaMigration($db, 'drop_profession_guardian_fields_v1');
}

function ensureAdmissionTokenSchema(PDO $db): void
{
    if (schemaMigrationApplied($db, 'admission_token_table_v1')) {
        return;
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS `token` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(255) NOT NULL,
            token_status TINYINT(1) NOT NULL DEFAULT 0,
            token_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_admission_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (tableExists($db, 'token') && columnExists($db, 'token', 'status') && !columnExists($db, 'token', 'token_status')) {
        $db->exec('ALTER TABLE `token` CHANGE `status` `token_status` TINYINT(1) NOT NULL DEFAULT 0');
        refreshColumnCache($db, 'token');
    }
    ensureColumn($db, 'token', 'token_status', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($db, 'token', 'token_creation_time', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

    if (tableExists($db, 'token')) {
        $exists = (int) $db->query(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ' . $db->quote('token') . '
               AND index_name = ' . $db->quote('uniq_admission_token')
        )->fetchColumn();
        if ($exists === 0) {
            try {
                $db->exec('ALTER TABLE `token` ADD UNIQUE KEY `uniq_admission_token` (`token`)');
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
            }
        }
    }

    recordSchemaMigration($db, 'admission_token_table_v1');
}

function convertLastClassToText(PDO $db): void
{
    if (schemaMigrationApplied($db, 'last_class_text_v1')) {
        return;
    }
    if (!tableExists($db, 'students_info') || !columnExists($db, 'students_info', 'last_class')) {
        recordSchemaMigration($db, 'last_class_text_v1');
        return;
    }

    if (!columnExists($db, 'students_info', 'last_class_text')) {
        $db->exec("ALTER TABLE `students_info` ADD `last_class_text` VARCHAR(100) NOT NULL DEFAULT ''");
    }
    if (tableExists($db, 'cls')) {
        $db->exec(
            "UPDATE students_info s
             LEFT JOIN cls c ON CAST(s.last_class AS CHAR) = CAST(c.id AS CHAR)
             SET s.last_class_text = CASE
                WHEN c.id IS NOT NULL THEN COALESCE(NULLIF(c.class_name_ban, ''), c.class_name, CAST(s.last_class AS CHAR))
                WHEN CAST(s.last_class AS CHAR) IN ('', '0') THEN ''
                ELSE CAST(s.last_class AS CHAR)
             END"
        );
    } else {
        $db->exec("UPDATE students_info SET last_class_text = CAST(last_class AS CHAR)");
    }

    dropColumn($db, 'students_info', 'last_class');
    $db->exec("ALTER TABLE `students_info` CHANGE `last_class_text` `last_class` VARCHAR(100) NOT NULL DEFAULT ''");
    refreshColumnCache($db, 'students_info');
    recordSchemaMigration($db, 'last_class_text_v1');
}

function ensureTeacherPanelSchema(PDO $db): void
{
    if (tableExists($db, 'teacher_info')) {
        ensureColumn($db, 'teacher_info', 'panel_role', "VARCHAR(20) NOT NULL DEFAULT 'teacher'");
        ensureColumn($db, 'teacher_info', 'admin_modules', 'TEXT DEFAULT NULL');
    }
    if (tableExists($db, 'student_marks')) {
        ensureColumn($db, 'student_marks', 'entered_by', 'VARCHAR(50) DEFAULT NULL');
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS class_teachers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            section_id INT NOT NULL DEFAULT 0,
            session_year VARCHAR(20) NOT NULL DEFAULT '',
            teacher_mobile VARCHAR(50) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_class_teacher_slot (class_id, section_id, session_year),
            KEY idx_class_teacher_mobile (teacher_mobile, session_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!tableExists($db, 'teacher_info') || !columnExists($db, 'teacher_info', 'panel_role')) {
        return;
    }

    if (schemaMigrationApplied($db, 'panel_role_seed_v1')) {
        return;
    }

    $heads = (int) $db->query(
        "SELECT COUNT(*) FROM teacher_info WHERE panel_role = 'head'"
    )->fetchColumn();
    if ($heads === 0) {
        $headId = $db->query(
            "SELECT id FROM teacher_info
             WHERE teacher_designation = 'head_teacher'
             ORDER BY teacher_designation_serial IS NULL, teacher_designation_serial ASC, id ASC
             LIMIT 1"
        )->fetchColumn();
        if ($headId) {
            $db->prepare("UPDATE teacher_info SET panel_role = 'head' WHERE id = :id LIMIT 1")
                ->execute(['id' => (int) $headId]);
        }
        $heads = (int) $db->query(
            "SELECT COUNT(*) FROM teacher_info WHERE panel_role = 'head'"
        )->fetchColumn();
    }
    if ($heads === 0) {
        $fallbackId = $db->query(
            "SELECT id FROM teacher_info
             ORDER BY teacher_designation_serial IS NULL, teacher_designation_serial ASC, id ASC
             LIMIT 1"
        )->fetchColumn();
        if ($fallbackId) {
            $db->prepare("UPDATE teacher_info SET panel_role = 'head' WHERE id = :id LIMIT 1")
                ->execute(['id' => (int) $fallbackId]);
        }
    }

    recordSchemaMigration($db, 'panel_role_seed_v1');
}

function ensureTeacherSortOrder(PDO $db): void
{
    if (!tableExists($db, 'teacher_info')) {
        return;
    }

    ensureColumn($db, 'teacher_info', 'sort_order', 'INT NOT NULL DEFAULT 0');

    if (schemaMigrationApplied($db, 'teacher_sort_order_v15')) {
        return;
    }

    $rows = fetchRows(
        $db,
        'SELECT id FROM teacher_info
         ORDER BY teacher_designation_serial IS NULL, teacher_designation_serial ASC, teacher_name ASC, id ASC'
    );
    $update = $db->prepare('UPDATE teacher_info SET sort_order = :sort WHERE id = :id');
    foreach ($rows as $index => $row) {
        $update->execute([
            'sort' => ((int) $index + 1) * 10,
            'id' => (int) $row['id'],
        ]);
    }

    recordSchemaMigration($db, 'teacher_sort_order_v15');
}

function ensureEventSortOrder(PDO $db): void
{
    if (!tableExists($db, 'events')) {
        return;
    }

    ensureColumn($db, 'events', 'sort_order', 'INT NOT NULL DEFAULT 0');

    if (schemaMigrationApplied($db, 'event_sort_order_v16')) {
        return;
    }

    $rows = fetchRows(
        $db,
        'SELECT id FROM events ORDER BY events_creation_time DESC, id DESC'
    );
    $update = $db->prepare('UPDATE events SET sort_order = :sort WHERE id = :id');
    foreach ($rows as $index => $row) {
        $update->execute([
            'sort' => ((int) $index + 1) * 10,
            'id' => (int) $row['id'],
        ]);
    }

    recordSchemaMigration($db, 'event_sort_order_v16');
}

function ensureCarouselSortOrder(PDO $db): void
{
    if (!tableExists($db, 'carousel')) {
        return;
    }

    ensureColumn($db, 'carousel', 'sort_order', 'INT NOT NULL DEFAULT 0');

    if (schemaMigrationApplied($db, 'carousel_sort_order_v17')) {
        return;
    }

    $rows = fetchRows(
        $db,
        'SELECT id FROM carousel ORDER BY carousel_creation_time DESC, id DESC'
    );
    $update = $db->prepare('UPDATE carousel SET sort_order = :sort WHERE id = :id');
    foreach ($rows as $index => $row) {
        $update->execute([
            'sort' => ((int) $index + 1) * 10,
            'id' => (int) $row['id'],
        ]);
    }

    recordSchemaMigration($db, 'carousel_sort_order_v17');
}

function ensureSchoolMessagesSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS school_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role VARCHAR(30) NOT NULL,
            heading VARCHAR(255) DEFAULT NULL,
            person_name VARCHAR(255) DEFAULT NULL,
            photo_path VARCHAR(255) DEFAULT NULL,
            message TEXT,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_message_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    ensureColumn($db, 'school_settings', 'title_messages', "VARCHAR(255) DEFAULT NULL");
    if (tableExists($db, 'school_settings') && columnExists($db, 'school_settings', 'title_messages')) {
        $db->exec(
            "UPDATE school_settings
             SET title_messages = 'সভাপতি ও প্রধান শিক্ষকের বাণী'
             WHERE title_messages IS NULL OR TRIM(title_messages) = ''"
        );
    }

    foreach (schoolLeaderMessageRoles() as $role => $meta) {
        $exists = $db->prepare('SELECT id FROM school_messages WHERE role = :role LIMIT 1');
        $exists->execute(['role' => $role]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $db->prepare(
            'INSERT INTO school_messages (role, heading, message, is_active, sort_order)
             VALUES (:role, :heading, :message, 1, :sort_order)'
        )->execute([
            'role' => $role,
            'heading' => $meta['default_heading'],
            'message' => (string) ($meta['default_message'] ?? ''),
            'sort_order' => (int) $meta['sort_order'],
        ]);
    }

    if (!schemaMigrationApplied($db, 'school_messages_default_copy_v1')) {
        foreach (schoolLeaderMessageRoles() as $role => $meta) {
            $message = trim((string) ($meta['default_message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $db->prepare(
                'UPDATE school_messages
                 SET message = :message
                 WHERE role = :role AND (message IS NULL OR TRIM(message) = \'\')'
            )->execute(['message' => $message, 'role' => $role]);
        }
        recordSchemaMigration($db, 'school_messages_default_copy_v1');
    }
}

function defaultCommitteeRoles(): array
{
    return [
        ['id' => 1, 'label_bn' => 'সভাপতি', 'slug' => 'president', 'sort_order' => 10, 'member_source' => 'manual', 'auto_teacher_designation' => '', 'allow_multiple' => 0],
        ['id' => 2, 'label_bn' => 'সদস্য সচিব', 'slug' => 'member_secretary', 'sort_order' => 20, 'member_source' => 'teacher', 'auto_teacher_designation' => 'head_teacher', 'allow_multiple' => 0],
        ['id' => 3, 'label_bn' => 'প্রতিষ্ঠাতা সদস্য', 'slug' => 'founder_member', 'sort_order' => 30, 'member_source' => 'manual', 'auto_teacher_designation' => '', 'allow_multiple' => 1],
        ['id' => 4, 'label_bn' => 'শিক্ষানুরাগী সদস্য', 'slug' => 'education_enthusiast', 'sort_order' => 40, 'member_source' => 'manual', 'auto_teacher_designation' => '', 'allow_multiple' => 1],
        ['id' => 5, 'label_bn' => 'দাতা সদস্য', 'slug' => 'donor_member', 'sort_order' => 50, 'member_source' => 'manual', 'auto_teacher_designation' => '', 'allow_multiple' => 1],
        ['id' => 6, 'label_bn' => 'অভিভাবক সদস্য', 'slug' => 'guardian_member', 'sort_order' => 60, 'member_source' => 'manual', 'auto_teacher_designation' => '', 'allow_multiple' => 1],
        ['id' => 7, 'label_bn' => 'শিক্ষক প্রতিনিধি', 'slug' => 'teacher_representative', 'sort_order' => 70, 'member_source' => 'teacher', 'auto_teacher_designation' => '', 'allow_multiple' => 1],
    ];
}

function ensureCommitteeSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS comitee_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            label_bn VARCHAR(120) NOT NULL,
            slug VARCHAR(60) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            member_source VARCHAR(20) NOT NULL DEFAULT 'manual',
            auto_teacher_designation VARCHAR(80) DEFAULT NULL,
            allow_multiple TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_comitee_role_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (tableExists($db, 'comitee')) {
        ensureColumn($db, 'comitee', 'teacher_id', 'INT DEFAULT NULL AFTER comitee_picture');
        foreach (['comitee_picture', 'comitee_mobile'] as $nullableColumn) {
            if (!columnExists($db, 'comitee', $nullableColumn)) {
                continue;
            }
            $column = $db->query("SHOW COLUMNS FROM `comitee` LIKE " . $db->quote($nullableColumn))->fetch();
            if (strtoupper((string) ($column['Null'] ?? '')) !== 'YES') {
                $db->exec("ALTER TABLE `comitee` MODIFY `{$nullableColumn}` VARCHAR(255) DEFAULT NULL");
            }
        }
    }

    if (!tableExists($db, 'comitee_roles')) {
        return;
    }

    if ((int) $db->query('SELECT COUNT(*) FROM comitee_roles')->fetchColumn() === 0) {
        $insert = $db->prepare(
            'INSERT INTO comitee_roles
             (id, label_bn, slug, sort_order, is_active, member_source, auto_teacher_designation, allow_multiple)
             VALUES (:id, :label_bn, :slug, :sort_order, 1, :member_source, :auto_teacher_designation, :allow_multiple)'
        );
        foreach (defaultCommitteeRoles() as $role) {
            $insert->execute([
                'id' => $role['id'],
                'label_bn' => $role['label_bn'],
                'slug' => $role['slug'],
                'sort_order' => $role['sort_order'],
                'member_source' => $role['member_source'],
                'auto_teacher_designation' => $role['auto_teacher_designation'] !== '' ? $role['auto_teacher_designation'] : null,
                'allow_multiple' => $role['allow_multiple'],
            ]);
        }
    }
}

function ensureNullableNoticeAttachment(PDO $db): void
{
    $migration = 'nullable_main_notice_file';
    if (schemaMigrationApplied($db, $migration)) {
        return;
    }

    if (tableExists($db, 'main_notice') && columnExists($db, 'main_notice', 'main_notice_file')) {
        $column = $db->query("SHOW COLUMNS FROM `main_notice` LIKE 'main_notice_file'")->fetch();
        $allowsNull = strtoupper((string) ($column['Null'] ?? '')) === 'YES';
        if (!$allowsNull) {
            $db->exec('ALTER TABLE `main_notice` MODIFY `main_notice_file` VARCHAR(255) DEFAULT NULL');
        }
    }

    recordSchemaMigration($db, $migration);
}

function ensureNoticeTextColumn(PDO $db): void
{
    $migration = 'main_notice_text_to_text';
    if (schemaMigrationApplied($db, $migration)) {
        return;
    }

    if (tableExists($db, 'main_notice') && columnExists($db, 'main_notice', 'main_notice_text')) {
        $column = $db->query("SHOW COLUMNS FROM `main_notice` LIKE 'main_notice_text'")->fetch();
        $type = strtolower((string) ($column['Type'] ?? ''));
        if (!str_contains($type, 'text')) {
            $db->exec('ALTER TABLE `main_notice` MODIFY `main_notice_text` TEXT NOT NULL');
        }
    }

    recordSchemaMigration($db, $migration);
}

function ensureEventImagesSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS event_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            image_file VARCHAR(255) NOT NULL,
            caption VARCHAR(255) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_event_images_event (event_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureRoutineSlotIndex(PDO $db): void
{
    if (!tableExists($db, 'class_routine')) {
        return;
    }

    $uniqueSlotIndex = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ' . $db->quote('class_routine') . '
           AND index_name = ' . $db->quote('uniq_routine_slot')
    )->fetchColumn();
    if ($uniqueSlotIndex > 0) {
        $db->exec('ALTER TABLE `class_routine` DROP INDEX `uniq_routine_slot`');
    }
    $slotIndex = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ' . $db->quote('class_routine') . '
           AND index_name = ' . $db->quote('idx_routine_slot')
    )->fetchColumn();
    if ($slotIndex === 0) {
        $db->exec('ALTER TABLE `class_routine` ADD INDEX `idx_routine_slot` (`class_id`, `section_id`, `day_of_week`, `period_no`)');
    }
}

function ensureRoutineSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS class_routine (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            day_of_week TINYINT NOT NULL,
            period_no TINYINT NOT NULL,
            period_time VARCHAR(50) DEFAULT NULL,
            subject_name VARCHAR(255) NOT NULL,
            teacher_mobile VARCHAR(50) DEFAULT NULL,
            room VARCHAR(50) DEFAULT NULL,
            KEY idx_routine_slot (class_id, section_id, day_of_week, period_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS routine_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assembly_label VARCHAR(80) NOT NULL DEFAULT 'সমাবেশ',
            assembly_time VARCHAR(50) NOT NULL DEFAULT '৯:৪০–১০:০০',
            break_label VARCHAR(80) NOT NULL DEFAULT 'বিরতি',
            break_time VARCHAR(50) NOT NULL DEFAULT '১:০৬–০১:৫০',
            session_label VARCHAR(40) DEFAULT NULL,
            footer_note TEXT,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (tableExists($db, 'class_periods')) {
        ensureColumn($db, 'class_periods', 'period_label', 'VARCHAR(50) DEFAULT NULL AFTER period_time');
    }

    if (tableExists($db, 'routine_settings')
        && (int) $db->query('SELECT COUNT(*) FROM routine_settings')->fetchColumn() === 0
    ) {
        $db->exec(
            "INSERT INTO routine_settings
             (assembly_label, assembly_time, break_label, break_time, session_label, is_published)
             VALUES ('সমাবেশ', '৯:৪০–১০:০০', 'বিরতি', '১:০৬–০১:৫০', NULL, 1)"
        );
    }

    if (tableExists($db, 'class_periods') && columnExists($db, 'class_periods', 'period_label')) {
        $update = $db->prepare(
            'UPDATE class_periods
             SET period_label = :label
             WHERE period_no = :no AND (period_label IS NULL OR TRIM(period_label) = \'\')'
        );
        foreach (defaultPeriodLabels() as $periodNo => $label) {
            $update->execute(['label' => $label, 'no' => $periodNo]);
        }
    }

    seedClassPeriods($db);
    if (function_exists('ensureRoutineNavMenu')) {
        ensureRoutineNavMenu($db);
    }
}

function ensureExamRoutineSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS exam_routine (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL,
            class_id INT NOT NULL,
            section_id INT NOT NULL DEFAULT 0,
            exam_date DATE NOT NULL,
            subject_name VARCHAR(255) NOT NULL,
            class_subject_id INT DEFAULT NULL,
            start_time VARCHAR(20) DEFAULT NULL,
            end_time VARCHAR(20) DEFAULT NULL,
            room VARCHAR(80) DEFAULT NULL,
            full_marks VARCHAR(20) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_exam_routine_lookup (exam_id, session_year, class_id, section_id, exam_date),
            UNIQUE KEY uniq_exam_routine_slot (exam_id, session_year, class_id, section_id, exam_date, subject_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Tracks the draft/final workflow for bulk mark entry: a subject teacher can
// temporarily save marks (draft) but they stay hidden from Admin Panel /
// public marksheets until Final Submit sets status = 'submitted'.
function ensureMarkSubmissionSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS mark_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            section_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL DEFAULT '',
            exam_id INT NOT NULL,
            class_subject_id INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            submitted_by VARCHAR(50) DEFAULT NULL,
            submitted_at TIMESTAMP NULL DEFAULT NULL,
            reopened_by VARCHAR(50) DEFAULT NULL,
            reopened_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mark_submission (class_id, section_id, session_year, exam_id, class_subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/** Class-10 archive → SSC candidate batch fields. */
function ensureSscCandidateSchema(PDO $db): void
{
    if (!tableExists($db, 'students_info')) {
        return;
    }

    ensureColumn($db, 'students_info', 'ssc_batch_year', "VARCHAR(20) NOT NULL DEFAULT ''");
    ensureColumn($db, 'students_info', 'ssc_session', "VARCHAR(40) NOT NULL DEFAULT ''");
    ensureColumn($db, 'students_info', 'ssc_reg_no', "VARCHAR(50) NOT NULL DEFAULT ''");
    ensureColumn($db, 'students_info', 'ssc_roll_no', "VARCHAR(50) NOT NULL DEFAULT ''");
    ensureColumn($db, 'students_info', 'ssc_group', "VARCHAR(80) NOT NULL DEFAULT ''");
    ensureColumn($db, 'students_info', 'ssc_result', "VARCHAR(80) NOT NULL DEFAULT ''");
    ensureColumn($db, 'students_info', 'ssc_passing_year', "VARCHAR(20) NOT NULL DEFAULT ''");
    ensureColumn($db, 'students_info', 'ssc_student_type', "VARCHAR(20) NOT NULL DEFAULT ''");
    ensureColumn($db, 'students_info', 'ssc_group_en', "VARCHAR(80) NOT NULL DEFAULT ''");

    if (!schemaMigrationApplied($db, 'ssc_candidate_fields_v25')) {
        recordSchemaMigration($db, 'ssc_candidate_fields_v25');
    }
    if (!schemaMigrationApplied($db, 'ssc_group_en_backfill_v1') && tableExists($db, 'schema_migrations')) {
        $backfill = $db->prepare("UPDATE students_info SET ssc_group_en = :en WHERE id = :id AND ssc_group_en = ''");
        $pending = fetchRows($db, "SELECT id, ssc_group FROM students_info WHERE ssc_group <> '' AND ssc_group_en = ''");
        foreach ($pending as $row) {
            $en = sscGroupEnglish((string) $row['ssc_group']);
            if ($en !== '') {
                $backfill->execute(['en' => $en, 'id' => (int) $row['id']]);
            }
        }
        recordSchemaMigration($db, 'ssc_group_en_backfill_v1');
    }
}

/** Per-session class/section/roll snapshot so promotion never erases placement history. */
function ensureStudentEnrollmentsSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS student_enrollments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            session_year VARCHAR(20) NOT NULL DEFAULT '',
            class_id INT NOT NULL DEFAULT 0,
            section_id INT NOT NULL DEFAULT 0,
            cls_roll VARCHAR(20) NOT NULL DEFAULT '',
            enrollment_status VARCHAR(20) NOT NULL DEFAULT 'active',
            started_on DATE DEFAULT NULL,
            ended_on DATE DEFAULT NULL,
            source VARCHAR(40) NOT NULL DEFAULT '',
            notes VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_session_class (student_id, session_year, class_id, section_id, cls_roll),
            KEY idx_enroll_student (student_id),
            KEY idx_enroll_session (session_year, class_id, enrollment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!schemaMigrationApplied($db, 'student_enrollments_v23') && function_exists('backfillStudentEnrollments')) {
        backfillStudentEnrollments($db);
        recordSchemaMigration($db, 'student_enrollments_v23');
    }
}

function ensureRetirementTeacherSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS retirement_teacher (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_name VARCHAR(255) NOT NULL,
            teacher_designation VARCHAR(80) DEFAULT NULL,
            joining_year SMALLINT NOT NULL,
            retirement_year SMALLINT NOT NULL,
            picture VARCHAR(255) DEFAULT NULL,
            retirement_teacher_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_retirement_year (retirement_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!tableExists($db, 'retirement_teacher')) {
        return;
    }

    ensureColumn($db, 'retirement_teacher', 'teacher_name', 'VARCHAR(255) NOT NULL');
    ensureColumn($db, 'retirement_teacher', 'teacher_designation', 'VARCHAR(80) DEFAULT NULL');
    ensureColumn($db, 'retirement_teacher', 'joining_year', 'SMALLINT NOT NULL');
    ensureColumn($db, 'retirement_teacher', 'retirement_year', 'SMALLINT NOT NULL');
    ensureColumn($db, 'retirement_teacher', 'picture', 'VARCHAR(255) DEFAULT NULL');
    ensureColumn($db, 'retirement_teacher', 'retirement_teacher_status', 'TINYINT(1) NOT NULL DEFAULT 1');
    if (columnExists($db, 'retirement_teacher', 'picture')) {
        $column = $db->query("SHOW COLUMNS FROM `retirement_teacher` LIKE 'picture'")->fetch();
        if (strtoupper((string) ($column['Null'] ?? '')) !== 'YES') {
            $db->exec('ALTER TABLE `retirement_teacher` MODIFY `picture` VARCHAR(255) DEFAULT NULL');
        }
    }
}

function ensureStudentSearchIndexes(PDO $db): void
{
    if (!tableExists($db, 'students_info') || schemaMigrationApplied($db, 'student_search_indexes_v1')) {
        return;
    }

    $wanted = [
        'idx_students_enrolled' => '(`status`, `sections`)',
        'idx_students_mobile' => '(`mobile`)',
        'idx_students_roll' => '(`cls_roll`)',
        'idx_students_session' => '(`session_year`)',
        'idx_students_name_ban' => '(`name_ban`(40))',
        'idx_students_name_eng' => '(`name_eng`(40))',
    ];
    foreach ($wanted as $name => $columns) {
        $exists = (int) $db->query(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ' . $db->quote('students_info') . '
               AND index_name = ' . $db->quote($name)
        )->fetchColumn();
        if ($exists > 0) {
            continue;
        }
        try {
            $db->exec("ALTER TABLE `students_info` ADD INDEX `{$name}` {$columns}");
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
        }
    }

    recordSchemaMigration($db, 'student_search_indexes_v1');
}

function ensureStudentAdmissionTokenIndex(PDO $db): void
{
    if (!tableExists($db, 'students_info') || schemaMigrationApplied($db, 'student_admission_token_index_v1')) {
        return;
    }
    if (columnExists($db, 'students_info', 'admission_token')) {
        $exists = (int) $db->query(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ' . $db->quote('students_info') . '
               AND index_name = ' . $db->quote('idx_students_admission_token')
        )->fetchColumn();
        if ($exists === 0) {
            try {
                $db->exec('ALTER TABLE `students_info` ADD INDEX `idx_students_admission_token` (`admission_token`)');
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
            }
        }
    }
    recordSchemaMigration($db, 'student_admission_token_index_v1');
}

function ensurePublicFeaturesSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS gallery_albums (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            event_name VARCHAR(255) DEFAULT NULL,
            album_year SMALLINT DEFAULT NULL,
            cover_file VARCHAR(255) DEFAULT NULL,
            album_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_gallery_albums_year (album_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS academic_calendar_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            event_date DATE NOT NULL,
            end_date DATE DEFAULT NULL,
            event_type VARCHAR(20) NOT NULL DEFAULT 'event',
            description VARCHAR(500) DEFAULT NULL,
            item_status TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cal_items_date (event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notice_notify_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notice_id INT NOT NULL,
            channel VARCHAR(20) NOT NULL,
            recipient VARCHAR(190) NOT NULL,
            status VARCHAR(40) NOT NULL,
            detail VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_notify_notice (notice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS syllabus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            syllabus_title VARCHAR(255) DEFAULT NULL,
            syllabus_file VARCHAR(255) DEFAULT NULL,
            syllabus_status TINYINT(1) NOT NULL DEFAULT 1,
            syllabus_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (tableExists($db, 'image_gallery')) {
        ensureColumn($db, 'image_gallery', 'album_id', 'INT DEFAULT NULL');
    }
    ensureColumn($db, 'school_settings', 'notify_from_email', 'VARCHAR(120) DEFAULT NULL');
    ensureColumn($db, 'school_settings', 'notify_parent_emails', 'TEXT');
    ensureColumn($db, 'school_settings', 'notify_sms_url', 'VARCHAR(500) DEFAULT NULL');
    ensureColumn($db, 'school_settings', 'sms_provider', "VARCHAR(20) NOT NULL DEFAULT ''");
    ensureColumn($db, 'school_settings', 'sms_api_token', "VARCHAR(255) NOT NULL DEFAULT ''");
    ensureColumn($db, 'school_settings', 'sms_sid', "VARCHAR(80) NOT NULL DEFAULT ''");
    $db->exec(
        "CREATE TABLE IF NOT EXISTS sms_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            context_key VARCHAR(40) NOT NULL DEFAULT '',
            recipient VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL,
            detail VARCHAR(180) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sms_log_context (context_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_id INT DEFAULT NULL,
            actor_name VARCHAR(120) DEFAULT NULL,
            action_key VARCHAR(40) NOT NULL,
            entity_name VARCHAR(40) NOT NULL,
            entity_id INT DEFAULT NULL,
            summary VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_created (created_at),
            KEY idx_audit_entity (entity_name, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    ensureContactMessagesTable($db);
    ensureMeritoriousStudentsSchema($db);
    relabelMeritoriousNav($db);
    dropAlumniFeature($db);

    if (tableExists($db, 'image_gallery') && tableExists($db, 'gallery_albums')
        && (int) $db->query('SELECT COUNT(*) FROM gallery_albums')->fetchColumn() === 0
        && (int) $db->query('SELECT COUNT(*) FROM image_gallery')->fetchColumn() > 0
    ) {
        $db->prepare(
            'INSERT INTO gallery_albums (title, event_name, album_year, album_status) VALUES (:t, :e, :y, 1)'
        )->execute([
            't' => 'সাধারণ অ্যালবাম',
            'e' => 'বিদ্যালয়ের ছবি',
            'y' => (int) date('Y'),
        ]);
        $albumId = (int) $db->lastInsertId();
        if ($albumId > 0) {
            $db->prepare('UPDATE image_gallery SET album_id = :id WHERE album_id IS NULL')->execute(['id' => $albumId]);
        }
    }

}

function ensureMeritoriousStudentsSchema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS meritorious_students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            roll_no VARCHAR(255) DEFAULT '',
            reg_no VARCHAR(255) DEFAULT '',
            session_year VARCHAR(255) DEFAULT '',
            students_name_eng VARCHAR(255) DEFAULT '',
            students_name_ban VARCHAR(255) DEFAULT '',
            fname_eng VARCHAR(255) DEFAULT '',
            fname_ban VARCHAR(255) DEFAULT '',
            mname_eng VARCHAR(255) DEFAULT '',
            mname_ban VARCHAR(255) DEFAULT '',
            section_group VARCHAR(40) DEFAULT '',
            mobile VARCHAR(50) DEFAULT '',
            results VARCHAR(255) DEFAULT '',
            remarks TEXT,
            picture VARCHAR(255) DEFAULT '',
            meritorious_students_status TINYINT(1) NOT NULL DEFAULT 1,
            meritorious_students_creation_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_merit_batch (session_year),
            KEY idx_merit_group (section_group)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    foreach ([
        'roll_no' => "VARCHAR(255) DEFAULT ''",
        'reg_no' => "VARCHAR(255) DEFAULT ''",
        'session_year' => "VARCHAR(255) DEFAULT ''",
        'students_name_eng' => "VARCHAR(255) DEFAULT ''",
        'students_name_ban' => "VARCHAR(255) DEFAULT ''",
        'fname_eng' => "VARCHAR(255) DEFAULT ''",
        'fname_ban' => "VARCHAR(255) DEFAULT ''",
        'mname_eng' => "VARCHAR(255) DEFAULT ''",
        'mname_ban' => "VARCHAR(255) DEFAULT ''",
        'section_group' => "VARCHAR(40) DEFAULT ''",
        'mobile' => "VARCHAR(50) DEFAULT ''",
        'results' => "VARCHAR(255) DEFAULT ''",
        'remarks' => 'TEXT',
        'picture' => "VARCHAR(255) DEFAULT ''",
        'meritorious_students_status' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ] as $column => $definition) {
        ensureColumn($db, 'meritorious_students', $column, $definition);
    }

    if (columnExists($db, 'meritorious_students', 'students_name')) {
        $db->exec(
            "UPDATE meritorious_students
             SET students_name_ban = students_name
             WHERE (students_name_ban IS NULL OR students_name_ban = '')
               AND students_name IS NOT NULL AND students_name <> ''"
        );
    }
    if (columnExists($db, 'meritorious_students', 'fname')) {
        $db->exec(
            "UPDATE meritorious_students
             SET fname_ban = fname
             WHERE (fname_ban IS NULL OR fname_ban = '')
               AND fname IS NOT NULL AND fname <> ''"
        );
    }
}

function relabelMeritoriousNav(PDO $db): void
{
    if (schemaMigrationApplied($db, 'relabel_meritorious_corner_v13')) {
        return;
    }
    if (tableExists($db, 'nav_menus')) {
        $db->exec(
            "UPDATE nav_menus
             SET label_bn = 'কৃতি শিক্ষার্থী', label_en = 'Meritorious'
             WHERE url IN ('meritorious_students', 'meritorious_students.php')"
        );
    }
    if (tableExists($db, 'homepage_info_cards')) {
        $db->exec(
            "UPDATE homepage_info_cards
             SET title = 'কৃতি শিক্ষার্থী'
             WHERE url IN ('meritorious_students', 'meritorious_students.php')
               AND title IN ('প্রাক্তন শিক্ষার্থী', 'Alumni')"
        );
    }
    recordSchemaMigration($db, 'relabel_meritorious_corner_v13');
}

function dropAlumniFeature(PDO $db): void
{
    if (schemaMigrationApplied($db, 'drop_alumni_feature_v14')) {
        return;
    }
    if (tableExists($db, 'alumni')) {
        $db->exec('DROP TABLE alumni');
    }
    $directory = publicSitePath('alumni_file');
    if (is_dir($directory)) {
        foreach (scandir($directory) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_file($path) && $file !== 'index.php') {
                @unlink($path);
            }
        }
    }
    recordSchemaMigration($db, 'drop_alumni_feature_v14');
}
