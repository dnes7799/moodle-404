<?php
/**
 * ============================================================================
 * local_riskdetector — lib.php
 * ============================================================================
 *
 * Two separate tables:
 *   mdl_local_riskdetector_defaults — admin global defaults (no weights)
 *   mdl_local_riskdetector_config   — per-course teacher settings (no weights)
 *
 * Config priority for risk calculation:
 *   1. Course-specific config (teacher saved)
 *   2. Admin defaults
 *   3. Built-in system defaults
 * ============================================================================
 */

defined('MOODLE_INTERNAL') || die();


// ============================================================================
// SECTION 1 — COURSE AND USER HELPERS
// ============================================================================

/**
 * Get all courses a teacher is enrolled in as editingteacher.
 */
function local_riskdetector_get_teacher_courses(int $userid): array {
    global $DB;
    $sql = "SELECT c.id, c.fullname, c.shortname, c.startdate, c.enddate,
                   c.visible, cc.name AS catname
              FROM {course} c
              JOIN {context} ctx ON ctx.instanceid = c.id
                                AND ctx.contextlevel = :ctxlevel
              JOIN {role_assignments} ra ON ra.contextid = ctx.id
                                        AND ra.userid = :userid
              JOIN {role} r ON r.id = ra.roleid
                           AND r.shortname = 'editingteacher'
              JOIN {course_categories} cc ON cc.id = c.category
             WHERE c.id != 1 AND c.visible = 1
          ORDER BY c.fullname ASC";
    return $DB->get_records_sql($sql, [
        'ctxlevel' => CONTEXT_COURSE,
        'userid'   => $userid,
    ]);
}

/**
 * Get all active visible courses — used by admin view.
 */
function local_riskdetector_get_all_courses(): array {
    global $DB;
    $sql = "SELECT c.id, c.fullname, c.shortname, c.startdate, c.enddate,
                   c.visible, cc.name AS catname
              FROM {course} c
              JOIN {course_categories} cc ON cc.id = c.category
             WHERE c.id != 1 AND c.visible = 1
          ORDER BY cc.name, c.fullname ASC";
    return $DB->get_records_sql($sql);
}

/**
 * Count students enrolled in a course.
 */
function local_riskdetector_enrolled_count(int $courseid): int {
    global $DB;
    $sql = "SELECT COUNT(DISTINCT ra.userid)
              FROM {role_assignments} ra
              JOIN {context} ctx ON ctx.id = ra.contextid
                                AND ctx.contextlevel = :ctxlevel
                                AND ctx.instanceid   = :courseid
              JOIN {role} r ON r.id = ra.roleid
                           AND r.shortname = 'student'";
    return (int) $DB->count_records_sql($sql, [
        'ctxlevel' => CONTEXT_COURSE,
        'courseid' => $courseid,
    ]);
}

/**
 * Count at-risk students in a course from results table.
 */
function local_riskdetector_atrisk_count(int $courseid): int {
    global $DB;
    return $DB->count_records_select(
        'local_riskdetector_ml',
        'course_id = :courseid AND risk_score >= 40',
        ['courseid' => $courseid]
    );
}


// ============================================================================
// SECTION 2 — ADMIN DEFAULTS TABLE (mdl_local_riskdetector_defaults)
// ============================================================================

/**
 * Get admin defaults from mdl_local_riskdetector_defaults.
 * Returns null if admin has never saved defaults.
 */
function local_riskdetector_get_defaults(): ?stdClass {
    global $DB;

    if (!$DB->get_manager()->table_exists('local_riskdetector_defaults')) {
        return null;
    }

    // Always get the latest row (should only ever be one)
    $rows = $DB->get_records(
        'local_riskdetector_defaults',
        [],
        'timemodified DESC',
        '*',
        0,
        1
    );

    return !empty($rows) ? reset($rows) : null;
}

/**
 * Save or update admin defaults.
 * Only one row ever exists — overwrites on update.
 */
