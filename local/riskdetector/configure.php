<?php
/**
 * Student Risk Detector — Threshold Configuration Page
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
require_capability('local/riskdetector:configure', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/riskdetector/configure.php', ['courseid' => $courseid]));
$PAGE->set_title('Risk Thresholds — ' . $course->shortname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

global $USER, $DB, $OUTPUT;

$config = local_riskdetector_get_config($courseid);
$saved  = false;

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    $action = optional_param('action', 'save', PARAM_ALPHA);

    $assign_items_raw = optional_param_array('assign_items', [], PARAM_INT);
    $quiz_items_raw   = optional_param_array('quiz_items',   [], PARAM_INT);

    $assign_items_json = json_encode(array_values(array_filter($assign_items_raw)));
    $quiz_items_json   = json_encode(array_values(array_filter($quiz_items_raw)));

    $data = (object)[
        'courseid'             => $courseid,
        'createdby'            => $USER->id,
        'attendance_enabled'   => optional_param('attendance_enabled',   0, PARAM_INT),
        'attendance_threshold' => optional_param('attendance_threshold',  50, PARAM_FLOAT),
        'attendance_weight'    => 15,
        'login_enabled'        => optional_param('login_enabled',         0, PARAM_INT),
        'login_days'           => optional_param('login_days',            14, PARAM_INT),
        'login_weight'         => 20,
        'quiz_enabled'         => optional_param('quiz_enabled',          0, PARAM_INT),
        'quiz_threshold'       => optional_param('quiz_threshold',        50, PARAM_FLOAT),
        'quiz_weight'          => 25,
        'assign_enabled'       => optional_param('assign_enabled',        0, PARAM_INT),
        'assign_threshold'     => optional_param('assign_threshold',      50, PARAM_FLOAT),
        'assign_weight'        => 30,
        'missing_enabled'      => 0,
        'missing_threshold'    => 2,
        'missing_weight'       => 10,
        'email_subject'        => optional_param('email_subject',         '', PARAM_TEXT),
        'email_template'       => optional_param('email_template',        '', PARAM_RAW),
        'assign_items'         => $assign_items_json,
        'quiz_items'           => $quiz_items_json,
        'timemodified'         => time(),
    ];

    $existing = $DB->get_record('local_riskdetector_config', ['courseid' => $courseid]);
    if ($existing) {
        $data->id = $existing->id;
        $DB->update_record('local_riskdetector_config', $data);
    } else {
        $data->timecreated = time();
        $DB->insert_record('local_riskdetector_config', $data);
    }

    // local_riskdetector_calculate_risk($courseid);

    if ($action === 'savedashboard') {
        redirect(new moodle_url('/local/riskdetector/index.php', ['courseid' => $courseid]));
    }

    $saved  = true;
    $config = local_riskdetector_get_config($courseid);
}

// ── Build working config ────────────────────────────────────────────────────
$c = $config ?: (object)[
    'attendance_enabled' => 0, 'attendance_threshold' => 50,
    'login_enabled'      => 1, 'login_days'           => 14,
    'quiz_enabled'       => 1, 'quiz_threshold'       => 50,
    'assign_enabled'     => 1, 'assign_threshold'     => 50,
    'email_subject'      => '', 'email_template'      => '',
    'assign_items'       => null,
    'quiz_items'         => null,
];

// Decode saved selections
$selected_assigns = [];
$selected_quizzes = [];
$assigns_saved    = false;
$quizzes_saved    = false;

if (!empty($c->assign_items)) {
    $d = json_decode($c->assign_items, true);
    if (is_array($d)) { $selected_assigns = $d; $assigns_saved = true; }
}
if (!empty($c->quiz_items)) {
    $d = json_decode($c->quiz_items, true);
    if (is_array($d)) { $selected_quizzes = $d; $quizzes_saved = true; }
}


$raw_assignments = $DB->get_records_sql(
    "SELECT a.id,
            a.name,
            a.grade,
            COALESCE(
                (SELECT ao.duedate
                   FROM {assign_overrides} ao
                  WHERE ao.assignid = a.id
                    AND ao.duedate IS NOT NULL
                    AND ao.userid IS NULL
                    AND ao.groupid IS NULL
                  LIMIT 1),
                a.duedate
            ) AS duedate
       FROM {assign} a
      WHERE a.course = :courseid
      ORDER BY duedate ASC, a.name ASC",
    ['courseid' => $courseid]
);

$assignments    = [];
$seen_a_names   = [];
foreach ($raw_assignments as $a) {
    $key = strtolower(trim($a->name));
    if (!in_array($key, $seen_a_names)) {
        $seen_a_names[] = $key;
        $assignments[]  = $a;
    }
}

// ── Fetch quizzes DIRECTLY from mdl_quiz ──────────────────────────────────
$raw_quizzes = $DB->get_records_sql(
    "SELECT id, name, timeopen, timeclose, grade
       FROM {quiz}
      WHERE course = :courseid
      ORDER BY timeclose ASC, name ASC",
    ['courseid' => $courseid]
);

// Deduplicate by name
$quizzes      = [];
$seen_q_names = [];
foreach ($raw_quizzes as $q) {
    $key = strtolower(trim($q->name));
    if (!in_array($key, $seen_q_names)) {
        $seen_q_names[] = $key;
        $quizzes[]      = $q;
    }
}

$now              = time();
$total_assigns    = count($assignments);
$total_quizzes    = count($quizzes);

// ── Build JS data for popups ──────────────────────────────────────────────
$assign_js = [];
foreach ($assignments as $idx => $a) {
    $sel  = $assigns_saved ? in_array((int)$a->id, $selected_assigns) : true;
    $past = ($a->duedate > 0 && $a->duedate < $now);
    $due  = $a->duedate > 0 ? date('d M Y, g:i A', $a->duedate) : 'No due date';
    $assign_js[] = [
        'id'      => (int)$a->id,
        'name'    => $a->name,
        'due'     => $due,
        'past'    => $past,
        'nodate'  => ($a->duedate == 0),
        'grade'   => $a->grade > 0 ? (float)$a->grade : 0,
        'checked' => $sel,
    ];
}

$quiz_js = [];
foreach ($quizzes as $idx => $q) {
    $sel  = $quizzes_saved ? in_array((int)$q->id, $selected_quizzes) : true;
    $past = ($q->timeclose > 0 && $q->timeclose < $now);
    $due  = $q->timeclose > 0 ? date('d M Y, g:i A', $q->timeclose) : 'No close date';
    $quiz_js[] = [
        'id'      => (int)$q->id,
        'name'    => $q->name,
        'due'     => $due,
        'past'    => $past,
        'nodate'  => ($q->timeclose == 0),
        'grade'   => $q->grade > 0 ? (float)$q->grade : 0,
        'checked' => $sel,
    ];
}

// Count selected
$assign_sel_count = $assigns_saved ? count($selected_assigns) : $total_assigns;
$quiz_sel_count   = $quizzes_saved ? count($selected_quizzes) : $total_quizzes;

$tmpl = local_riskdetector_get_template($courseid);
$back = new moodle_url('/local/riskdetector/index.php');

echo $OUTPUT->header();
?>
<style>
.rd-page{max-width:980px;margin:0 auto;padding:24px 20px 40px}
.rd-breadcrumb{font-size:13px;color:#6c757d;margin-bottom:20px}
.rd-breadcrumb a{color:#6c5ce7;text-decoration:none}
.rd-breadcrumb a:hover{text-decoration:underline}
.rd-page-header{margin-bottom:28px}
.rd-page-header h1{font-size:24px;font-weight:700;color:#1a202c;margin:0 0 6px}
.rd-page-header p{font-size:14px;color:#6c757d;margin:0}
.rd-course-tag{display:inline-block;background:#f0edff;color:#6c5ce7;font-size:13px;font-weight:600;padding:4px 14px;border-radius:20px;margin-top:8px}
.rd-alert-success{background:#f0fff4;border:1.5px solid #c6f6d5;color:#276749;padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:14px;font-weight:500;display:flex;align-items:center;gap:10px}
.rd-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
@media(max-width:720px){.rd-grid{grid-template-columns:1fr}}
.rd-rule-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px 22px;transition:border-color .2s,box-shadow .2s}
.rd-rule-card.enabled{border-color:#6c5ce7;box-shadow:0 2px 12px rgba(108,92,231,.1)}
.rd-rule-header{display:flex;align-items:center;justify-content:space-between}
.rd-rule-title{display:flex;align-items:center;gap:10px}
.rd-rule-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.icon-login{background:#e8f4fd}.icon-quiz{background:#fef3e2}
.icon-assign{background:#e8fdf0}.icon-attend{background:#f0e8fd}
.rd-rule-name{font-size:15px;font-weight:600;color:#2d3748}
.rd-rule-desc{font-size:12px;color:#a0aec0;margin-top:2px}
.rd-toggle{position:relative;width:44px;height:24px;flex-shrink:0}
.rd-toggle input{opacity:0;width:0;height:0}
.rd-toggle-slider{position:absolute;inset:0;background:#cbd5e0;border-radius:24px;cursor:pointer;transition:background .3s}
.rd-toggle-slider:before{content:"";position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:transform .3s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.rd-toggle input:checked + .rd-toggle-slider{background:#6c5ce7}
.rd-toggle input:checked + .rd-toggle-slider:before{transform:translateX(20px)}
.rd-rule-body{display:none;margin-top:16px}
.rd-rule-body.show{display:block}
.rd-rule-body-inner{background:#f8f7ff;border-radius:10px;padding:12px 14px}
.rd-input-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.rd-input-row label{font-size:13px;color:#4a5568;font-weight:500}
.rd-number-input{width:80px;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-weight:600;color:#2d3748;background:#fff;text-align:center;transition:border-color .2s}
.rd-number-input:focus{outline:none;border-color:#6c5ce7}
.rd-unit{font-size:13px;color:#718096;font-weight:500}
.rd-select-btn{display:inline-flex;align-items:center;gap:8px;margin-top:12px;padding:8px 16px;background:#f0edff;color:#6c5ce7;border:1.5px solid #c9c2f7;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
.rd-select-btn:hover{background:#6c5ce7;color:#fff;border-color:#6c5ce7}
.rd-select-btn:hover .rd-badge{background:rgba(255,255,255,0.25);color:#fff}
.rd-badge{background:#6c5ce7;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;transition:all .2s}
.rd-full-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px 22px;margin-bottom:16px}
.rd-full-card.enabled{border-color:#6c5ce7;box-shadow:0 2px 12px rgba(108,92,231,.08)}
.rd-full-card-header{display:flex;align-items:center;justify-content:space-between}
.rd-full-card-body{display:none;margin-top:16px}
.rd-full-card-body.show{display:block}
.rd-email-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:720px){.rd-email-grid{grid-template-columns:1fr}}
.rd-field-label{font-size:13px;font-weight:600;color:#4a5568;margin-bottom:6px;display:block}
.rd-input-full{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;color:#2d3748;transition:border-color .2s;box-sizing:border-box}
.rd-input-full:focus{outline:none;border-color:#6c5ce7}
textarea.rd-input-full{resize:vertical;font-family:inherit;line-height:1.5}
.rd-placeholder-hint{font-size:11px;color:#a0aec0;margin-top:6px;line-height:1.5}
.rd-action-bar{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.rd-action-bar-left{display:flex;gap:10px;flex-wrap:wrap}
.btn-primary{background:#6c5ce7;color:#fff;border:none;padding:10px 24px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-primary:hover{background:#5a4bd1}
.btn-success{background:#00b894;color:#fff;border:none;padding:10px 24px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-success:hover{background:#00a381}
.btn-secondary{background:#f7f7f7;color:#555;border:1.5px solid #e2e8f0;padding:10px 20px;border-radius:9px;font-size:14px;font-weight:600;text-decoration:none;display:inline-block;transition:background .2s}
.btn-secondary:hover{background:#efefef;color:#333;text-decoration:none}

/* ── Popup ── */
.rd-popup-overlay{display:none;position:fixed;inset:0;background:rgba(15,20,40,0.6);z-index:9998;align-items:center;justify-content:center}
.rd-popup-overlay.open{display:flex}
.rd-popup{background:#fff;border-radius:20px;width:94%;max-width:600px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden}
.rd-popup-head{padding:22px 24px 14px;border-bottom:1px solid #edf0f7;display:flex;align-items:flex-start;justify-content:space-between;flex-shrink:0}
.rd-popup-head-left{}
.rd-popup-title{font-size:17px;font-weight:700;color:#1a202c;display:flex;align-items:center;gap:8px}
.rd-popup-title-count{background:#6c5ce7;color:#fff;font-size:12px;font-weight:700;padding:2px 10px;border-radius:10px}
.rd-popup-subtitle{font-size:12px;color:#8a92a6;margin-top:4px}
.rd-popup-close{width:32px;height:32px;border-radius:50%;border:none;background:#f4f6f9;font-size:16px;cursor:pointer;color:#6c757d;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-left:12px}
.rd-popup-close:hover{background:#e2e8f0}
.rd-popup-toolbar{padding:10px 24px;display:flex;align-items:center;justify-content:space-between;background:#fafbff;border-bottom:1px solid #edf0f7;flex-shrink:0}
.rd-popup-count-pill{font-size:12px;font-weight:600;color:#6c5ce7;background:#f0edff;padding:4px 12px;border-radius:10px}
.rd-popup-actions{display:flex;gap:6px}
.rd-tiny-btn{font-size:11px;font-weight:600;padding:4px 12px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;color:#6c757d;cursor:pointer;transition:all .15s}
.rd-tiny-btn:hover{background:#6c5ce7;border-color:#6c5ce7;color:#fff}
.rd-popup-body{overflow-y:auto;padding:14px 24px;flex:1}
.rd-popup-body::-webkit-scrollbar{width:4px}
.rd-popup-body::-webkit-scrollbar-thumb{background:#c9c2f7;border-radius:4px}

/* Item rows */
.rd-item-row{display:flex;align-items:center;background:#fff;border:1.5px solid #edf0f7;border-radius:12px;padding:12px 14px;margin-bottom:8px;transition:all .2s;gap:12px}
.rd-item-row:last-child{margin-bottom:0}
.rd-item-row.included{border-color:#6c5ce7;background:#faf9ff}
.rd-item-serial{width:26px;height:26px;border-radius:50%;background:#edf0f7;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#6c757d;flex-shrink:0;transition:all .2s}
.rd-item-row.included .rd-item-serial{background:#6c5ce7;color:#fff}
.rd-item-info{flex:1;min-width:0}
.rd-item-name{font-size:13px;font-weight:600;color:#2d3748;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rd-item-due{font-size:11px;margin-top:3px;font-weight:500}
.rd-item-due.past{color:#e53e3e}
.rd-item-due.upcoming{color:#276749}
.rd-item-due.nodate{color:#a0aec0}
.rd-item-grade-chip{font-size:11px;font-weight:700;color:#6c5ce7;background:#f0edff;padding:3px 10px;border-radius:8px;white-space:nowrap;flex-shrink:0}
.rd-item-grade-chip.nograde{color:#a0aec0;background:#f4f6f9}
.rd-item-toggle{position:relative;width:38px;height:20px;flex-shrink:0;cursor:pointer}
.rd-item-toggle input{opacity:0;width:0;height:0}
.rd-item-toggle-slider{position:absolute;inset:0;background:#cbd5e0;border-radius:20px;cursor:pointer;transition:background .2s}
.rd-item-toggle-slider:before{content:"";position:absolute;width:14px;height:14px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
.rd-item-toggle input:checked + .rd-item-toggle-slider{background:#6c5ce7}
.rd-item-toggle input:checked + .rd-item-toggle-slider:before{transform:translateX(18px)}
.rd-no-items{font-size:13px;color:#a0aec0;text-align:center;padding:32px 0;font-style:italic}
.rd-popup-foot{padding:14px 24px;border-top:1px solid #edf0f7;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.rd-popup-foot-info{font-size:12px;color:#8a92a6}
.rd-popup-foot-actions{display:flex;gap:8px}
.btn-popup-done{background:#6c5ce7;color:#fff;border:none;padding:9px 24px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-popup-done:hover{background:#5a4bd1}
.btn-popup-cancel{background:#f7f7f7;color:#555;border:1.5px solid #e2e8f0;padding:9px 18px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer}

#assign-hidden-inputs,#quiz-hidden-inputs{display:none}

/* Save overlay */
.rd-save-overlay{display:none;position:fixed;inset:0;background:rgba(255,255,255,0.94);z-index:9999;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.rd-save-overlay.show{display:flex}
.rd-save-overlay h3{font-size:20px;font-weight:700;color:#2d3748;margin:20px 0 8px}
.rd-save-overlay p{font-size:14px;color:#6c757d}
.sv-scene{position:relative;width:100px;height:100px}
.sv-orbit{position:absolute;border-radius:50%;border:2.5px solid transparent;animation:svSpin linear infinite}
.sv-orbit-1{width:100px;height:100px;top:0;left:0;border-top-color:#6c5ce7;border-right-color:#6c5ce7;animation-duration:1.4s}
.sv-orbit-2{width:74px;height:74px;top:13px;left:13px;border-top-color:#00b894;border-left-color:#00b894;animation-duration:1.1s;animation-direction:reverse}
.sv-orbit-3{width:48px;height:48px;top:26px;left:26px;border-bottom-color:#fd79a8;border-right-color:#fd79a8;animation-duration:0.8s}
@keyframes svSpin{to{transform:rotate(360deg)}}
.sv-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:18px;height:18px;border-radius:50%;background:#6c5ce7;animation:svPulse 1.4s ease-in-out infinite}
@keyframes svPulse{0%,100%{transform:translate(-50%,-50%) scale(1);opacity:1}50%{transform:translate(-50%,-50%) scale(1.5);opacity:0.6}}
</style>

<div class="rd-page">
<div class="rd-breadcrumb">
    <a href="<?php echo $back; ?>">Student Risk Detector</a> &rsaquo; Configure Thresholds
</div>
<div class="rd-page-header">
    <h1>Risk Threshold Settings</h1>
    <p>Enable rules and choose which activities count toward risk detection.</p>
    <span class="rd-course-tag"><?php echo s($course->shortname); ?> &mdash; <?php echo s($course->fullname); ?></span>
</div>

<?php if ($saved): ?>
<div class="rd-alert-success">&#10003; Thresholds saved and risk scores recalculated.</div>
<?php endif; ?>

<form method="post" action="" id="configForm">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

<!-- Hidden inputs synced by JS before submit -->
<div id="assign-hidden-inputs">
<?php foreach ($assign_js as $a): if ($a['checked']): ?>
<input type="checkbox" name="assign_items[]" value="<?php echo $a['id']; ?>" checked>
<?php endif; endforeach; ?>
</div>
<div id="quiz-hidden-inputs">
<?php foreach ($quiz_js as $q): if ($q['checked']): ?>
<input type="checkbox" name="quiz_items[]" value="<?php echo $q['id']; ?>" checked>
<?php endif; endforeach; ?>
</div>

<div class="rd-grid">

<!-- ASSIGNMENT -->
<div class="rd-rule-card <?php echo $c->assign_enabled ? 'enabled':''; ?>" id="card_assign">
    <div class="rd-rule-header">
        <div class="rd-rule-title">
            <div class="rd-rule-icon icon-assign">&#128196;</div>
            <div>
                <div class="rd-rule-name">Assignment performance</div>
                <div class="rd-rule-desc">
                    <?php echo $total_assigns; ?> assignment<?php echo $total_assigns != 1 ? 's':''; ?> found in this course
                </div>
            </div>
        </div>
        <label class="rd-toggle">
            <input type="checkbox" name="assign_enabled" value="1"
                <?php echo $c->assign_enabled ? 'checked':''; ?>
                onchange="toggleCard('assign',this.checked)">
            <span class="rd-toggle-slider"></span>
        </label>
    </div>
    <div class="rd-rule-body <?php echo $c->assign_enabled ? 'show':''; ?>" id="body_assign">
        <div class="rd-rule-body-inner">
            <div class="rd-input-row">
                <label>Average below</label>
                <input type="number" name="assign_threshold" class="rd-number-input"
                    value="<?php echo (float)$c->assign_threshold; ?>" min="0" max="100" step="1">
                <span class="rd-unit">% flags at risk</span>
            </div>
        </div>
        <button type="button" class="rd-select-btn" onclick="openPopup('assign')">
            &#128196; Select assignments
            <span class="rd-badge" id="assign-badge"><?php echo $assign_sel_count; ?>/<?php echo $total_assigns; ?></span>
        </button>
    </div>
</div>

<!-- QUIZ -->
<div class="rd-rule-card <?php echo $c->quiz_enabled ? 'enabled':''; ?>" id="card_quiz">
    <div class="rd-rule-header">
        <div class="rd-rule-title">
            <div class="rd-rule-icon icon-quiz">&#9997;</div>
            <div>
                <div class="rd-rule-name">Quiz performance</div>
                <div class="rd-rule-desc">
                    <?php echo $total_quizzes; ?> quiz<?php echo $total_quizzes != 1 ? 'zes':''; ?> found in this course
                </div>
            </div>
        </div>
        <label class="rd-toggle">
            <input type="checkbox" name="quiz_enabled" value="1"
                <?php echo $c->quiz_enabled ? 'checked':''; ?>
                onchange="toggleCard('quiz',this.checked)">
            <span class="rd-toggle-slider"></span>
        </label>
    </div>
    <div class="rd-rule-body <?php echo $c->quiz_enabled ? 'show':''; ?>" id="body_quiz">
        <div class="rd-rule-body-inner">
            <div class="rd-input-row">
                <label>Average below</label>
                <input type="number" name="quiz_threshold" class="rd-number-input"
                    value="<?php echo (float)$c->quiz_threshold; ?>" min="0" max="100" step="1">
                <span class="rd-unit">% flags at risk</span>
            </div>
        </div>
        <button type="button" class="rd-select-btn" onclick="openPopup('quiz')">
            &#9997; Select quizzes
            <span class="rd-badge" id="quiz-badge"><?php echo $quiz_sel_count; ?>/<?php echo $total_quizzes; ?></span>
        </button>
    </div>
</div>

<!-- LOGIN INACTIVITY -->
<div class="rd-rule-card <?php echo $c->login_enabled ? 'enabled':''; ?>" id="card_login">
    <div class="rd-rule-header">
        <div class="rd-rule-title">
            <div class="rd-rule-icon icon-login">&#128274;</div>
            <div>
                <div class="rd-rule-name">Login inactivity</div>
                <div class="rd-rule-desc">Detect students not logging in</div>
            </div>
        </div>
        <label class="rd-toggle">
            <input type="checkbox" name="login_enabled" value="1"
                <?php echo $c->login_enabled ? 'checked':''; ?>
                onchange="toggleCard('login',this.checked)">
            <span class="rd-toggle-slider"></span>
        </label>
    </div>
    <div class="rd-rule-body <?php echo $c->login_enabled ? 'show':''; ?>" id="body_login">
        <div class="rd-rule-body-inner">
            <div class="rd-input-row">
                <label>No login for more than</label>
                <input type="number" name="login_days" class="rd-number-input"
                    value="<?php echo (int)$c->login_days; ?>" min="1" max="90">
                <span class="rd-unit">days</span>
            </div>
        </div>
    </div>
</div>

<!-- ATTENDANCE -->
<div class="rd-rule-card <?php echo $c->attendance_enabled ? 'enabled':''; ?>" id="card_attendance">
    <div class="rd-rule-header">
        <div class="rd-rule-title">
            <div class="rd-rule-icon icon-attend">&#128203;</div>
            <div>
                <div class="rd-rule-name">Attendance</div>
                <div class="rd-rule-desc">Detect low attendance rate</div>
            </div>
        </div>
        <label class="rd-toggle">
            <input type="checkbox" name="attendance_enabled" value="1"
                <?php echo $c->attendance_enabled ? 'checked':''; ?>
                onchange="toggleCard('attendance',this.checked)">
            <span class="rd-toggle-slider"></span>
        </label>
    </div>
    <div class="rd-rule-body <?php echo $c->attendance_enabled ? 'show':''; ?>" id="body_attendance">
        <div class="rd-rule-body-inner">
            <div class="rd-input-row">
                <label>Attendance below</label>
                <input type="number" name="attendance_threshold" class="rd-number-input"
                    value="<?php echo (float)$c->attendance_threshold; ?>" min="0" max="100" step="1">
                <span class="rd-unit">%</span>
            </div>
        </div>
    </div>
</div>

</div><!-- end grid -->

<!-- EMAIL TEMPLATE -->
<div class="rd-full-card enabled" id="card_email">
    <div class="rd-full-card-header">
        <div class="rd-rule-title">
            <div class="rd-rule-icon" style="background:#e8f0fd;">&#9993;</div>
            <div>
                <div class="rd-rule-name">Notification email template</div>
                <div class="rd-rule-desc">Message sent to at-risk students</div>
            </div>
        </div>
        <label class="rd-toggle">
            <input type="checkbox" id="email_toggle" checked
                onchange="toggleFullCard('email',this.checked)">
            <span class="rd-toggle-slider"></span>
        </label>
    </div>
    <div class="rd-full-card-body show" id="body_email">
        <div class="rd-email-grid">
            <div>
                <label class="rd-field-label">Email subject</label>
                <input type="text" name="email_subject" class="rd-input-full"
                    value="<?php echo s($c->email_subject ?: $tmpl['subject']); ?>">
                <p class="rd-placeholder-hint">Placeholders: {student_name} {course_name}</p>
            </div>
            <div>
                <label class="rd-field-label">Message body</label>
                <textarea name="email_template" class="rd-input-full" rows="6"><?php echo s($c->email_template ?: $tmpl['template']); ?></textarea>
                <p class="rd-placeholder-hint">{student_name} {course_name} {risk_reasons} {last_login} {teacher_name}</p>
            </div>
        </div>
    </div>
</div>

<!-- ACTION BAR -->
<div class="rd-action-bar">
    <div class="rd-action-bar-left">
        <button type="submit" name="action" value="save" class="btn-primary" onclick="showSaveLoader()">Save thresholds</button>
        <button type="submit" name="action" value="savedashboard" class="btn-success" onclick="showSaveLoader()">Save &amp; go to dashboard</button>
    </div>
    <a href="<?php echo $back; ?>" class="btn-secondary">Cancel</a>
</div>
</form>
</div>

<!-- ── ASSIGNMENT POPUP ─────────────────────────────────────────────────── -->
<div class="rd-popup-overlay" id="popup-assign">
<div class="rd-popup">
    <div class="rd-popup-head">
        <div class="rd-popup-head-left">
            <div class="rd-popup-title">
                Assignments
                <span class="rd-popup-title-count"><?php echo $total_assigns; ?> total</span>
            </div>
            <div class="rd-popup-subtitle">Toggle on each assignment to include it in risk detection</div>
        </div>
        <button class="rd-popup-close" onclick="closePopup('assign')">&#10005;</button>
    </div>
    <div class="rd-popup-toolbar">
        <span class="rd-popup-count-pill" id="popup-assign-count"></span>
        <div class="rd-popup-actions">
            <button type="button" class="rd-tiny-btn" onclick="popupSelectAll('assign',true)">Select all</button>
            <button type="button" class="rd-tiny-btn" onclick="popupSelectAll('assign',false)">Deselect all</button>
        </div>
    </div>
    <div class="rd-popup-body">
    <?php if (empty($assign_js)): ?>
        <div class="rd-no-items">No assignments found in this course.</div>
    <?php else: foreach ($assign_js as $idx => $a):
        $due_class = $a['nodate'] ? 'nodate' : ($a['past'] ? 'past' : 'upcoming');
        $due_prefix = $a['nodate'] ? '' : ($a['past'] ? '&#9888; Due: ' : '&#9989; Due: ');
        $grade_text = $a['grade'] > 0 ? number_format($a['grade'],0) . ' pts' : 'No grade';
        $grade_chip_class = $a['grade'] > 0 ? '' : 'nograde';
    ?>
    <div class="rd-item-row <?php echo $a['checked'] ? 'included':''; ?>"
         id="prow-assign-<?php echo $a['id']; ?>">
        <span class="rd-item-serial"><?php echo $idx + 1; ?></span>
        <div class="rd-item-info">
            <div class="rd-item-name" title="<?php echo s($a['name']); ?>"><?php echo s($a['name']); ?></div>
            <div class="rd-item-due <?php echo $due_class; ?>">
                <?php echo $due_prefix . $a['due']; ?>
            </div>
        </div>
        <span class="rd-item-grade-chip <?php echo $grade_chip_class; ?>"><?php echo $grade_text; ?></span>
        <label class="rd-item-toggle">
            <input type="checkbox" data-type="assign" data-id="<?php echo $a['id']; ?>"
                <?php echo $a['checked'] ? 'checked':''; ?>
                onchange="onItemToggle('assign',<?php echo $a['id']; ?>,this.checked)">
            <span class="rd-item-toggle-slider"></span>
        </label>
    </div>
    <?php endforeach; endif; ?>
    </div>
    <div class="rd-popup-foot">
        <span class="rd-popup-foot-info" id="popup-assign-foot"></span>
        <div class="rd-popup-foot-actions">
            <button type="button" class="btn-popup-cancel" onclick="closePopup('assign')">Cancel</button>
            <button type="button" class="btn-popup-done" onclick="confirmPopup('assign')">Done</button>
        </div>
    </div>
</div>
</div>

<!-- ── QUIZ POPUP ───────────────────────────────────────────────────────── -->
<div class="rd-popup-overlay" id="popup-quiz">
<div class="rd-popup">
    <div class="rd-popup-head">
        <div class="rd-popup-head-left">
            <div class="rd-popup-title">
                Quizzes
                <span class="rd-popup-title-count"><?php echo $total_quizzes; ?> total</span>
            </div>
            <div class="rd-popup-subtitle">Toggle on each quiz to include it in risk detection</div>
        </div>
        <button class="rd-popup-close" onclick="closePopup('quiz')">&#10005;</button>
    </div>
    <div class="rd-popup-toolbar">
        <span class="rd-popup-count-pill" id="popup-quiz-count"></span>
        <div class="rd-popup-actions">
            <button type="button" class="rd-tiny-btn" onclick="popupSelectAll('quiz',true)">Select all</button>
            <button type="button" class="rd-tiny-btn" onclick="popupSelectAll('quiz',false)">Deselect all</button>
        </div>
    </div>
    <div class="rd-popup-body">
    <?php if (empty($quiz_js)): ?>
        <div class="rd-no-items">No quizzes found in this course.</div>
    <?php else: foreach ($quiz_js as $idx => $q):
        $due_class = $q['nodate'] ? 'nodate' : ($q['past'] ? 'past' : 'upcoming');
        $due_prefix = $q['nodate'] ? '' : ($q['past'] ? '&#9888; Closed: ' : '&#9989; Closes: ');
        $grade_text = $q['grade'] > 0 ? number_format($q['grade'],0) . ' pts' : 'No grade';
        $grade_chip_class = $q['grade'] > 0 ? '' : 'nograde';
    ?>
    <div class="rd-item-row <?php echo $q['checked'] ? 'included':''; ?>"
         id="prow-quiz-<?php echo $q['id']; ?>">
        <span class="rd-item-serial"><?php echo $idx + 1; ?></span>
        <div class="rd-item-info">
            <div class="rd-item-name" title="<?php echo s($q['name']); ?>"><?php echo s($q['name']); ?></div>
            <div class="rd-item-due <?php echo $due_class; ?>">
                <?php echo $due_prefix . $q['due']; ?>
            </div>
        </div>
        <span class="rd-item-grade-chip <?php echo $grade_chip_class; ?>"><?php echo $grade_text; ?></span>
        <label class="rd-item-toggle">
            <input type="checkbox" data-type="quiz" data-id="<?php echo $q['id']; ?>"
                <?php echo $q['checked'] ? 'checked':''; ?>
                onchange="onItemToggle('quiz',<?php echo $q['id']; ?>,this.checked)">
            <span class="rd-item-toggle-slider"></span>
        </label>
    </div>
    <?php endforeach; endif; ?>
    </div>
    <div class="rd-popup-foot">
        <span class="rd-popup-foot-info" id="popup-quiz-foot"></span>
        <div class="rd-popup-foot-actions">
            <button type="button" class="btn-popup-cancel" onclick="closePopup('quiz')">Cancel</button>
            <button type="button" class="btn-popup-done" onclick="confirmPopup('quiz')">Done</button>
        </div>
    </div>
</div>
</div>

<!-- Save overlay -->
<div class="rd-save-overlay" id="saveOverlay">
    <div class="sv-scene">
        <div class="sv-orbit sv-orbit-1"></div>
        <div class="sv-orbit sv-orbit-2"></div>
        <div class="sv-orbit sv-orbit-3"></div>
        <div class="sv-center"></div>
    </div>
    <h3>Saving thresholds&hellip;</h3>
    <p>Recalculating risk scores for all enrolled students.</p>
</div>

<script>
const state = {
    assign: <?php
        $a_state = [];
        foreach ($assign_js as $a) { $a_state[$a['id']] = $a['checked']; }
        echo json_encode($a_state);
    ?>,
    quiz: <?php
        $q_state = [];
        foreach ($quiz_js as $q) { $q_state[$q['id']] = $q['checked']; }
        echo json_encode($q_state);
    ?>,
};
const totals = { assign: <?php echo $total_assigns; ?>, quiz: <?php echo $total_quizzes; ?> };
let snapshot = { assign: {}, quiz: {} };

function toggleCard(name, enabled) {
    document.getElementById('card_' + name).classList.toggle('enabled', enabled);
    document.getElementById('body_' + name).classList.toggle('show', enabled);
}
function toggleFullCard(name, enabled) {
    document.getElementById('card_' + name).classList.toggle('enabled', enabled);
    document.getElementById('body_' + name).classList.toggle('show', enabled);
}

function openPopup(type) {
    snapshot[type] = Object.assign({}, state[type]);
    refreshCount(type);
    document.getElementById('popup-' + type).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closePopup(type) {
    // Revert to snapshot
    Object.assign(state[type], snapshot[type]);
    document.querySelectorAll('[data-type="' + type + '"]').forEach(cb => {
        const id = parseInt(cb.dataset.id);
        cb.checked = !!state[type][id];
        document.getElementById('prow-' + type + '-' + id)?.classList.toggle('included', !!state[type][id]);
    });
    refreshCount(type);
    document.getElementById('popup-' + type).classList.remove('open');
    document.body.style.overflow = '';
}
function confirmPopup(type) {
    syncHiddenInputs(type);
    updateBadge(type);
    document.getElementById('popup-' + type).classList.remove('open');
    document.body.style.overflow = '';
}

function onItemToggle(type, id, checked) {
    state[type][id] = checked;
    document.getElementById('prow-' + type + '-' + id)?.classList.toggle('included', checked);
    refreshCount(type);
}
function popupSelectAll(type, val) {
    document.querySelectorAll('[data-type="' + type + '"]').forEach(cb => {
        cb.checked = val;
        state[type][parseInt(cb.dataset.id)] = val;
        document.getElementById('prow-' + type + '-' + cb.dataset.id)?.classList.toggle('included', val);
    });
    refreshCount(type);
}

function refreshCount(type) {
    const checked = Object.values(state[type]).filter(Boolean).length;
    const total   = totals[type];
    const pill    = document.getElementById('popup-' + type + '-count');
    const foot    = document.getElementById('popup-' + type + '-foot');
    if (pill) pill.textContent = checked + ' of ' + total + ' selected';
    if (foot) foot.textContent = checked + ' item' + (checked != 1 ? 's' : '') + ' will be included in risk calculation';
}
function updateBadge(type) {
    const checked = Object.values(state[type]).filter(Boolean).length;
    const badge   = document.getElementById(type + '-badge');
    if (badge) badge.textContent = checked + '/' + totals[type];
}
function syncHiddenInputs(type) {
    const container = document.getElementById(type + '-hidden-inputs');
    container.innerHTML = '';
    Object.entries(state[type]).forEach(([id, checked]) => {
        if (checked) {
            const inp = document.createElement('input');
            inp.type = 'checkbox'; inp.name = type + '_items[]';
            inp.value = id; inp.checked = true;
            container.appendChild(inp);
        }
    });
}
function showSaveLoader() {
    syncHiddenInputs('assign');
    syncHiddenInputs('quiz');
    document.getElementById('saveOverlay').classList.add('show');
}

// Init
refreshCount('assign');
refreshCount('quiz');
</script>
<?php echo $OUTPUT->footer(); ?>