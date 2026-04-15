<?php
// Load Moodle
require_once('../../config.php');
// Load the lib.php with all the functions
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

// Call the function for course ID 4
$results = local_riskdetector_get_results(4);

// Print it to the browser
echo '<pre>';
print_r($results);
echo '</pre>';