function local_riskdetector_save_defaults(stdClass $data): void {
    global $DB, $USER;

    if (!$DB->get_manager()->table_exists('local_riskdetector_defaults')) {
        return;
    }

    $existing = local_riskdetector_get_defaults();

    // ── Prepare email fields — trim strings, keep null if truly empty ──────
    $f_sender_name   = trim($data->sender_name   ?? '');
    $f_smtp_host     = trim($data->smtp_host     ?? '');
    $f_smtp_username = trim($data->smtp_username ?? '');
    $f_smtp_port     = (int)($data->smtp_port    ?? 587);

    // Password: only encode if a real value was passed in
    // (admin.php already decoded the stored one if keeping existing)
    $raw_password    = $data->smtp_password ?? '';
    $f_smtp_password = ($raw_password !== '')
        ? base64_encode($raw_password)
        : null;

    $record = (object)[
        'createdby'            => $USER->id,
        'attendance_enabled'   => (int)   ($data->attendance_enabled   ?? 0),
        'attendance_threshold' => (float) ($data->attendance_threshold ?? 50),
        'login_enabled'        => (int)   ($data->login_enabled        ?? 1),
        'login_days'           => (int)   ($data->login_days           ?? 14),
        'quiz_enabled'         => (int)   ($data->quiz_enabled         ?? 1),
        'quiz_threshold'       => (float) ($data->quiz_threshold       ?? 50),
        'assign_enabled'       => (int)   ($data->assign_enabled       ?? 1),
        'assign_threshold'     => (float) ($data->assign_threshold     ?? 50),
        'email_subject'        => $data->email_subject  ?? '',
        'email_template'       => $data->email_template ?? '',
        // ── 5 email config fields ─────────────────────────────────────────
        'sender_name'   => $f_sender_name   !== '' ? $f_sender_name   : null,
        'smtp_host'     => $f_smtp_host     !== '' ? $f_smtp_host     : null,
        'smtp_port'     => $f_smtp_port     >  0   ? $f_smtp_port     : 587,
        'smtp_username' => $f_smtp_username !== '' ? $f_smtp_username : null,
        'smtp_password' => $f_smtp_password,
        'timemodified'  => time(),
    ];

    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('local_riskdetector_defaults', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('local_riskdetector_defaults', $record);
    }
}

/**
 * Apply admin defaults to a single course config row.
 * Creates or overwrites the course's entry in mdl_local_riskdetector_config.
 */
function local_riskdetector_apply_defaults_to_course(int $courseid, stdClass $defaults): void {
    global $DB, $USER;

    $record = (object)[
        'courseid'             => $courseid,
        'createdby'            => $USER->id,
        'attendance_enabled'   => $defaults->attendance_enabled,
        'attendance_threshold' => $defaults->attendance_threshold,
        'login_enabled'        => $defaults->login_enabled,
        'login_days'           => $defaults->login_days,
        'quiz_enabled'         => $defaults->quiz_enabled,
        'quiz_threshold'       => $defaults->quiz_threshold,
        'assign_enabled'       => $defaults->assign_enabled,
        'assign_threshold'     => $defaults->assign_threshold,
        'email_subject'        => $defaults->email_subject,
        'email_template'       => $defaults->email_template,
        'timemodified'         => time(),
    ];

    $existing = $DB->get_record('local_riskdetector_config', ['courseid' => $courseid]);
    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('local_riskdetector_config', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('local_riskdetector_config', $record);
    }
}

/**
 * Apply admin defaults to ALL courses — overwrites every course config.
 *
 * @return int number of courses updated
 */
function local_riskdetector_apply_defaults_to_all(): int {
    global $DB;

    $defaults = local_riskdetector_get_defaults();
    if (!$defaults) {
        return 0;
    }

    $courses = $DB->get_records_select('course', 'id != 1 AND visible = 1', [], '', 'id');
    foreach ($courses as $course) {
        local_riskdetector_apply_defaults_to_course($course->id, $defaults);
    }

    return count($courses);
}

