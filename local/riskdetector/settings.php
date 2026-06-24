<?php
// local/riskdetector/settings.php

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage(
        'local_riskdetector',
        get_string('pluginname', 'local_riskdetector')
    );
    $ADMIN->add('localplugins', $settings);

    // Informational note about email delivery.
    $settings->add(new admin_setting_heading(
        'local_riskdetector/emailinfo_heading',
        get_string('emailinfo_heading', 'local_riskdetector'),
        get_string('emailinfo_desc', 'local_riskdetector')
    ));

    // Risk score threshold for flagging at-risk students.
    $settings->add(new admin_setting_configtext(
        'local_riskdetector/risk_threshold',
        get_string('setting_threshold', 'local_riskdetector'),
        get_string('setting_threshold_desc', 'local_riskdetector'),
        40,       // default
        PARAM_INT
    ));

    // Whether to auto-send alerts on each cron run, or require manual trigger.
    $settings->add(new admin_setting_configcheckbox(
        'local_riskdetector/autosend',
        get_string('setting_autosend', 'local_riskdetector'),
        get_string('setting_autosend_desc', 'local_riskdetector'),
        0         // default off
    ));
}