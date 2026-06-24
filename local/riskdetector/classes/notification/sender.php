<?php
// local/riskdetector/classes/notification/sender.php

namespace local_riskdetector\notification;

defined('MOODLE_INTERNAL') || die();

use core_user;
use core\message\message;
use moodle_url;
use stdClass;

/**
 * Builds and dispatches at-risk notifications through Moodle's messaging API.
 */
class sender {

    /**
     * Send a risk alert to a single student.
     *
     * @param stdClass    $student        Moodle user record
     * @param stdClass    $course         Moodle course record
     * @param stdClass    $riskdata       ML row with risk_score, risk_band, and feature columns
     * @param string|null $customsubject  Optional override; supports placeholders
     * @param string|null $custombody     Optional override; supports placeholders
     * @return int|false                  Message ID on success, false on failure
     */
    public static function send_student_alert(
        stdClass $student,
        stdClass $course,
        stdClass $riskdata,
        ?string $customsubject = null,
        ?string $custombody = null
    ) {

        $reasons = self::build_reasons($riskdata);

        $a = (object)[
            'firstname' => $student->firstname,
            'course'    => format_string($course->fullname, true, ['context' => \context_system::instance()]),
        ];

        // Build placeholder map once and reuse for both subject and body.
        $placeholders = self::build_placeholders($student, $course, $riskdata, $reasons);

        // Subject: custom (with placeholders substituted) OR templated default.
        if ($customsubject !== null && trim($customsubject) !== '') {
            $subject = self::apply_placeholders(trim($customsubject), $placeholders);
        } else {
            $subject = get_string('alert_subject', 'local_riskdetector', $a);
        }

        // Body: custom (with placeholders substituted) OR templated default.
        if ($custombody !== null && trim($custombody) !== '') {
            $plaintext = self::apply_placeholders(trim($custombody), $placeholders);
            $html      = nl2br(s($plaintext));
        } else {
            $plaintext = self::render_plaintext($a, $reasons);
            $html      = self::render_html($a, $reasons);
        }

        $message = new message();
        $message->component         = 'local_riskdetector';
        $message->name              = 'riskalert_student';
        $message->userfrom          = core_user::get_noreply_user();
        $message->userto            = $student;
        $message->subject           = $subject;
        $message->fullmessage       = $plaintext;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml   = $html;
        $message->smallmessage      = get_string('alert_smallmessage', 'local_riskdetector', $a->course);
        $message->notification      = 1;
        $message->contexturl        = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $message->contexturlname    = $a->course;
        $message->courseid          = $course->id;

        $messageid = message_send($message);

        if ($messageid) {
            self::log_notification($student->id, $course->id, $subject, $html, $messageid);
            return $messageid;
        }

        return false;
    }

    /**
     * Build the placeholder substitution map.
     *
     * Supports common variations (snake_case, camelCase, with/without underscores)
     * because templates written by different team members tend to vary.
     *
     * @param stdClass $student
     * @param stdClass $course
     * @param stdClass $riskdata
     * @param string[] $reasons
     * @return array<string,string>  Map of placeholder => value, keys are case-insensitive
     */
    protected static function build_placeholders(stdClass $student, stdClass $course, stdClass $riskdata, array $reasons): array {

        $fullname    = trim(($student->firstname ?? '') . ' ' . ($student->lastname ?? ''));
        $coursename  = format_string($course->fullname, true, ['context' => \context_system::instance()]);
        $reasonsList = '- ' . implode("\n- ", $reasons);

        $lastlogin = '';
        if (!empty($student->lastaccess)) {
            $lastlogin = userdate($student->lastaccess, get_string('strftimedaydate', 'core_langconfig'));
        } else if (!empty($student->lastlogin)) {
            $lastlogin = userdate($student->lastlogin, get_string('strftimedaydate', 'core_langconfig'));
        } else {
            $lastlogin = 'Never';
        }

        $score = isset($riskdata->risk_score) ? round((float)$riskdata->risk_score, 1) : '';
        $band  = isset($riskdata->risk_band) ? ucfirst(strtolower((string)$riskdata->risk_band)) : '';

        // Each value is keyed by every reasonable variation users might type.
        $map = [
            // Student name variants
            'student_name'  => $fullname,
            'studentname'   => $fullname,
            'firstname'     => $student->firstname ?? '',
            'first_name'    => $student->firstname ?? '',
            'lastname'      => $student->lastname ?? '',
            'last_name'     => $student->lastname ?? '',
            'fullname'      => $fullname,
            'name'          => $fullname,

            // Course variants
            'course_name'   => $coursename,
            'coursename'    => $coursename,
            'course'        => $coursename,

            // Risk variants
            'risk_reasons'  => $reasonsList,
            'reasons'       => $reasonsList,
            'risk_score'    => (string)$score,
            'score'         => (string)$score,
            'risk_band'     => $band,
            'band'          => $band,

            // Activity variants
            'last_login'    => $lastlogin,
            'lastlogin'     => $lastlogin,
            'days_inactive' => isset($riskdata->days_since_active) ? (string)(int)$riskdata->days_since_active : '',
            'days_since_active' => isset($riskdata->days_since_active) ? (string)(int)$riskdata->days_since_active : '',

            // Metric variants
            'avg_grade'     => isset($riskdata->avg_grade_pct) ? round((float)$riskdata->avg_grade_pct, 1) . '%' : '',
            'avg_grade_pct' => isset($riskdata->avg_grade_pct) ? round((float)$riskdata->avg_grade_pct, 1) . '%' : '',
            'attendance'    => isset($riskdata->attendance_pct) ? round((float)$riskdata->attendance_pct, 1) . '%' : '',
            'attendance_pct'=> isset($riskdata->attendance_pct) ? round((float)$riskdata->attendance_pct, 1) . '%' : '',
            'submission'    => isset($riskdata->submission_pct) ? round((float)$riskdata->submission_pct, 1) . '%' : '',
            'submission_pct'=> isset($riskdata->submission_pct) ? round((float)$riskdata->submission_pct, 1) . '%' : '',
        ];

        return $map;
    }

