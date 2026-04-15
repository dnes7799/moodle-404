<?php
/**
 * Student Risk Detector — Smart Entry Point
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

require_login();

global $USER, $DB, $OUTPUT;

// Block students and guests from accessing this plugin entirely
if (!is_siteadmin($USER->id)) {
    $syscontext = context_system::instance();
    // Check all courses — if user ONLY has student role anywhere, block
    $non_student_roles = $DB->get_records_sql(
        "SELECT DISTINCT r.shortname
           FROM {role_assignments} ra
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.userid = :userid
            AND r.shortname IN ('editingteacher','teacher','manager')",
        ['userid' => $USER->id]
    );
    if (empty($non_student_roles)) {
        redirect(new moodle_url('/'), 'Access denied.', null, \core\output\notification::NOTIFY_ERROR);
    }
}

$courseid = optional_param('courseid', 0, PARAM_INT);

// ── No courseid — show full course list ───────────────────────────────────────
if (!$courseid) {
    $context  = context_system::instance();
    $PAGE->set_context($context);
    $PAGE->set_url(new moodle_url('/local/riskdetector/index.php'));
    $PAGE->set_title(get_string('pluginname', 'local_riskdetector'));
    $PAGE->set_heading(get_string('pluginname', 'local_riskdetector'));
    $PAGE->set_pagelayout('standard');

    $is_admin = is_siteadmin($USER->id);
    $courses  = $is_admin
        ? local_riskdetector_get_all_courses()
        : local_riskdetector_get_teacher_courses($USER->id);

    $admin_url = new moodle_url('/local/riskdetector/admin.php');

    echo $OUTPUT->header();
    ?>
    <style>
    .riskdetector-wrap{max-width:1100px;margin:0 auto;padding:20px}
    .riskdetector-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
    .riskdetector-header h2{margin:0;font-size:24px;font-weight:600}
    .riskdetector-header-right{display:flex;align-items:center;gap:10px}
    .riskdetector-badge{background:#6c5ce7;color:#fff;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}
    .btn-admin-settings{display:inline-flex;align-items:center;gap:6px;background:#1a1a2e;color:#fff;padding:8px 16px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;transition:background .2s}
    .btn-admin-settings:hover{background:#2d2d4e;color:#fff;text-decoration:none}
    .course-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px}
    .course-card{background:#fff;border:1px solid #e0e0e0;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);transition:box-shadow .2s}
    .course-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.12)}
    .course-card-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}
    .course-shortname{font-size:12px;color:#6c757d;font-weight:600;text-transform:uppercase}
    .course-fullname{font-size:16px;font-weight:600;color:#2d3748;margin:4px 0 8px;line-height:1.3}
    .course-category{font-size:12px;color:#6c757d;margin-bottom:14px}
    .course-stats{display:flex;gap:16px;margin-bottom:16px}
    .stat-item{text-align:center}
    .stat-value{font-size:22px;font-weight:700;color:#2d3748}
    .stat-value.risk{color:#e53e3e}
    .stat-label{font-size:11px;color:#6c757d;text-transform:uppercase}
    .course-actions{display:flex;gap:8px}
    .btn-open{background:#6c5ce7;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:inline-block}
    .btn-open:hover{background:#5a4bd1;color:#fff;text-decoration:none}
    .btn-config{background:#f0f0f0;color:#444;border:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:inline-block}
    .btn-config:hover{background:#e0e0e0;color:#444;text-decoration:none}
    .risk-pill{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
    .risk-pill.has-risk{background:#fff5f5;color:#e53e3e;border:1px solid #fed7d7}
    .risk-pill.no-risk{background:#f0fff4;color:#276749;border:1px solid #c6f6d5}
    .no-courses{text-align:center;padding:60px 20px;color:#6c757d}
    </style>

    <div class="riskdetector-wrap">
        <div class="riskdetector-header">
            <div>
            </div>
            <div class="riskdetector-header-right">
                <span class="riskdetector-badge"><?php echo $is_admin ? 'Admin View' : 'Teacher View'; ?></span>
                <?php if ($is_admin): ?>
                <a href="<?php echo $admin_url; ?>" class="btn-admin-settings">
                    &#9881; Admin default settings
                </a>
                <?php endif; ?>
            </div>
        </div>

    <?php if (empty($courses)): ?>
        <div class="no-courses">
            <h3>No courses found</h3>
            <p>No active courses found for your account.</p>
        </div>
    <?php else: ?>
        <div class="course-grid">
        <?php foreach ($courses as $course):
            $enrolled  = local_riskdetector_enrolled_count($course->id);
            $atrisk    = local_riskdetector_atrisk_count($course->id);
            $dash_url  = new moodle_url('/local/riskdetector/index.php',     ['courseid' => $course->id]);
            $conf_url  = new moodle_url('/local/riskdetector/configure.php', ['courseid' => $course->id]);
            $risk_pill = $atrisk > 0
                ? '<span class="risk-pill has-risk">' . $atrisk . ' at risk</span>'
                : '<span class="risk-pill no-risk">0 at risk</span>';
        ?>
            <div class="course-card">
                <div class="course-card-header">
                    <span class="course-shortname"><?php echo s($course->shortname); ?></span>
                    <?php echo $risk_pill; ?>
                </div>
                <div class="course-fullname"><?php echo s($course->fullname); ?></div>
                <div class="course-category"><?php echo s($course->catname ?? ''); ?></div>
                <div class="course-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $enrolled; ?></div>
                        <div class="stat-label">Students</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value risk"><?php echo $atrisk; ?></div>
                        <div class="stat-label">At Risk</div>
                    </div>
                </div>
                <div class="course-actions">
                    <a href="<?php echo $dash_url; ?>" class="btn-open">Open Dashboard</a>
                    <a href="<?php echo $conf_url; ?>" class="btn-config">Configure</a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
    <?php
    echo $OUTPUT->footer();
    exit;
}

// ── courseid passed — smart routing ───────────────────────────────────────────
$course  = get_course($courseid);
$context = context_course::instance($courseid);
require_login($course);

$config     = local_riskdetector_get_config($courseid);
$has_config = ($config !== null);

$conf_url = new moodle_url('/local/riskdetector/configure.php', ['courseid' => $courseid]);
$dash_url = new moodle_url('/local/riskdetector/dashboard.php', ['courseid' => $courseid]);

if (!$has_config) {
    redirect($conf_url, 'Please configure risk thresholds for this course first.');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/riskdetector/index.php', ['courseid' => $courseid]));
$PAGE->set_title('Analysing Student Risk — ' . $course->shortname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$dash_str = $dash_url->out(false) . '&refresh=1';

echo $OUTPUT->header();
?>
<style>
.preloader-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:75vh;text-align:center;padding:40px 20px}
.pl-scene{position:relative;width:120px;height:120px;margin-bottom:24px}
.pl-orbit{position:absolute;border-radius:50%;border:2.5px solid transparent;animation:plSpin linear infinite}
.pl-orbit-1{width:120px;height:120px;top:0;left:0;border-top-color:#6c5ce7;border-right-color:#6c5ce7;animation-duration:1.4s}
.pl-orbit-2{width:88px;height:88px;top:16px;left:16px;border-top-color:#00b894;border-left-color:#00b894;animation-duration:1.1s;animation-direction:reverse}
.pl-orbit-3{width:58px;height:58px;top:31px;left:31px;border-bottom-color:#fd79a8;border-right-color:#fd79a8;animation-duration:0.8s}
@keyframes plSpin{to{transform:rotate(360deg)}}
.pl-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:22px;height:22px;border-radius:50%;background:#6c5ce7;animation:plPulse 1.4s ease-in-out infinite}
@keyframes plPulse{0%,100%{transform:translate(-50%,-50%) scale(1);opacity:1}50%{transform:translate(-50%,-50%) scale(1.5);opacity:0.6}}
.pl-particles{position:absolute;width:100%;height:100%;top:0;left:0}
.pl-particle{position:absolute;border-radius:50%;animation:plFloat ease-in-out infinite}
.pl-particle:nth-child(1){width:7px;height:7px;background:#6c5ce7;top:5%;left:48%;animation-duration:2s;animation-delay:0s;opacity:.7}
.pl-particle:nth-child(2){width:5px;height:5px;background:#00b894;top:48%;right:5%;animation-duration:2.4s;animation-delay:.3s;opacity:.6}
.pl-particle:nth-child(3){width:6px;height:6px;background:#fd79a8;bottom:5%;left:48%;animation-duration:1.8s;animation-delay:.6s;opacity:.7}
.pl-particle:nth-child(4){width:5px;height:5px;background:#fdcb6e;top:48%;left:5%;animation-duration:2.2s;animation-delay:.9s;opacity:.6}
@keyframes plFloat{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-9px) scale(1.4)}}
.preloader-course-tag{font-size:13px;color:#6c5ce7;font-weight:600;background:#f0edff;padding:5px 16px;border-radius:20px;margin-bottom:16px;display:inline-block}
.preloader-title{font-size:22px;font-weight:700;color:#2d3748;margin-bottom:8px}
.preloader-subtitle{font-size:14px;color:#6c757d;margin-bottom:28px;max-width:420px;line-height:1.6}
.preloader-steps{display:flex;flex-direction:column;gap:10px;width:100%;max-width:400px;margin-bottom:24px}
.step-item{display:flex;align-items:center;gap:12px;padding:11px 16px;border-radius:10px;background:#f8f9fa;border:1.5px solid #e9ecef;font-size:14px;color:#adb5bd;transition:all 0.5s ease;text-align:left}
.step-item.active{background:#eef2ff;border-color:#6c5ce7;color:#2d3748;font-weight:500}
.step-item.done{background:#f0fff4;border-color:#c6f6d5;color:#276749;font-weight:500}
.step-indicator{width:24px;height:24px;border-radius:50%;background:#dee2e6;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;transition:all 0.3s;color:#fff}
.step-item.active .step-indicator{background:#6c5ce7;animation:plPulseStep 1.2s infinite}
.step-item.done .step-indicator{background:#48bb78}
@keyframes plPulseStep{0%,100%{box-shadow:0 0 0 0 rgba(108,92,231,0.5)}50%{box-shadow:0 0 0 6px rgba(108,92,231,0)}}
.preloader-bar-wrap{width:100%;max-width:400px;background:#f0f0f0;border-radius:8px;height:6px;overflow:hidden}
.preloader-bar{height:6px;background:linear-gradient(90deg,#6c5ce7,#00b894);border-radius:8px;width:0%;transition:width 0.6s ease}
</style>

<div class="preloader-wrap">
    <div class="pl-scene">
        <div class="pl-orbit pl-orbit-1"></div>
        <div class="pl-orbit pl-orbit-2"></div>
        <div class="pl-orbit pl-orbit-3"></div>
        <div class="pl-particles">
            <div class="pl-particle"></div>
            <div class="pl-particle"></div>
            <div class="pl-particle"></div>
            <div class="pl-particle"></div>
        </div>
        <div class="pl-center"></div>
    </div>
    <span class="preloader-course-tag">
        <?php echo s($course->shortname); ?> &mdash; <?php echo s($course->fullname); ?>
    </span>
    <h2 class="preloader-title">Analysing student risk data</h2>
    <p class="preloader-subtitle">
        Calculating risk scores for all enrolled students based on your configured thresholds.
    </p>
    <div class="preloader-steps">
        <div class="step-item" id="step1"><div class="step-indicator" id="si1">1</div><span>Loading enrolled students</span></div>
        <div class="step-item" id="step2"><div class="step-indicator" id="si2">2</div><span>Fetching assignment grades</span></div>
        <div class="step-item" id="step3"><div class="step-indicator" id="si3">3</div><span>Fetching quiz performance</span></div>
        <div class="step-item" id="step4"><div class="step-indicator" id="si4">4</div><span>Checking login activity</span></div>
        <div class="step-item" id="step5"><div class="step-indicator" id="si5">5</div><span>Calculating risk scores</span></div>
        <div class="step-item" id="step6"><div class="step-indicator" id="si6">6</div><span>Preparing dashboard</span></div>
    </div>
    <div class="preloader-bar-wrap">
        <div class="preloader-bar" id="progressBar"></div>
    </div>
</div>

<script>
const steps  = ["step1","step2","step3","step4","step5","step6"];
const indics = ["si1","si2","si3","si4","si5","si6"];
const delays = [400, 1200, 2000, 2800, 3600, 4400];
const dashUrl = "<?php echo $dash_str; ?>";

steps.forEach((id, i) => {
    setTimeout(() => {
        if (i > 0) {
            document.getElementById(steps[i-1]).classList.remove("active");
            document.getElementById(steps[i-1]).classList.add("done");
            document.getElementById(indics[i-1]).innerHTML = "&#10003;";
        }
        document.getElementById(id).classList.add("active");
        document.getElementById("progressBar").style.width = Math.round(((i+1)/steps.length)*90) + "%";
    }, delays[i]);
});

setTimeout(() => {
    document.getElementById(steps[steps.length-1]).classList.remove("active");
    document.getElementById(steps[steps.length-1]).classList.add("done");
    document.getElementById(indics[indics.length-1]).innerHTML = "&#10003;";
    document.getElementById("progressBar").style.width = "100%";
    setTimeout(() => { window.location.href = dashUrl; }, 600);
}, 5400);
</script>
<?php
echo $OUTPUT->footer();