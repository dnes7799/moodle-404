<?php
/**
 * Moodle 4.4 — Cleanup Script (CLI)
 * Deletes ALL assignments (mdl_assign) and quizzes (mdl_quiz)
 * from all 24 courses.
 *
 * Usage:
 *   php admin/cli/cleanup_activities.php
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir  . '/clilib.php');
require_once($CFG->libdir  . '/moodlelib.php');
require_once($CFG->dirroot . '/course/lib.php');

$ALL_COURSE_IDS = [4,6,7,8,9,10,11,12,13,14,15,16,18,19,20,21,22,23,24,25,26,27,28,29];

function log_ok(string $m): void   { cli_writeln("  [DEL]  {$m}"); }
function log_skip(string $m): void { cli_writeln("  [SKIP] {$m}"); }
function log_section(string $t): void {
    cli_writeln('');
    cli_writeln('══════════════════════════════════════════════');
    cli_writeln(" {$t}");
    cli_writeln('══════════════════════════════════════════════');
}

/** Delete a course module and clean up sections */
function delete_cm(int $cmid, int $courseid): void {
    global $DB;

    // Remove from section sequences
    $sections = $DB->get_records('course_sections', ['course' => $courseid]);
    foreach ($sections as $section) {
        if (empty($section->sequence)) continue;
        $seq = array_filter(explode(',', $section->sequence), fn($id) => (int)$id !== $cmid);
        $DB->set_field('course_sections', 'sequence', implode(',', $seq), ['id' => $section->id]);
    }

    // Delete the course module record
    $DB->delete_records('course_modules', ['id' => $cmid]);
}

$assign_module_id = $DB->get_field('modules', 'id', ['name' => 'assign']);
$quiz_module_id   = $DB->get_field('modules', 'id', ['name' => 'quiz']);

$total_assigns = 0;
$total_quizzes = 0;

foreach ($ALL_COURSE_IDS as $cid) {
    $course = $DB->get_record('course', ['id' => $cid]);
    if (!$course) { log_skip("Course id={$cid} not found."); continue; }

    cli_writeln('');
    cli_writeln("  Course: [{$cid}] {$course->shortname}");

    // ── Delete all assignments ────────────────────────────────────────────
    $assigns = $DB->get_records('assign', ['course' => $cid]);
    foreach ($assigns as $assign) {
        // Delete grade items + grades
        $DB->delete_records('grade_grades', [
            'itemid' => $DB->get_field('grade_items', 'id', [
                'itemtype'     => 'mod',
                'itemmodule'   => 'assign',
                'iteminstance' => $assign->id,
                'courseid'     => $cid,
            ])
        ]);
        $DB->delete_records('grade_items', [
            'itemtype'     => 'mod',
            'itemmodule'   => 'assign',
            'iteminstance' => $assign->id,
            'courseid'     => $cid,
        ]);

        // Delete assign submissions + related records
        $DB->delete_records('assignsubmission_file',    ['assignment' => $assign->id]);
        $DB->delete_records('assignsubmission_onlinetext', ['assignment' => $assign->id]);
        $DB->delete_records('assign_submission',        ['assignment' => $assign->id]);
        $DB->delete_records('assign_grades',            ['assignment' => $assign->id]);
        $DB->delete_records('assign_user_flags',        ['assignment' => $assign->id]);
        $DB->delete_records('assign_user_mapping',      ['assignment' => $assign->id]);

        // Delete course module
        $cm = $DB->get_record('course_modules', [
            'course'   => $cid,
            'instance' => $assign->id,
            'module'   => $assign_module_id,
        ]);
        if ($cm) delete_cm($cm->id, $cid);

        // Delete assign record
        $DB->delete_records('assign', ['id' => $assign->id]);

        log_ok("[Assign] '{$assign->name}' deleted.");
        $total_assigns++;
    }

    // ── Delete all quizzes ────────────────────────────────────────────────
    $quizzes = $DB->get_records('quiz', ['course' => $cid]);
    foreach ($quizzes as $quiz) {
        // Delete grade items + grades
        $DB->delete_records('grade_grades', [
            'itemid' => $DB->get_field('grade_items', 'id', [
                'itemtype'     => 'mod',
                'itemmodule'   => 'quiz',
                'iteminstance' => $quiz->id,
                'courseid'     => $cid,
            ])
        ]);
        $DB->delete_records('grade_items', [
            'itemtype'     => 'mod',
            'itemmodule'   => 'quiz',
            'iteminstance' => $quiz->id,
            'courseid'     => $cid,
        ]);

        // Delete quiz attempts and related records
        $DB->delete_records('quiz_attempts',       ['quiz' => $quiz->id]);
        $DB->delete_records('quiz_grades',         ['quiz' => $quiz->id]);
        $DB->delete_records('quiz_sections',       ['quizid' => $quiz->id]);
        $DB->delete_records('quiz_slots',          ['quizid' => $quiz->id]);
        $DB->delete_records('quiz_feedback',       ['quizid' => $quiz->id]);
        $DB->delete_records('quiz_overrides',      ['quiz' => $quiz->id]);

        // Delete course module
        $cm = $DB->get_record('course_modules', [
            'course'   => $cid,
            'instance' => $quiz->id,
            'module'   => $quiz_module_id,
        ]);
        if ($cm) delete_cm($cm->id, $cid);

        // Delete quiz record
        $DB->delete_records('quiz', ['id' => $quiz->id]);

        log_ok("[Quiz]   '{$quiz->name}' deleted.");
        $total_quizzes++;
    }

    // Rebuild course cache after all deletions
    rebuild_course_cache($cid, true);
}

log_section('Cleanup Complete');
cli_writeln("  Assignments deleted : {$total_assigns}");
cli_writeln("  Quizzes deleted     : {$total_quizzes}");
cli_writeln("  Total               : " . ($total_assigns + $total_quizzes));
cli_writeln('');
cli_writeln('  All courses are now clean. Ready for fresh seeding.');
cli_writeln('');