/**
 * Apply admin defaults ONLY to courses that have no config yet.
 *
 * @return int number of courses updated
 */
function local_riskdetector_apply_defaults_to_new(): int {
    global $DB;

    $defaults = local_riskdetector_get_defaults();
    if (!$defaults) {
        return 0;
    }

    $courses = $DB->get_records_select('course', 'id != 1 AND visible = 1', [], '', 'id');
    $count   = 0;

    foreach ($courses as $course) {
        $existing = $DB->get_record('local_riskdetector_config', ['courseid' => $course->id]);
        if (!$existing) {
            local_riskdetector_apply_defaults_to_course($course->id, $defaults);
            $count++;
        }
    }

    return $count;
}


// ============================================================================
// SECTION 3 — COURSE CONFIG HELPERS (mdl_local_riskdetector_config)
// ============================================================================

/**
 * Get course-specific config (teacher saved).
 * Returns NULL if teacher has never saved config for this course.
 * Does NOT fall back to defaults — that is handled in configure.php UI
 * and in get_effective_config().
 */
function local_riskdetector_get_config(int $courseid): ?stdClass {
    global $DB;
    $c = $DB->get_record('local_riskdetector_config', ['courseid' => $courseid]);
    return $c ?: null;
}

/**
 * Get effective config for a course — used by the risk calculation engine.
 *
 * Priority cascade:
 *   1. Course-specific config → teacher has customised this course
 *   2. Admin defaults         → teacher hasn't configured yet, use admin settings
 *   3. System defaults        → nothing set anywhere, use hardcoded values
 */
function local_riskdetector_get_effective_config(int $courseid): stdClass {

    // 1. Course-specific config
    $config = local_riskdetector_get_config($courseid);
    if ($config) {
        return $config;
    }

    // 2. Admin defaults
    $defaults = local_riskdetector_get_defaults();
    if ($defaults) {
        return $defaults;
    }

    // 3. System defaults — hardcoded fallback
    return (object)[
        'attendance_enabled'   => 0,
        'attendance_threshold' => 50,
        'login_enabled'        => 1,
        'login_days'           => 14,
        'quiz_enabled'         => 1,
        'quiz_threshold'       => 50,
        'assign_enabled'       => 1,
        'assign_threshold'     => 50,
        'email_subject'        => 'Academic support notice — {course_name}',
        'email_template'       => "Dear {student_name},\n\nYou have been identified as needing "
                                . "additional support in {course_name}.\n\n"
                                . "Current concerns: {risk_reasons}\n\n"
                                . "Please log in and review your course activities.\n\n"
                                . "Regards,\n{teacher_name}",
    ];
}

/**
 * Get email template for a course — uses effective config cascade.
 */
function local_riskdetector_get_template(int $courseid): array {
    $c = local_riskdetector_get_effective_config($courseid);
    return [
        'subject'  => !empty($c->email_subject)
            ? $c->email_subject
            : 'Academic support notice — {course_name}',
        'template' => !empty($c->email_template)
            ? $c->email_template
            : "Dear {student_name},\n\nYou need support in {course_name}.\n\nRegards,\n{teacher_name}",
    ];
}


// ============================================================================
// SECTION 4 — RISK BAND AND PHASE HELPERS
// ============================================================================

/**
 * Convert numeric risk score (0–100) to a band label.
 *
 *   0–19  = safe
 *   20–39 = low
 *   40–59 = moderate
 *   60–79 = high
 *   80+   = critical
 */
function local_riskdetector_get_band(float $score): string {
    if ($score < 20) return 'safe';
    if ($score < 40) return 'low';
    if ($score < 60) return 'moderate';
    if ($score < 80) return 'high';
    return 'critical';
}

/**
 * Map a risk band to a Bootstrap CSS class.
 */
