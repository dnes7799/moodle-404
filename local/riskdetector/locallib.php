<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Method 1: Adds plugin link under Course → Reports in the left sidebar.
 * This is the settings navigation hook.
 */
function local_riskdetector_extend_settings_navigation(
    settings_navigation $settingsnav,
    context $context
) {
    global $PAGE;

    // Only inside a course
    if ($context->contextlevel != CONTEXT_COURSE) {
        return;
    }

    // Only for teachers and admins
    if (!has_capability('local/riskdetector:viewdashboard', $context)) {
        return;
    }

    // Find the course admin node
    $coursenode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);
    if (!$coursenode) {
        return;
    }

    // Find the Reports node inside course admin
    $reportsnode = $coursenode->find('coursereports', navigation_node::TYPE_CONTAINER);

    // If reports node found, add inside it — otherwise add directly under course
    $target = $reportsnode ?: $coursenode;

    $target->add(
        'Student Risk Detector',
        new moodle_url('/local/riskdetector/index.php', ['courseid' => $PAGE->course->id]),
        navigation_node::TYPE_SETTING,
        'Student Risk Detector',
        'local_riskdetector',
        new pix_icon('i/warning', 'Student Risk Detector')
    );
}

/**
 * Method 2: Adds plugin to the course navigation tabs at the top.
 * This makes it appear next to Participants, Grades, Reports tabs.
 */
function local_riskdetector_extend_navigation_course(
    navigation_node $parentnode,
    stdClass $course,
    context_course $context
) {
    // Only for teachers and admins
    if (!has_capability('local/riskdetector:viewdashboard', $context)) {
        return;
    }

    // Add under Reports container in top nav
    $reportsnode = $parentnode->find('coursereports', navigation_node::TYPE_CONTAINER);

    if ($reportsnode) {
        // Add inside Reports section
        $reportsnode->add(
            'Student Risk Detector',
            new moodle_url('/local/riskdetector/index.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_riskdetector',
            new pix_icon('i/warning', '')
        );
    } else {
        // Fallback: add as a top-level course nav item
        $parentnode->add(
            'Risk Detector',
            new moodle_url('/local/riskdetector/index.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_riskdetector',
            new pix_icon('i/warning', '')
        );
    }
}