    /**
     * Substitute {placeholder} tokens in a template string.
     *
     * Matching is case-insensitive: {Student_Name}, {STUDENT_NAME}, {student_name}
     * all resolve the same way.
     *
     * @param string $template
     * @param array<string,string> $placeholders
     * @return string
     */
    protected static function apply_placeholders(string $template, array $placeholders): string {
        return preg_replace_callback(
            '/\{([a-zA-Z_]+)\}/',
            function($matches) use ($placeholders) {
                $key = strtolower($matches[1]);
                return array_key_exists($key, $placeholders) ? $placeholders[$key] : $matches[0];
            },
            $template
        );
    }

    /**
     * Turn ML feature values into human-readable reasons.
     */
    protected static function build_reasons(stdClass $riskdata): array {
        $reasons = [];

        if (isset($riskdata->avg_grade_pct) && $riskdata->avg_grade_pct < 50) {
            $reasons[] = "Your average grade is " . round($riskdata->avg_grade_pct, 1) . "% — below the passing threshold.";
        }
        if (isset($riskdata->attendance_pct) && $riskdata->attendance_pct < 70) {
            $reasons[] = "Your attendance is at " . round($riskdata->attendance_pct, 1) . "%.";
        }
        if (isset($riskdata->submission_pct) && $riskdata->submission_pct < 60) {
            $reasons[] = "You have submitted " . round($riskdata->submission_pct, 1) . "% of required assignments.";
        }
        if (isset($riskdata->days_since_active) && $riskdata->days_since_active > 7) {
            $reasons[] = "You haven't logged in for " . (int)$riskdata->days_since_active . " days.";
        }

        if (empty($reasons)) {
            $reasons[] = "Your overall engagement in the course appears to be low.";
        }

        return $reasons;
    }

    protected static function render_plaintext(stdClass $a, array $reasons): string {
        $lines = [];
        $lines[] = get_string('alert_greeting', 'local_riskdetector', $a);
        $lines[] = '';
        $lines[] = get_string('alert_intro', 'local_riskdetector', $a);
        $lines[] = '';
        $lines[] = get_string('alert_reasons_heading', 'local_riskdetector');
        foreach ($reasons as $r) {
            $lines[] = '  - ' . $r;
        }
        $lines[] = '';
        $lines[] = get_string('alert_nextsteps_heading', 'local_riskdetector');
        $lines[] = get_string('alert_nextsteps', 'local_riskdetector');
        $lines[] = '';
        $lines[] = get_string('alert_footer', 'local_riskdetector');

        return implode("\n", $lines);
    }

    protected static function render_html(stdClass $a, array $reasons): string {
        $html  = '<p>' . s(get_string('alert_greeting', 'local_riskdetector', $a)) . '</p>';
        $html .= '<p>' . s(get_string('alert_intro', 'local_riskdetector', $a)) . '</p>';
        $html .= '<p><strong>' . s(get_string('alert_reasons_heading', 'local_riskdetector')) . '</strong></p>';
        $html .= '<ul>';
        foreach ($reasons as $r) {
            $html .= '<li>' . s($r) . '</li>';
        }
        $html .= '</ul>';
        $html .= '<p><strong>' . s(get_string('alert_nextsteps_heading', 'local_riskdetector')) . '</strong><br>';
        $html .= s(get_string('alert_nextsteps', 'local_riskdetector')) . '</p>';
        $html .= '<hr>';
        $html .= '<p><small>' . s(get_string('alert_footer', 'local_riskdetector')) . '</small></p>';

        return $html;
    }

    protected static function log_notification(int $userid, int $courseid, string $subject, string $messagehtml, int $messageid): void {
        global $DB, $USER;

        $record = new stdClass();
        $record->courseid    = $courseid;
        $record->studentid   = $userid;
        $record->sentby      = isset($USER->id) ? (int)$USER->id : 0;
        $record->subject     = $subject;
        $record->messagehtml = $messagehtml;
        $record->timesent    = time();
        $record->messageid   = $messageid;

        $DB->insert_record('local_riskdetector_notif', $record);
    }
}