function local_riskdetector_band_class(string $band): string {
    return [
        'safe'     => 'success',
        'low'      => 'info',
        'moderate' => 'warning',
        'high'     => 'danger',
        'critical' => 'danger',
    ][$band] ?? 'secondary';
}

/**
 * Detect current semester week for a course.
 * Returns 99 if no start date is set (treats as mid-semester).
 */
function local_riskdetector_get_semester_week(int $courseid): int {
    global $DB;

    $startdate = $DB->get_field('course', 'startdate', ['id' => $courseid]);

    if (!$startdate || $startdate == 0) {
        return 99; // No start date — run full model
    }

    $diff = time() - (int)$startdate;

    if ($diff < 0) {
        return 0; // Course hasn't started yet
    }

    return (int) ceil($diff / (7 * 24 * 3600));
}

/**
 * Get phase-adjusted signal weights based on current semester week.
 *
 * Phase 1 (weeks 4–6):  Login + attendance dominate — grades not available yet
 * Phase 2 (weeks 7–10): Balanced — grades starting to matter
 * Phase 3 (weeks 11+):  Full model — grades are primary signal
 *
 * Weights = risk score points added when a signal FAILS.
 */
function local_riskdetector_get_phase_weights(int $week): array {

    if ($week >= 4 && $week <= 6) {
        return [
            'assign'     => 15,  // Low — few items graded yet
            'quiz'       => 10,  // Low — may not have started
            'login'      => 35,  // High — main early engagement signal
            'attendance' => 30,  // High — attendance is trackable early
        ];
    }

    if ($week >= 7 && $week <= 10) {
        return [
            'assign'     => 25,
            'quiz'       => 20,
            'login'      => 25,
            'attendance' => 20,
        ];
    }

    // Phase 3 — week 11+
    return [
        'assign'     => 30,
        'quiz'       => 25,
        'login'      => 20,
        'attendance' => 15,
    ];
}


// ============================================================================
// SECTION 5 — SIGNAL CALCULATORS
// ============================================================================

/**
 * SIGNAL 1: Assignment average for a student.
 *
 * Only counts assignments whose duedate has passed.
 * Unsubmitted = 0 score. Submitted but ungraded = skipped.
 *
 * @param int   $courseid
 * @param int   $userid
 * @return array ['avg' => float, 'total' => int, 'graded' => int, 'missing' => int]
 */
function local_riskdetector_calc_assignment(int $courseid, int $userid): array {
    global $DB;

    $now = time();

    $sql = "SELECT gi.id AS itemid,
                   gi.grademax,
                   a.duedate,
                   gg.finalgrade,
                   sub.status AS sub_status
              FROM {grade_items} gi
              JOIN {assign} a        ON a.id = gi.iteminstance
                                    AND a.course = gi.courseid
         LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id
                                    AND gg.userid = :userid1
         LEFT JOIN {assign_submission} sub
                                    ON sub.assignment = a.id
                                    AND sub.userid = :userid2
                                    AND sub.status = 'submitted'
             WHERE gi.courseid   = :courseid
               AND gi.itemtype   = 'mod'
               AND gi.itemmodule = 'assign'
               AND gi.grademax   > 0
               AND a.duedate     > 0
               AND a.duedate     <= :now";

    $items = $DB->get_records_sql($sql, [
        'userid1'  => $userid,
        'userid2'  => $userid,
        'courseid' => $courseid,
        'now'      => $now,
    ]);

    if (empty($items)) {
        return ['avg' => 0, 'total' => 0, 'graded' => 0, 'missing' => 0];
    }

    $total = count($items);
    $graded = $missing = $sum = 0;

    foreach ($items as $item) {
        if ($item->finalgrade !== null) {
            $sum += $item->grademax > 0
                ? ((float)$item->finalgrade / (float)$item->grademax) * 100
                : 0;
            $graded++;
        } elseif ($item->sub_status !== 'submitted') {
            // Not submitted — counts as 0, marks as missing
            $sum += 0;
            $graded++;
            $missing++;
        }
        // Submitted but not graded → skip entirely
    }

    return [
        'avg'     => $graded > 0 ? round($sum / $graded, 2) : 0,
        'total'   => $total,
        'graded'  => $graded,
        'missing' => $missing,
    ];
}

