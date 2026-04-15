<?php
require_once('../../config.php');
require_login();
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$PAGE->set_context(context_course::instance($courseid));
$PAGE->set_url(new moodle_url('/local/riskdetector/debug_nav.php', ['courseid' => $courseid]));
$PAGE->set_pagelayout('standard');
echo $OUTPUT->header();

// Print all settings navigation nodes
$nav = $PAGE->settingsnav;
echo '<pre>';
function print_nodes($node, $depth = 0) {
    echo str_repeat('  ', $depth) . '► key=' . $node->key . ' | text=' . $node->text . ' | type=' . $node->type . "\n";
    foreach ($node->children as $child) {
        print_nodes($child, $depth + 1);
    }
}
print_nodes($nav);
echo '</pre>';
echo $OUTPUT->footer();
