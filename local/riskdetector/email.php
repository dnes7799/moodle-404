<?php
/**
 * Student Risk Detector — Email Notification
 *
 * Sends email to a student via PHPMailer using plugin SMTP settings.
 * Saves to local_riskdetector_notif only on confirmed delivery.
 */

define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

function send_json(bool $sent, string $status, string $notice, string $student = ''): void {
    header('Content-Type: application/json');
    echo json_encode([
        'sent'    => $sent,
        'status'  => $status,
        'notice'  => $notice,
        'student' => $student,
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
    send_json(false, 'failed', 'You do not have permission to send emails.');
}

global $USER, $DB, $CFG;

$student = $DB->get_record('user', ['id' => $studentid], '*', MUST_EXIST);

// ── Validate student has an email address ─────────────────────────────────
if (empty($student->email) || !validate_email($student->email)) {
    send_json(false, 'failed',
        fullname($student) . ' does not have a valid email address. '
        . 'Update their profile in Moodle before sending.',
        fullname($student));
}

// ── Load plugin SMTP config ───────────────────────────────────────────────
$plugin_defaults = local_riskdetector_get_defaults();

$sender_name   = !empty($plugin_defaults->sender_name)   ? $plugin_defaults->sender_name   : null;
$smtp_host     = !empty($plugin_defaults->smtp_host)      ? $plugin_defaults->smtp_host     : null;
$smtp_port     = !empty($plugin_defaults->smtp_port)      ? (int)$plugin_defaults->smtp_port : 587;
$smtp_username = !empty($plugin_defaults->smtp_username)  ? $plugin_defaults->smtp_username : null;
$smtp_password = !empty($plugin_defaults->smtp_password)
    ? base64_decode($plugin_defaults->smtp_password) : null;

if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
    send_json(false, 'failed',
        'Email SMTP is not configured. Go to Risk Detector → Admin Settings '
        . '→ Email Sending Configuration and fill in all required fields.',
        fullname($student));
}

$from_email = $smtp_username;
$from_name  = $sender_name ?: 'Student Risk Detector';

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
    : 'Recently';

$placeholders = [
    '{student_name}' => fullname($student),
    '{course_name}'  => $course->fullname,
    '{risk_reasons}' => $reasons_str,
    '{last_login}'   => $last_login_str,
    '{teacher_name}' => $from_name,
];

$subject_final = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
$message_final = str_replace(array_keys($placeholders), array_values($placeholders), $message);

// ── Build HTML email body ─────────────────────────────────────────────────
$is_html = (strpos($message_final, '<') !== false && strpos($message_final, '>') !== false);

$message_html = $is_html
    ? $message_final
    : '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.8; '
      . 'color: #2d3748; max-width: 600px; margin: 0 auto; padding: 20px;">'
      . '<div style="border-bottom: 3px solid #6c5ce7; padding-bottom: 16px; margin-bottom: 20px;">'
      . '<h2 style="margin: 0; color: #2d3748; font-size: 18px;">' . htmlspecialchars($subject_final) . '</h2>'
      . '</div>'
      . '<div style="background: #f8f9fc; border-radius: 8px; padding: 20px; margin-bottom: 20px;">'
      . nl2br(htmlspecialchars($message_final))
      . '</div>'
      . '<div style="font-size: 12px; color: #8a92a6; border-top: 1px solid #e2e8f0; padding-top: 12px;">'
      . 'Sent via Student Risk Detector &mdash; ' . htmlspecialchars($course->fullname)
      . '</div></div>';

$message_plain = strip_tags(
    str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $message_final)
);

// ── Send via PHPMailer ────────────────────────────────────────────────────
require_once($CFG->libdir . '/phpmailer/moodle_phpmailer.php');

$mail = new moodle_phpmailer();
$mail->isSMTP();
$mail->Host       = $smtp_host;
$mail->Port       = $smtp_port;
$mail->SMTPAuth   = true;
$mail->Username   = $smtp_username;
$mail->Password   = $smtp_password;
$mail->Timeout    = 10;
$mail->SMTPOptions = ['ssl' => [
    'verify_peer'       => false,
    'verify_peer_name'  => false,
    'allow_self_signed' => true,
]];
$mail->SMTPSecure = ($smtp_port == 465) ? 'ssl' : 'tls';
$mail->CharSet    = 'UTF-8';

$mail->setFrom($from_email, $from_name);
$mail->addAddress($student->email, fullname($student));
$mail->addReplyTo($from_email, $from_name);

$mail->isHTML(true);
$mail->Subject = $subject_final;
$mail->Body    = $message_html;
$mail->AltBody = $message_plain;

$sent      = false;
$error_msg = '';

try {
    $sent = $mail->send();
    if (!$sent) {
        $error_msg = $mail->ErrorInfo;
    }
} catch (\Exception $e) {
    $error_msg = $e->getMessage();
}

if ($sent) {
    // ── Confirmed — save to notification log ──────────────────────────
    $DB->insert_record('local_riskdetector_notif', (object)[
        'courseid'    => $courseid,
        'studentid'   => $studentid,
        'sentby'      => $USER->id,
        'channel'     => 'email',
        'subject'     => $subject_final,
        'messagehtml' => $message_html,
        'timesent'    => time(),
    ]);

    send_json(true, 'delivered',
        'Email sent to ' . fullname($student) . ' (' . $student->email . ').',
        fullname($student));
} else {
    send_json(false, 'failed',
        'Failed to send email to ' . $student->email . '. '
        . 'SMTP error: ' . ($error_msg ?: 'Unknown error') . '. '
        . 'Check your SMTP credentials in Admin Settings.',
        fullname($student));
}