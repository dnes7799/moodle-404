<?php
// local/riskdetector/notify.php
//
// Dual-mode endpoint:
//   AJAX  (POST with ajax=1): single-student send, returns JSON. Used by the dashboard.
//   PAGE  (GET with courseid only): bulk send for all at-risk students in a course; renders an HTML result page.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

use local_riskdetector\notification\sender;

require_login();
require_sesskey();

$ajax = optional_param('ajax', 0, PARAM_INT);

// ─── AJAX MODE: single-student send ──────────────────────────────────
if ($ajax) {
    header('Content-Type: application/json');

    try {
        $courseid  = required_param('courseid', PARAM_INT);
        $studentid = required_param('studentid', PARAM_INT);
        $subject   = optional_param('subject', '', PARAM_TEXT);
        $custommsg = optional_param('message', '', PARAM_RAW);

        $context = context_course::instance($courseid);
        require_capability('local/riskdetector:managenotifications', $context);

        $course  = get_course($courseid);
        $student = core_user::get_user($studentid);

        if (!$student || $student->deleted || $student->suspended) {
            echo json_encode([
                'sent'   => false,
                'notice' => 'Student not found or unavailable.',
            ]);
            exit;
        }

        // Verify the student is actually enrolled in this course.
        // Stops a teacher in Course A from notifying a student in Course B.
        if (!is_enrolled($context, $student->id, '', true)) {
            echo json_encode([
                'sent'   => false,
                'notice' => 'That student is not enrolled in this course.',
            ]);
            exit;
        }

        global $DB;
        $riskdata = $DB->get_record('local_riskdetector_ml', [
            'course_id'  => $courseid,
            'student_id' => $studentid,
        ]);

        if (!$riskdata) {
            $riskdata = (object)[
                'risk_score'        => 0,
                'risk_band'         => 'Unknown',
                'avg_grade_pct'     => 0,
                'attendance_pct'    => 0,
                'submission_pct'    => 0,
                'days_since_active' => 0,
            ];
        }

        $messageid = sender::send_student_alert(
            $student,
            $course,
            $riskdata,
            $subject !== '' ? $subject : null,
            $custommsg !== '' ? $custommsg : null
        );

        if ($messageid) {
            echo json_encode([
                'sent'    => true,
                'student' => fullname($student),
                'notice'  => 'Notification sent (ID ' . $messageid . ').',
            ]);
        } else {
            echo json_encode([
                'sent'   => false,
                'notice' => 'Send failed. Check the site\'s notification settings.',
            ]);
        }
        exit;

    } catch (\required_capability_exception $e) {
        echo json_encode([
            'sent'   => false,
            'notice' => 'You don\'t have permission to send notifications in this course.',
        ]);
        exit;
    } catch (\moodle_exception $e) {
        echo json_encode([
            'sent'   => false,
            'notice' => 'Error: ' . $e->getMessage(),
        ]);
        exit;
    } catch (\Throwable $e) {
        echo json_encode([
            'sent'   => false,
            'notice' => 'Unexpected error: ' . $e->getMessage(),
        ]);
        exit;
    }
}


// ─── PAGE MODE: bulk send for all at-risk students ───────────────────
$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);
$context  = context_course::instance($courseid);

require_capability('local/riskdetector:managenotifications', $context);

$PAGE->set_url('/local/riskdetector/notify.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_riskdetector'));
$PAGE->set_heading($course->fullname);

global $DB, $OUTPUT;

$threshold = (int)get_config('local_riskdetector', 'risk_threshold') ?: 40;

$sql = "SELECT ml.*
          FROM {local_riskdetector_ml} ml
         WHERE ml.course_id = :courseid
           AND ml.risk_score >= :threshold";

$atrisk = $DB->get_records_sql($sql, [
    'courseid'  => $courseid,
    'threshold' => $threshold,
]);

$sent    = 0;
$failed  = 0;
$skipped = 0;

foreach ($atrisk as $row) {
    $student = core_user::get_user($row->student_id);

    if (!$student || $student->deleted || $student->suspended) {
        $skipped++;
        continue;
    }

    $messageid = sender::send_student_alert($student, $course, $row);

    if ($messageid) {
        $sent++;
    } else {
        $failed++;
    }
}

echo $OUTPUT->header();

$summary = "Notifications sent: {$sent}. Failed: {$failed}. Skipped (deleted/suspended): {$skipped}.";
$type = $failed > 0
    ? \core\output\notification::NOTIFY_WARNING
    : \core\output\notification::NOTIFY_SUCCESS;

echo $OUTPUT->notification($summary, $type);

echo $OUTPUT->continue_button(
    new moodle_url('/local/riskdetector/dashboard.php', ['courseid' => $courseid])
);

echo $OUTPUT->footer();