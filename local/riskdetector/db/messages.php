<?php
// local/riskdetector/db/messages.php

defined('MOODLE_INTERNAL') || die();

$messageproviders = [

    // Notification sent to students identified as at-risk.
    'riskalert_student' => [
        'defaults' => [
            'email'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'popup'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'airnotifier' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
        'capability' => 'local/riskdetector:receivealert',
    ],

    // Notification sent to teachers/admins summarising at-risk students in their courses.
    'riskalert_staff' => [
        'defaults' => [
            'email'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'popup'       => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'airnotifier' => MESSAGE_PERMITTED,  
        ],
    ],
];