/**
 * SIGNAL 2: Quiz average for a student.
 *
 * Only counts quizzes whose close time has passed.
 * Never attempted = 0 score. Uses best attempt.
 *
 * @param int $courseid
 * @param int $userid
 * @return array ['avg' => float, 'total' => int, 'attempted' => int, 'missing' => int]
 */
function local_riskdetector_calc_quiz(int $courseid, int $userid): array {
    global $DB;

    $now = time();

    $sql = "SELECT q.id,
                   q.timeclose,
                   MAX(qa.sumgrades) AS best_sumgrades,
                   q.sumgrades       AS quiz_sumgrades
              FROM {quiz} q
         LEFT JOIN {quiz_attempts} qa ON qa.quiz   = q.id
                                     AND qa.userid = :userid
                                     AND qa.state  = 'finished'
             WHERE q.course    = :courseid
               AND q.timeclose > 0
               AND q.timeclose <= :now
          GROUP BY q.id, q.timeclose, q.sumgrades";

    $quizzes = $DB->get_records_sql($sql, [
        'userid'   => $userid,
        'courseid' => $courseid,
        'now'      => $now,
    ]);

    if (empty($quizzes)) {
        return ['avg' => 0, 'total' => 0, 'attempted' => 0, 'missing' => 0];
    }

    $total = count($quizzes);
    $attempted = $missing = $sum = 0;

    foreach ($quizzes as $quiz) {
        if ($quiz->best_sumgrades !== null && $quiz->quiz_sumgrades > 0) {
            $pct = ((float)$quiz->best_sumgrades / (float)$quiz->quiz_sumgrades) * 100;
            $sum += min(100, $pct);
            $attempted++;
        } else {
            $sum += 0;
            $attempted++;
            $missing++;
        }
    }

    return [
        'avg'       => $attempted > 0 ? round($sum / $attempted, 2) : 0,
        'total'     => $total,
        'attempted' => $attempted,
        'missing'   => $missing,
    ];
}

/**
 * SIGNAL 3: Login inactivity.
 *
 * Checks course-specific last access first, falls back to site login.
 * Returns 999 days if never logged in.
 *
 * @param int $courseid
 * @param int $userid
 * @return array ['days_inactive' => float, 'last_access' => int]
 */
function local_riskdetector_calc_login(int $courseid, int $userid): array {
    global $DB;

    $last_access = 0;

    // Course-specific access (most accurate)
    $la = $DB->get_record('user_lastaccess', ['userid' => $userid, 'courseid' => $courseid]);
    if ($la && $la->timeaccess > 0) {
        $last_access = (int)$la->timeaccess;
    } else {
        // Fall back to site-wide last login
        $user = $DB->get_record('user', ['id' => $userid], 'lastlogin');
        if ($user && $user->lastlogin > 0) {
            $last_access = (int)$user->lastlogin;
        }
    }

    $days = $last_access > 0 ? (time() - $last_access) / 86400 : 999;

    return [
        'days_inactive' => round($days, 1),
        'last_access'   => $last_access,
    ];
}

/**
 * SIGNAL 4: Attendance percentage.
 *
 * Requires Moodle Attendance plugin. Returns null if not installed
 * or no data — caller skips the signal entirely when null.
 * P (present) and L (late) both count as attending.
 *
 * @param int $courseid
 * @param int $userid
 * @return array|null  ['pct' => float, 'present' => int, 'total' => int]
 */
