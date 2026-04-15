<?php
/**
 * Student Risk Detector — Moodle Notification (bell icon)
 *
 * Sends an in-platform notification via message_send().
 * Saves to local_riskdetector_notif only on confirmed delivery.
 */

define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

function send_json(bool $sent, string $status, string $notice, string $student = '', $message_id = null): void {
    header('Content-Type: application/json');
    echo json_encode([
        'sent'       => $sent,
        'status'     => $status,
        'notice'     => $notice,
        'student'    => $student,
        'message_id' => $message_id,
    ]);
    exit;
}

require_login();

if (!confirm_sesskey()) {
    send_json(false, 'failed', 'Session expired. Please refresh and try again.');
}

$courseid  = required_param('courseid',  PARAM_INT);
$studentid = required_param('studentid', PARAM_INT);
$subject   = required_param('subject',   PARAM_TEXT);
$message   = required_param('message',   PARAM_RAW);

$course  = get_course($courseid);
$context = context_course::instance($courseid);

if (!has_capability('local/riskdetector:notify', $context)) {
    send_json(false, 'failed', 'You do not have permission to send notifications.');
}

global $USER, $DB;

$student = $DB->get_record('user', ['id' => $studentid], '*', MUST_EXIST);

// ── Resolve template placeholders from ML table ──────────────────────────
$ml_result = $DB->get_record_sql(
    "SELECT ml.risk_score,
            ml.ml_risk_band,
            ml.avg_grade_pct,
            ml.days_since_active,
            ml.attendance_pct,
            ml.submission_pct
       FROM {local_riskdetector_ml} ml
      WHERE ml.course_id = :courseid AND ml.student_id = :studentid",
    ['courseid' => $courseid, 'studentid' => $studentid]
);

$reasons_parts = [];
if ($ml_result) {
    if ($ml_result->avg_grade_pct < 50)    $reasons_parts[] = 'Low grades (' . round($ml_result->avg_grade_pct, 1) . '%)';
    if ($ml_result->attendance_pct < 60)    $reasons_parts[] = 'Low attendance (' . round($ml_result->attendance_pct, 1) . '%)';
    if ($ml_result->submission_pct < 60)    $reasons_parts[] = 'Missing submissions (' . round($ml_result->submission_pct, 1) . '%)';
    if ($ml_result->days_since_active > 14) $reasons_parts[] = 'Inactive for ' . (int)$ml_result->days_since_active . ' days';
}
$reasons_str    = !empty($reasons_parts) ? implode(', ', $reasons_parts) : 'General academic concern';
$last_login_str = ($ml_result && $ml_result->days_since_active > 0)
    ? (int)$ml_result->days_since_active . ' days ago'
    : 'Unknown';

$plugin_defaults = local_riskdetector_get_defaults();
$from_name = !empty($plugin_defaults->sender_name) ? $plugin_defaults->sender_name : 'Student Risk Detector';

$placeholders = [
    '{student_name}' => fullname($student),
    '{course_name}'  => $course->fullname,
    '{risk_reasons}' => $reasons_str,
    '{last_login}'   => $last_login_str,
    '{teacher_name}' => $from_name,
];

$subject_final = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
$message_final = str_replace(array_keys($placeholders), array_values($placeholders), $message);

// ── Ensure messaging is enabled ──────────────────────────────────────────
set_config('messaging', 1);

// ── Force popup-only delivery so Moodle doesn't attempt SMTP ─────────────
set_user_preference('message_provider_moodle_instantmessage_enabled', 'popup', $student);

// ── Build and send ───────────────────────────────────────────────────────
$msg                    = new \core\message\message();
$msg->component         = 'moodle';
$msg->name              = 'instantmessage';
$msg->userfrom          = get_admin();
$msg->userto            = $student;
$msg->subject           = $subject_final;
$msg->fullmessage       = strip_tags(
    str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $message_final)
);
$msg->fullmessageformat = FORMAT_HTML;
$msg->fullmessagehtml   = '<div style="font-family:sans-serif;font-size:14px;'
                         . 'line-height:1.6;color:#2d3748;">'
                         . nl2br(htmlspecialchars($message_final)) . '</div>';
$msg->smallmessage      = $subject_final;
$msg->notification      = 1;
$msg->contexturl        = (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
$msg->contexturlname    = $course->fullname;

$result_id = false;
try {
    $result_id = message_send($msg);
} catch (\Exception $e) {
    send_json(false, 'failed', 'Exception: ' . $e->getMessage(), fullname($student));
}

if (!$result_id || (int)$result_id <= 0) {
    send_json(false, 'failed',
        'Moodle could not deliver the notification. Check that the student account is active.',
        fullname($student));
}

// ── Confirmed delivered — save to log ────────────────────────────────────
$DB->insert_record('local_riskdetector_notif', (object)[
    'courseid'    => $courseid,
    'studentid'   => $studentid,
    'sentby'      => $USER->id,
    'channel'     => 'moodle',
    'subject'     => $subject_final,
    'messagehtml' => $message_final,
    'timesent'    => time(),
]);

send_json(true, 'delivered',
    'Notification delivered to ' . fullname($student) . '. They will see it in their bell icon.',
    fullname($student), $result_id);