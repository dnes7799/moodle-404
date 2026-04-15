<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']          = 'Student Risk Detector';
$string['welcomemsg']          = 'Welcome to the Student At-Risk Detector and Notification System.';
$string['adminview']           = 'Admin view — all active courses shown.';
$string['teacherview']         = 'Teacher view — your enrolled courses shown.';
$string['nocourses']           = 'No active courses found for your account.';
$string['opencourse']          = 'Open Dashboard';
$string['configure']           = 'Configure Thresholds';
$string['coursename']          = 'Course name';
$string['coursecode']          = 'Course code';
$string['atriskcount']         = 'At-risk students';
$string['status']              = 'Status';
$string['active']              = 'Active';

// Risk bands
$string['band_low']            = 'Low risk';
$string['band_moderate']       = 'Moderate risk';
$string['band_high']           = 'High risk';
$string['band_critical']       = 'Critical risk';
$string['not_at_risk']         = 'Not at risk';
$string['at_risk']             = 'At risk';

// Dashboard KPIs
$string['totalenrolled']       = 'Total enrolled';
$string['totalrisk']           = 'At-risk students';
$string['riskpercent']         = 'At-risk percentage';
$string['inactivestudents']    = 'Inactive students';
$string['belowassign']         = 'Below assignment threshold';
$string['belowquiz']           = 'Below quiz threshold';

// Threshold settings
$string['thresholdsettings']   = 'Configure Risk Thresholds';
$string['saveconfig']          = 'Save Thresholds';
$string['savedashboard']       = 'Save and Go to Dashboard';
$string['cancel']              = 'Cancel';
$string['configsaved']         = 'Thresholds saved successfully.';

$string['attendance_enabled']   = 'Enable attendance rule';
$string['attendance_threshold'] = 'Attendance below (%)';
$string['attendance_weight']    = 'Weight';
$string['login_enabled']        = 'Enable login inactivity rule';
$string['login_days']           = 'No login for (days)';
$string['login_weight']         = 'Weight';
$string['quiz_enabled']         = 'Enable quiz rule';
$string['quiz_threshold']       = 'Quiz average below (%)';
$string['quiz_weight']          = 'Weight';
$string['assign_enabled']       = 'Enable assignment rule';
$string['assign_threshold']     = 'Assignment average below (%)';
$string['assign_weight']        = 'Weight';
$string['missing_enabled']      = 'Enable missing submissions rule';
$string['missing_threshold']    = 'Missing submissions more than';
$string['missing_weight']       = 'Weight';
$string['email_subject']        = 'Email subject';
$string['email_template']       = 'Email template';

// Table columns
$string['studentname']         = 'Student name';
$string['studentid']           = 'Student ID';
$string['riskstatus']          = 'Risk status';
$string['riskband']            = 'Risk band';
$string['riskscore']           = 'Risk score';
$string['reasons']             = 'Reasons';
$string['lastlogin']           = 'Last login';
$string['attendance']          = 'Attendance';
$string['gradesummary']        = 'Grade summary';
$string['action']              = 'Action';
$string['seedetails']          = 'See details';
$string['notify']              = 'Notify';
$string['noresults']           = 'No students found.';

// Notification
$string['notifystudent']       = 'Notify student';
$string['sendnotification']    = 'Send notification';
$string['notificationsent']    = 'Notification sent successfully.';
$string['notificationfailed']  = 'Failed to send notification.';
$string['close']               = 'Close';

// Default email template
$string['defaulttemplate']     = 'Dear {student_name},

You have been identified as needing additional support in {course_name}.

Current concerns: {risk_reasons}

Please review your course activities and contact your teacher if you need support.

Regards,
{teacher_name}';

$string['defaultsubject']      = 'Academic support notice - {course_name}';

// Warnings
$string['noquizdata']          = 'No quiz items found in this course.';
$string['noassigndata']        = 'No assignment items found in this course.';
$string['noattendancedata']    = 'Attendance data unavailable for this course.';
$string['hiddengradenotice']   = 'Includes grades not yet released to students.';

// Navigation
$string['pluginname_nav']      = 'Risk Detector';
$string['backtocourses']       = 'Back to course list';
$string['backtodashboard']     = 'Back to dashboard';