function local_riskdetector_calc_attendance(int $courseid, int $userid): ?array {
    global $DB;

    if (!$DB->get_manager()->table_exists('attendance_log')) {
        return null;
    }

    $attendance = $DB->get_record('attendance', ['course' => $courseid]);
    if (!$attendance) {
        return null;
    }

    $statuses = $DB->get_records('attendance_statuses', ['attendanceid' => $attendance->id]);
    if (empty($statuses)) {
        return null;
    }

    $present_ids = [];
    foreach ($statuses as $s) {
        if (in_array(strtoupper(trim($s->acronym)), ['P', 'L'])) {
            $present_ids[] = $s->id;
        }
    }

    $total = (int)$DB->count_records_sql(
        "SELECT COUNT(s.id) FROM {attendance_sessions} s
          WHERE s.attendanceid = :attid AND s.sessdate <= :now",
        ['attid' => $attendance->id, 'now' => time()]
    );

    if ($total === 0) {
        return null;
    }

    $present = 0;
    if (!empty($present_ids)) {
        list($in_sql, $in_params) = $DB->get_in_or_equal($present_ids, SQL_PARAMS_NAMED);
        $present = (int)$DB->count_records_sql(
            "SELECT COUNT(al.id)
               FROM {attendance_log} al
               JOIN {attendance_sessions} s ON s.id = al.sessionid
              WHERE s.attendanceid = :attid
                AND al.studentid  = :userid
                AND s.sessdate    <= :now
                AND al.statusid   $in_sql",
            array_merge([
                'attid'  => $attendance->id,
                'userid' => $userid,
                'now'    => time(),
            ], $in_params)
        );
    }

    return [
        'pct'     => round(($present / $total) * 100, 2),
        'present' => $present,
        'total'   => $total,
    ];
}

/**
 * SIGNAL 5: Late submission penalty.
 *
 * Catches students who submit after the deadline but still get decent grades
 * — they would otherwise appear Safe despite bad submission habits.
 * +5 pts per late submission, capped at 20.
 *
 * @param int $courseid
 * @param int $userid
 * @return array ['penalty' => int, 'late_count' => int]
 */
function local_riskdetector_calc_late_penalty(int $courseid, int $userid): array {
    global $DB;

    $result = $DB->get_record_sql(
        "SELECT COUNT(sub.id) AS late_count
           FROM {assign_submission} sub
           JOIN {assign} a ON a.id = sub.assignment AND a.course = :courseid
          WHERE sub.userid         = :userid
            AND sub.status         = 'submitted'
            AND a.duedate          > 0
            AND a.duedate          <= :now
            AND sub.timemodified   > a.duedate",
        ['courseid' => $courseid, 'userid' => $userid, 'now' => time()]
    );

    $late_count = $result ? (int)$result->late_count : 0;

    return [
        'penalty'    => min(20, $late_count * 5),
        'late_count' => $late_count,
    ];
}

/**
 * Count total missing submissions (display only — not used in scoring).
 */
function local_riskdetector_count_missing(int $courseid, int $userid): int {
    global $DB;

    $now = time();

    $missing_assign = (int)$DB->count_records_sql(
        "SELECT COUNT(a.id)
           FROM {assign} a
      LEFT JOIN {assign_submission} sub ON sub.assignment = a.id
                                      AND sub.userid = :uid
                                      AND sub.status = 'submitted'
          WHERE a.course  = :courseid
            AND a.duedate > 0
            AND a.duedate <= :now
            AND sub.id IS NULL",
        ['uid' => $userid, 'courseid' => $courseid, 'now' => $now]
    );

    $missing_quiz = (int)$DB->count_records_sql(
        "SELECT COUNT(q.id)
           FROM {quiz} q
      LEFT JOIN {quiz_attempts} qa ON qa.quiz   = q.id
                                  AND qa.userid = :uid
                                  AND qa.state  = 'finished'
          WHERE q.course    = :courseid
            AND q.timeclose > 0
            AND q.timeclose <= :now
            AND qa.id IS NULL",
        ['uid' => $userid, 'courseid' => $courseid, 'now' => $now]
    );

    return $missing_assign + $missing_quiz;
}


// ============================================================================
// SECTION 6 — MAIN RISK CALCULATION ENGINE
// ============================================================================

/**
 * Run the full risk detection algorithm for ALL students in a course.
 * Saves/updates results in mdl_local_riskdetector_results.
 *
 * Uses get_effective_config() — so it respects course config → admin
 * defaults → system defaults cascade automatically.
 */
function local_riskdetector_calculate_risk(int $courseid): void {
    global $DB;

    // Load effective config (course → defaults → system)
    $config = local_riskdetector_get_effective_config($courseid);

    // Detect semester week and phase weights
    $week    = local_riskdetector_get_semester_week($courseid);
    $weights = local_riskdetector_get_phase_weights($week);

    // Too early in semester — skip
    if ($week > 0 && $week < 4) {
        return;
    }

    // Get enrolled students
    $context      = context_course::instance($courseid);
    $student_role = $DB->get_record('role', ['shortname' => 'student']);
    if (!$student_role) {
        return;
    }

    $students = get_role_users($student_role->id, $context);
    if (empty($students)) {
        return;
    }

    foreach ($students as $student) {

        $score   = 0;
        $reasons = [];

        // ── Signal 1: Assignment ──────────────────────────────────────────
        $ad = local_riskdetector_calc_assignment($courseid, $student->id);
        if ($config->assign_enabled && $ad['total'] > 0
                && $ad['avg'] < (float)$config->assign_threshold) {
            $score    += $weights['assign'];
            $reasons[] = 'Assignment average ' . $ad['avg']
                       . '% (threshold: ' . $config->assign_threshold . '%)';
        }

        // ── Signal 2: Quiz ────────────────────────────────────────────────
        $qd = local_riskdetector_calc_quiz($courseid, $student->id);
        if ($config->quiz_enabled && $qd['total'] > 0
                && $qd['avg'] < (float)$config->quiz_threshold) {
            $score    += $weights['quiz'];
            $reasons[] = 'Quiz average ' . $qd['avg']
                       . '% (threshold: ' . $config->quiz_threshold . '%)';
        }

        // ── Signal 3: Login ───────────────────────────────────────────────
        $ld = local_riskdetector_calc_login($courseid, $student->id);
        if ($config->login_enabled
                && $ld['days_inactive'] >= (float)$config->login_days) {
            $score    += $weights['login'];
            $reasons[] = $ld['last_access'] === 0
                ? 'Never logged into this course'
                : 'Inactive for ' . round($ld['days_inactive']) . ' days'
                  . ' (threshold: ' . $config->login_days . ' days)';
        }

        // ── Signal 4: Attendance ──────────────────────────────────────────
        $attd    = local_riskdetector_calc_attendance($courseid, $student->id);
        $att_pct = 0;
        if ($attd !== null) {
            $att_pct = $attd['pct'];
            if ($config->attendance_enabled
                    && $att_pct < (float)$config->attendance_threshold) {
                $score    += $weights['attendance'];
                $reasons[] = 'Attendance ' . $att_pct
                           . '% (threshold: ' . $config->attendance_threshold . '%)';
            }
        }

        // ── Signal 5: Late penalty ────────────────────────────────────────
        $late = local_riskdetector_calc_late_penalty($courseid, $student->id);
        if ($late['penalty'] > 0) {
            $score    += $late['penalty'];
            $reasons[] = $late['late_count'] . ' late submission(s) detected'
                       . ' (+' . $late['penalty'] . ' penalty pts)';
        }

        // ── Missing count — display only ──────────────────────────────────
        $missing_count = local_riskdetector_count_missing($courseid, $student->id);

        // ── Finalise ──────────────────────────────────────────────────────
        $score     = min(100, (float)$score);
        $band      = local_riskdetector_get_band($score);
        $is_atrisk = ($score >= 40) ? 1 : 0;

        $record = (object)[
            'courseid'       => $courseid,
            'userid'         => $student->id,
            'risk_score'     => round($score, 2),
            'risk_band'      => $band,
            'is_atrisk'      => $is_atrisk,
            'reasons'        => json_encode($reasons),
            'last_login'     => $ld['last_access'],
            'attendance_pct' => $att_pct,
            'quiz_avg'       => $qd['avg'],
            'assign_avg'     => $ad['avg'],
            'missing_count'  => $missing_count,
            'timecalculated' => time(),
        ];

        $existing = $DB->get_record('local_riskdetector_results', [
            'courseid' => $courseid,
            'userid'   => $student->id,
        ]);

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_riskdetector_results', $record);
        } else {
            $DB->insert_record('local_riskdetector_results', $record);
        }
    }
}


// ============================================================================
// SECTION 7 — RESULTS AND NOTIFICATIONS
// ============================================================================

/**
 * Get all risk results for a course, highest risk first.
 */
function local_riskdetector_get_results(int $courseid): array {
    global $DB;

    $sql = "SELECT ml.id,
                   ml.course_id AS courseid,
                   ml.student_id AS userid,
                   ml.risk_score,
                   ml.ml_risk_band AS risk_band,
                   ml.ml_confidence,
                   ml.avg_grade_pct,
                   ml.days_since_active,
                   ml.attendance_pct,
                   ml.submission_pct,
                   ml.predicted_at AS timecalculated,
                   CASE WHEN ml.risk_score >= 40 THEN 1 ELSE 0 END AS is_atrisk,
                   u.firstname, u.lastname, u.email, u.idnumber
              FROM {local_riskdetector_ml} ml
              JOIN {user} u ON u.id = ml.student_id
             WHERE ml.course_id = :courseid
          ORDER BY ml.risk_score DESC";

    return $DB->get_records_sql($sql, ['courseid' => $courseid]);
}
/**
 * Get notification history for a student in a course.
 */
function local_riskdetector_get_notifications(int $courseid, int $studentid): array {
    global $DB;

    return $DB->get_records(
        'local_riskdetector_notif',
        ['courseid' => $courseid, 'studentid' => $studentid],
        'timesent DESC'
    );
}


// ============================================================================
// SECTION 8 — NAVIGATION HOOKS
// Must be in lib.php — Moodle auto-loads this file for navigation hooks.
// ============================================================================

/**
 * Adds "Student Risk Detector" link to the course Reports section
 * in the secondary navigation (visible to admins and editingteachers only).
 */
function local_riskdetector_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    global $USER, $DB;

    if (is_siteadmin($USER->id)) {
        $show = true;
    } else {
        $sql = "SELECT ra.id
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid        = :userid
                   AND r.shortname      = 'editingteacher'
                   AND ctx.instanceid   = :courseid
                   AND ctx.contextlevel = :ctxlevel";

        $show = $DB->record_exists_sql($sql, [
            'userid'   => $USER->id,
            'courseid' => $course->id,
            'ctxlevel' => CONTEXT_COURSE,
        ]);
    }

    if (!$show) {
        return;
    }

    $target = $navigation->find('reports', navigation_node::TYPE_CONTAINER) ?: $navigation;

    $target->add(
        'Student Risk Detector',
        new moodle_url('/local/riskdetector/index.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_riskdetector',
        new pix_icon('i/warning', '')
    );
}

/**
 * Adds "Risk Detector" to the flat left navigation for site admins only.
 */
function local_riskdetector_extend_navigation(global_navigation $nav): void {
    global $USER;

    if (!is_siteadmin($USER->id)) {
        return;
    }

    $node = $nav->add(
        'Risk Detector',
        new moodle_url('/local/riskdetector/index.php'),
        navigation_node::TYPE_CUSTOM,
        'Risk Detector',
        'local_riskdetector'
    );

    if ($node) {
        $node->showinflatnavigation = true;
        $node->isexpandable         = false;
    }
}