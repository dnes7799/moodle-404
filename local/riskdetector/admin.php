<?php
/**
 * Student Risk Detector — Admin Default Settings
 * ============================================================================
 *
 * Saves to mdl_local_riskdetector_defaults (separate from course configs).
 * No weight fields anywhere — only enabled/threshold + email template + SMTP.
 *
 * Features:
 *   - Set default thresholds for all 4 signals
 *   - Set default email template with plain text / HTML / Preview tabs
 *   - Configure SMTP (sender name, host, port, username, app password)
 *   - Apply defaults to unconfigured courses only (safe)
 *   - Overwrite ALL course configs with defaults (with confirmation)
 *
 * Access: Site admin only
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/riskdetector/admin.php'));
$PAGE->set_title('Admin Default Settings — Student Risk Detector');
$PAGE->set_heading('Risk Detector — Admin Settings');
$PAGE->set_pagelayout('standard');

global $USER, $DB, $OUTPUT;

$saved        = false;
$bulk_applied = 0;
$bulk_action  = '';

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    $post_action = optional_param('post_action', 'save', PARAM_ALPHA);

    // ── Keep existing password if field left blank ─────────────────────────
    $existing_defaults   = local_riskdetector_get_defaults();
    $submitted_password  = optional_param('smtp_password', '', PARAM_RAW);

    if ($submitted_password === '' && $existing_defaults && !empty($existing_defaults->smtp_password)) {
        // Admin left password blank — keep the stored one (decoded so save_defaults re-encodes it)
        $smtp_password_final = base64_decode($existing_defaults->smtp_password);
    } else {
        $smtp_password_final = $submitted_password;
    }

    // ── Build data object (thresholds + email template + SMTP) ─────────────
    $data = (object)[
        // Thresholds
        'attendance_enabled'   => optional_param('attendance_enabled',   0,   PARAM_INT),
        'attendance_threshold' => optional_param('attendance_threshold',  50,  PARAM_FLOAT),
        'login_enabled'        => optional_param('login_enabled',         0,   PARAM_INT),
        'login_days'           => optional_param('login_days',            14,  PARAM_INT),
        'quiz_enabled'         => optional_param('quiz_enabled',          0,   PARAM_INT),
        'quiz_threshold'       => optional_param('quiz_threshold',        50,  PARAM_FLOAT),
        'assign_enabled'       => optional_param('assign_enabled',        0,   PARAM_INT),
        'assign_threshold'     => optional_param('assign_threshold',      50,  PARAM_FLOAT),
        // Email template
        'email_subject'        => optional_param('email_subject',         '',  PARAM_TEXT),
        'email_template'       => optional_param('email_template',        '',  PARAM_RAW),
        // SMTP — all saved into defaults table
        'sender_name'          => optional_param('sender_name',           '',  PARAM_TEXT),
        'smtp_host'            => optional_param('smtp_host',             '',  PARAM_TEXT),
        'smtp_port'            => optional_param('smtp_port',             587, PARAM_INT),
        'smtp_username'        => optional_param('smtp_username',         '',  PARAM_TEXT),
        'smtp_password'        => $smtp_password_final,
    ];

    // ── Save ONCE to mdl_local_riskdetector_defaults ───────────────────────
    local_riskdetector_save_defaults($data);
    $saved = true;

    // ── Bulk apply (optional) ─────────────────────────────────────────────
    if ($post_action === 'apply_all') {
        $bulk_applied = local_riskdetector_apply_defaults_to_all();
        $bulk_action  = 'all';
    } elseif ($post_action === 'apply_new') {
        $bulk_applied = local_riskdetector_apply_defaults_to_new();
        $bulk_action  = 'new';
    }
}

// ── Load ALL defaults from mdl_local_riskdetector_defaults ────────────────
// Single source of truth for thresholds AND email AND SMTP config.
$d = local_riskdetector_get_defaults() ?: (object)[
    'attendance_enabled'   => 0,   'attendance_threshold' => 50,
    'login_enabled'        => 1,   'login_days'           => 14,
    'quiz_enabled'         => 1,   'quiz_threshold'       => 50,
    'assign_enabled'       => 1,   'assign_threshold'     => 50,
    'email_subject'   => 'Academic support notice — {course_name}',
    'email_template'  => "Dear {student_name},\n\n"
                       . "You have been identified as needing additional support in {course_name}.\n\n"
                       . "Concerns: {risk_reasons}\n\n"
                       . "Please log in and review your course.\n\n"
                       . "Regards,\n{teacher_name}",
    'sender_name'   => '',
    'smtp_host'     => '',
    'smtp_port'     => 587,
    'smtp_username' => '',
    'smtp_password' => '',
];

// ── Email / SMTP config variables (all from defaults table) ───────────────
$sender_name       = $d->sender_name   ?? '';
$smtp_host         = $d->smtp_host     ?? '';
$smtp_port         = (int)($d->smtp_port ?? 587);
$smtp_username     = $d->smtp_username ?? '';
$smtp_has_password = !empty($d->smtp_password);

// ── Stats ──────────────────────────────────────────────────────────────────
$total_courses    = $DB->count_records_select('course', 'id != 1 AND visible = 1');
$configured_count = $DB->count_records_select('local_riskdetector_config', 'courseid != 0');
$unconfigured     = max(0, $total_courses - $configured_count);
$back             = new moodle_url('/local/riskdetector/index.php');

echo $OUTPUT->header();
?>
<style>
/* ── Layout ── */
.adm-page{max-width:1000px;margin:0 auto;padding:24px 20px 40px}
.adm-breadcrumb{font-size:13px;color:#6c757d;margin-bottom:20px}
.adm-breadcrumb a{color:#6c5ce7;text-decoration:none}
.adm-breadcrumb a:hover{text-decoration:underline}
.adm-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.adm-header-left h1{font-size:24px;font-weight:700;color:#1a202c;margin:0 0 6px}
.adm-header-left p{font-size:14px;color:#6c757d;margin:0}
.adm-admin-badge{background:#1a1a2e;color:#fff;font-size:12px;font-weight:700;padding:5px 14px;border-radius:20px;display:inline-flex;align-items:center;gap:6px;align-self:flex-start}

/* ── Stats ── */
.adm-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px}
.adm-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 20px;text-align:center}
.adm-stat-value{font-size:28px;font-weight:800;color:#1a202c}
.adm-stat-label{font-size:12px;color:#8a92a6;text-transform:uppercase;font-weight:600;margin-top:4px;letter-spacing:.5px}
.adm-stat.purple .adm-stat-value{color:#6c5ce7}
.adm-stat.success .adm-stat-value{color:#276749}
.adm-stat.warning .adm-stat-value{color:#d69e2e}

/* ── Alerts ── */
.adm-alert{border-radius:10px;padding:13px 16px;margin-bottom:20px;font-size:14px;font-weight:500;display:flex;align-items:flex-start;gap:10px}
.adm-alert-success{background:#f0fff4;border:1.5px solid #c6f6d5;color:#276749}

/* ── Info box ── */
.adm-info-box{background:#f0edff;border:1px solid #c9c2f7;border-radius:10px;padding:12px 16px;font-size:13px;color:#4a5568;line-height:1.6;margin-bottom:20px}

/* ── Two-column rule grid ── */
.adm-layout{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
@media(max-width:720px){.adm-layout{grid-template-columns:1fr}}

/* ── Rule card ── */
.adm-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px 22px;transition:border-color .2s,box-shadow .2s}
.adm-card.enabled{border-color:#6c5ce7;box-shadow:0 2px 12px rgba(108,92,231,.08)}
.adm-card-header{display:flex;align-items:center;justify-content:space-between}
.adm-card-title-wrap{display:flex;align-items:center;gap:10px}
.adm-card-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.adm-card-name{font-size:15px;font-weight:600;color:#2d3748}
.adm-card-desc{font-size:12px;color:#a0aec0;margin-top:2px}

/* ── Toggle ── */
.adm-toggle{position:relative;width:44px;height:24px;flex-shrink:0}
.adm-toggle input{opacity:0;width:0;height:0}
.adm-toggle-slider{position:absolute;inset:0;background:#cbd5e0;border-radius:24px;cursor:pointer;transition:background .3s}
.adm-toggle-slider:before{content:"";position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:transform .3s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.adm-toggle input:checked + .adm-toggle-slider{background:#6c5ce7}
.adm-toggle input:checked + .adm-toggle-slider:before{transform:translateX(20px)}

/* ── Threshold body ── */
.adm-card-body{display:none;margin-top:16px}
.adm-card-body.show{display:block}
.adm-threshold-row{background:#f8f7ff;border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.adm-threshold-row label{font-size:13px;color:#4a5568;font-weight:500}
.adm-num-input{width:76px;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-weight:600;text-align:center;color:#2d3748;transition:border-color .2s}
.adm-num-input:focus{outline:none;border-color:#6c5ce7}
.adm-unit{font-size:13px;color:#718096}

/* ── Email card ── */
.adm-email-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:22px 24px;margin-bottom:16px}
.adm-email-card h3{font-size:15px;font-weight:700;color:#1a202c;margin:0 0 6px;display:flex;align-items:center;gap:8px}
.adm-email-card > p{font-size:13px;color:#8a92a6;margin:0 0 16px}
.adm-label{font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px}
.adm-input-full{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;color:#2d3748;box-sizing:border-box;font-family:inherit;transition:border-color .2s}
.adm-input-full:focus{outline:none;border-color:#6c5ce7}
textarea.adm-input-full{resize:vertical;line-height:1.5}
.adm-hint{font-size:11px;color:#a0aec0;margin-top:5px;line-height:1.5}

/* ── Email tabs ── */
.adm-tabs{display:flex;border-bottom:2px solid #edf0f7;margin-bottom:14px}
.adm-tab-btn{padding:8px 16px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2.5px solid transparent;color:#8a92a6;margin-bottom:-2px;transition:all .2s}
.adm-tab-btn.active{border-bottom-color:#6c5ce7;color:#6c5ce7}
.adm-tab-pane{display:none}
.adm-tab-pane.active{display:block}
.adm-quick-btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.adm-quick-btn{padding:5px 12px;background:#f0edff;color:#6c5ce7;border:1px solid #c9c2f7;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s}
.adm-quick-btn:hover{background:#6c5ce7;color:#fff;border-color:#6c5ce7}

/* ── Bulk section ── */
.adm-bulk-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px 22px;margin-bottom:16px}
.adm-bulk-card h3{font-size:15px;font-weight:700;color:#1a202c;margin:0 0 8px;display:flex;align-items:center;gap:8px}
.adm-bulk-card p{font-size:13px;color:#6c757d;margin:0 0 14px;line-height:1.6}
.adm-bulk-actions{display:flex;gap:10px;flex-wrap:wrap}

/* ── Action bar ── */
.adm-action-bar{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.adm-action-left{display:flex;gap:10px;flex-wrap:wrap}
.btn-primary{background:#6c5ce7;color:#fff;border:none;padding:10px 24px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-primary:hover{background:#5a4bd1}
.btn-danger{background:#e53e3e;color:#fff;border:none;padding:10px 22px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-danger:hover{background:#c53030}
.btn-warning{background:#d69e2e;color:#fff;border:none;padding:10px 22px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}
.btn-warning:hover{background:#b7791f}
.btn-secondary{background:#f7f7f7;color:#555;border:1.5px solid #e2e8f0;padding:10px 20px;border-radius:9px;font-size:14px;font-weight:600;text-decoration:none;display:inline-block}
.btn-secondary:hover{background:#efefef;color:#333;text-decoration:none}

/* ── Save overlay ── */
.adm-save-overlay{display:none;position:fixed;inset:0;background:rgba(255,255,255,.94);z-index:9999;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.adm-save-overlay.show{display:flex}
.adm-save-overlay h3{font-size:20px;font-weight:700;color:#2d3748;margin:20px 0 8px}
.adm-save-overlay p{font-size:14px;color:#6c757d}
.sv-scene{position:relative;width:100px;height:100px}
.sv-orbit{position:absolute;border-radius:50%;border:2.5px solid transparent;animation:svSpin linear infinite}
.sv-orbit-1{width:100px;height:100px;top:0;left:0;border-top-color:#6c5ce7;border-right-color:#6c5ce7;animation-duration:1.4s}
.sv-orbit-2{width:74px;height:74px;top:13px;left:13px;border-top-color:#00b894;border-left-color:#00b894;animation-duration:1.1s;animation-direction:reverse}
.sv-orbit-3{width:48px;height:48px;top:26px;left:26px;border-bottom-color:#fd79a8;border-right-color:#fd79a8;animation-duration:.8s}
@keyframes svSpin{to{transform:rotate(360deg)}}
.sv-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:18px;height:18px;border-radius:50%;background:#6c5ce7;animation:svPulse 1.4s ease-in-out infinite}
@keyframes svPulse{0%,100%{transform:translate(-50%,-50%) scale(1);opacity:1}50%{transform:translate(-50%,-50%) scale(1.5);opacity:.6}}
</style>

<div class="adm-page">

<div class="adm-breadcrumb">
    <a href="<?php echo $back; ?>">Student Risk Detector</a> &rsaquo; Admin Default Settings
</div>

<div class="adm-header">
    <div class="adm-header-left">
        <h1>Default Threshold Settings</h1>
        <p>Set global defaults. Teachers see these pre-filled when they configure a course for the first time.</p>
    </div>
    <span class="adm-admin-badge">&#9881; Admin only</span>
</div>

<?php if ($saved): ?>
<div class="adm-alert adm-alert-success">
    &#10003;
    <?php if ($bulk_action === 'all' && $bulk_applied > 0): ?>
        Defaults saved. <strong><?php echo $bulk_applied; ?> course(s)</strong> have been overwritten with these defaults.
    <?php elseif ($bulk_action === 'new' && $bulk_applied > 0): ?>
        Defaults saved. Applied to <strong><?php echo $bulk_applied; ?> unconfigured course(s)</strong>.
    <?php elseif ($bulk_action === 'all' || $bulk_action === 'new'): ?>
        Defaults saved. No courses matched the bulk criteria.
    <?php else: ?>
        Default settings saved to <strong>mdl_local_riskdetector_defaults</strong>.
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="adm-stats">
    <div class="adm-stat purple">
        <div class="adm-stat-value"><?php echo $total_courses; ?></div>
        <div class="adm-stat-label">Total courses</div>
    </div>
    <div class="adm-stat success">
        <div class="adm-stat-value"><?php echo $configured_count; ?></div>
        <div class="adm-stat-label">Configured</div>
    </div>
    <div class="adm-stat warning">
        <div class="adm-stat-value"><?php echo $unconfigured; ?></div>
        <div class="adm-stat-label">Not configured yet</div>
    </div>
</div>

<!-- Info box -->
<div class="adm-info-box">
    &#128161; <strong>How this works:</strong>
    These defaults are saved in a separate <code>mdl_local_riskdetector_defaults</code> table.
    When a teacher opens the configure page for the first time, these values are pre-filled automatically.
    If a teacher saves their own settings, those go into <code>mdl_local_riskdetector_config</code> and override your defaults for that course only.
    Using <strong>Overwrite all courses</strong> pushes these defaults directly into every course config.
</div>

<form method="post" action="" id="adminForm">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

<!-- 2-column threshold grid -->
<div class="adm-layout">

    <!-- Assignment -->
    <div class="adm-card <?php echo $d->assign_enabled ? 'enabled' : ''; ?>" id="adm_card_assign">
        <div class="adm-card-header">
            <div class="adm-card-title-wrap">
                <div class="adm-card-icon" style="background:#e8fdf0;">&#128196;</div>
                <div>
                    <div class="adm-card-name">Assignment performance</div>
                    <div class="adm-card-desc">Flag students below average threshold</div>
                </div>
            </div>
            <label class="adm-toggle">
                <input type="checkbox" name="assign_enabled" value="1"
                    <?php echo $d->assign_enabled ? 'checked' : ''; ?>
                    onchange="admToggle('assign', this.checked)">
                <span class="adm-toggle-slider"></span>
            </label>
        </div>
        <div class="adm-card-body <?php echo $d->assign_enabled ? 'show' : ''; ?>" id="adm_body_assign">
            <div class="adm-threshold-row">
                <label>Average below</label>
                <input type="number" name="assign_threshold" class="adm-num-input"
                    value="<?php echo (float)$d->assign_threshold; ?>" min="0" max="100" step="1">
                <span class="adm-unit">%</span>
            </div>
        </div>
    </div>

    <!-- Quiz -->
    <div class="adm-card <?php echo $d->quiz_enabled ? 'enabled' : ''; ?>" id="adm_card_quiz">
        <div class="adm-card-header">
            <div class="adm-card-title-wrap">
                <div class="adm-card-icon" style="background:#fef3e2;">&#9997;</div>
                <div>
                    <div class="adm-card-name">Quiz performance</div>
                    <div class="adm-card-desc">Flag students below quiz average</div>
                </div>
            </div>
            <label class="adm-toggle">
                <input type="checkbox" name="quiz_enabled" value="1"
                    <?php echo $d->quiz_enabled ? 'checked' : ''; ?>
                    onchange="admToggle('quiz', this.checked)">
                <span class="adm-toggle-slider"></span>
            </label>
        </div>
        <div class="adm-card-body <?php echo $d->quiz_enabled ? 'show' : ''; ?>" id="adm_body_quiz">
            <div class="adm-threshold-row">
                <label>Average below</label>
                <input type="number" name="quiz_threshold" class="adm-num-input"
                    value="<?php echo (float)$d->quiz_threshold; ?>" min="0" max="100" step="1">
                <span class="adm-unit">%</span>
            </div>
        </div>
    </div>

    <!-- Login inactivity -->
    <div class="adm-card <?php echo $d->login_enabled ? 'enabled' : ''; ?>" id="adm_card_login">
        <div class="adm-card-header">
            <div class="adm-card-title-wrap">
                <div class="adm-card-icon" style="background:#e8f4fd;">&#128274;</div>
                <div>
                    <div class="adm-card-name">Login inactivity</div>
                    <div class="adm-card-desc">Detect students not logging in</div>
                </div>
            </div>
            <label class="adm-toggle">
                <input type="checkbox" name="login_enabled" value="1"
                    <?php echo $d->login_enabled ? 'checked' : ''; ?>
                    onchange="admToggle('login', this.checked)">
                <span class="adm-toggle-slider"></span>
            </label>
        </div>
        <div class="adm-card-body <?php echo $d->login_enabled ? 'show' : ''; ?>" id="adm_body_login">
            <div class="adm-threshold-row">
                <label>No login for more than</label>
                <input type="number" name="login_days" class="adm-num-input"
                    value="<?php echo (int)$d->login_days; ?>" min="1" max="90">
                <span class="adm-unit">days</span>
            </div>
        </div>
    </div>

    <!-- Attendance -->
    <div class="adm-card <?php echo $d->attendance_enabled ? 'enabled' : ''; ?>" id="adm_card_attendance">
        <div class="adm-card-header">
            <div class="adm-card-title-wrap">
                <div class="adm-card-icon" style="background:#f0e8fd;">&#128203;</div>
                <div>
                    <div class="adm-card-name">Attendance</div>
                    <div class="adm-card-desc">Detect low attendance rate</div>
                </div>
            </div>
            <label class="adm-toggle">
                <input type="checkbox" name="attendance_enabled" value="1"
                    <?php echo $d->attendance_enabled ? 'checked' : ''; ?>
                    onchange="admToggle('attendance', this.checked)">
                <span class="adm-toggle-slider"></span>
            </label>
        </div>
        <div class="adm-card-body <?php echo $d->attendance_enabled ? 'show' : ''; ?>" id="adm_body_attendance">
            <div class="adm-threshold-row">
                <label>Attendance below</label>
                <input type="number" name="attendance_threshold" class="adm-num-input"
                    value="<?php echo (float)$d->attendance_threshold; ?>" min="0" max="100" step="1">
                <span class="adm-unit">%</span>
            </div>
        </div>
    </div>

</div><!-- end grid -->

<!-- Email / SMTP configuration card -->
<div class="adm-email-card" style="margin-bottom:16px;">
    <h3>
        <span style="background:#e8fdf0;width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-size:16px;">&#128231;</span>
        Email sending configuration
    </h3>
    <p>Configure SMTP so the plugin can send emails directly to students independent of Moodle's mail settings.</p>

    <!-- Status banner -->
    <?php if (!empty($smtp_host) && !empty($smtp_username) && $smtp_has_password): ?>
    <div style="background:#f0fff4;border:1.5px solid #c6f6d5;border-radius:8px;padding:10px 16px;font-size:13px;color:#276749;display:flex;align-items:center;gap:8px;margin-bottom:16px;">
        &#10003; SMTP ready &mdash;
        <strong><?php echo s($smtp_username); ?></strong> via <strong><?php echo s($smtp_host . ':' . $smtp_port); ?></strong>
    </div>
    <?php else: ?>
    <div style="background:#fffbeb;border:1.5px solid #fef3c7;border-radius:8px;padding:10px 16px;font-size:13px;color:#92400e;display:flex;align-items:center;gap:8px;margin-bottom:16px;">
        &#9888; SMTP not fully configured &mdash; fill in all fields below to enable email notifications.
    </div>
    <?php endif; ?>

    <!-- Field explanations box -->
    <div style="background:#f0edff;border:1px solid #c9c2f7;border-radius:8px;padding:12px 16px;font-size:13px;color:#4a5568;line-height:1.7;margin-bottom:16px;">
        <strong style="color:#6c5ce7;">&#128161; Field guide</strong><br>
        <strong>Sender display name</strong> — The name students see in their inbox as "From" e.g. <em>Student Support Team</em><br>
        <strong>Email / SMTP username</strong> — Your full email address. Used to log into the mail server AND as the From email address e.g. <em>support@koi.edu.au</em><br>
        <strong>SMTP host</strong> — Your mail server. Gmail = <code>smtp.gmail.com</code>, Outlook = <code>smtp.office365.com</code><br>
        <strong>Port</strong> — Use <strong>587</strong> for TLS (recommended) or <strong>465</strong> for SSL<br>
        <strong>Password</strong> — For Gmail use an App Password, not your regular password
    </div>

    <!-- 5 fields -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

        <div>
            <label class="adm-label">Sender display name</label>
            <input type="text" name="sender_name" class="adm-input-full"
                value="<?php echo s($sender_name); ?>"
                placeholder="e.g. Student Support Team">
            <p class="adm-hint">Shown as the "From" name in the student's inbox.</p>
        </div>

        <div>
            <label class="adm-label">Email address &amp; SMTP username</label>
            <input type="text" name="smtp_username" class="adm-input-full"
                value="<?php echo s($smtp_username); ?>"
                placeholder="e.g. support@koi.edu.au"
                autocomplete="off">
            <p class="adm-hint">Your full email address — used as login AND as the From address.</p>
        </div>

        <div>
            <label class="adm-label">SMTP host</label>
            <input type="text" name="smtp_host" class="adm-input-full"
                value="<?php echo s($smtp_host); ?>"
                placeholder="e.g. smtp.gmail.com"
                autocomplete="off">
            <p class="adm-hint">Your outgoing mail server hostname.</p>
        </div>

        <div>
            <label class="adm-label">SMTP port</label>
            <input type="number" name="smtp_port" class="adm-input-full"
                value="<?php echo (int)$smtp_port; ?>"
                placeholder="587" min="1" max="65535">
            <p class="adm-hint">587 = TLS &nbsp;|&nbsp; 465 = SSL &nbsp;(auto-detected from port)</p>
        </div>

        <div style="grid-column: 1 / -1;">
            <label class="adm-label">
                SMTP password
                <?php if ($smtp_has_password): ?>
                <span style="font-size:11px;color:#00b894;font-weight:500;margin-left:8px;">&#10003; Password is saved</span>
                <?php endif; ?>
            </label>
            <input type="password" name="smtp_password"
                class="adm-input-full"
                value=""
                placeholder="<?php echo $smtp_has_password ? 'Leave blank to keep existing password' : 'Enter SMTP password or app password'; ?>"
                autocomplete="new-password">
            <p class="adm-hint">
                <?php if ($smtp_has_password): ?>
                    A password is already saved. Leave blank to keep it. Type a new one to replace it.
                <?php else: ?>
                    For <strong>Gmail</strong>: go to Google Account &rarr; Security &rarr; 2-Step Verification &rarr; App passwords &rarr; generate one for "Mail".
                    Do <strong>not</strong> use your regular Gmail password.
                <?php endif; ?>
            </p>
        </div>

    </div>
</div>

<!-- Email template card -->
<div class="adm-email-card">
    <h3>
        <span style="background:#e8f0fd;width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;font-size:16px;">&#9993;</span>
        Default notification email template
    </h3>
    <p>Teachers see this pre-filled when they set up a course. They can customise it per course.</p>

    <div style="margin-bottom:16px;">
        <label class="adm-label">Email subject</label>
        <input type="text" name="email_subject" class="adm-input-full"
            value="<?php echo s($d->email_subject); ?>">
        <p class="adm-hint">Placeholders: {student_name} {course_name} {risk_reasons} {teacher_name}</p>
    </div>

    <!-- Tabs: Plain / HTML / Preview -->
    <div class="adm-tabs">
        <button type="button" class="adm-tab-btn active" onclick="switchEmailTab('plain', this)">Plain text</button>
        <button type="button" class="adm-tab-btn" onclick="switchEmailTab('html', this)">HTML / Marketing style</button>
        <button type="button" class="adm-tab-btn" onclick="switchEmailTab('preview', this)">Preview</button>
    </div>

    <!-- Plain text -->
    <div class="adm-tab-pane active" id="email-tab-plain">
        <textarea name="email_template" id="email_plain" class="adm-input-full" rows="8"
            oninput="syncEmailContent()"><?php echo s($d->email_template); ?></textarea>
        <p class="adm-hint">Plain text. Line breaks are preserved in emails.</p>
    </div>

    <!-- HTML editor -->
    <div class="adm-tab-pane" id="email-tab-html">
        <textarea id="email_html_editor" class="adm-input-full" rows="10"
            style="font-family:monospace;font-size:12px;"
            oninput="syncHtmlToPlain()"><?php echo s($d->email_template); ?></textarea>
        <p class="adm-hint">
            Full HTML for marketing-style emails. Use inline CSS for best email client compatibility.<br>
            Example: <code>&lt;h2 style="color:#6c5ce7;"&gt;Dear {student_name}&lt;/h2&gt;</code>
        </p>
        <div class="adm-quick-btns">
            <button type="button" class="adm-quick-btn" onclick="insertHtmlTemplate('simple')">Simple HTML</button>
            <button type="button" class="adm-quick-btn" onclick="insertHtmlTemplate('professional')">Professional card</button>
            <button type="button" class="adm-quick-btn" onclick="insertHtmlTemplate('marketing')">Marketing style</button>
        </div>
    </div>

    <!-- Preview -->
    <div class="adm-tab-pane" id="email-tab-preview">
        <div style="background:#f4f6f9;border-radius:10px;padding:16px;margin-bottom:8px;">
            <div style="font-size:12px;color:#8a92a6;margin-bottom:4px;font-weight:600;">SUBJECT</div>
            <div id="preview-subject" style="font-size:14px;color:#2d3748;font-weight:600;"></div>
        </div>
        <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;overflow:hidden;">
            <div style="background:#f8f9fc;padding:10px 16px;font-size:12px;color:#8a92a6;font-weight:600;border-bottom:1px solid #e2e8f0;">
                EMAIL BODY PREVIEW
            </div>
            <iframe id="preview-frame" style="width:100%;border:none;min-height:200px;" scrolling="no"></iframe>
        </div>
        <p class="adm-hint" style="margin-top:8px;">Placeholders shown as-is. Real emails will have names filled in.</p>
    </div>
</div>

<!-- Bulk apply -->
<div class="adm-bulk-card">
    <h3>&#128640; Bulk apply to courses</h3>
    <p>
        <strong>Apply to unconfigured</strong> — sets defaults only on courses where no thresholds have been saved yet. Safe and non-destructive.<br>
        <strong>Overwrite ALL courses</strong> — replaces every course config with these defaults. Teachers will lose their custom settings.
    </p>
    <div class="adm-bulk-actions">
        <button type="submit" name="post_action" value="apply_new"
            class="btn-warning" onclick="showSaveLoader('Applying to unconfigured courses\u2026', 'Setting defaults for <?php echo $unconfigured; ?> courses.')">
            Apply to unconfigured
            <span style="background:rgba(255,255,255,.2);padding:1px 8px;border-radius:8px;font-size:11px;margin-left:4px;">
                <?php echo $unconfigured; ?> courses
            </span>
        </button>
        <button type="submit" name="post_action" value="apply_all"
            class="btn-danger"
            onclick="if(!confirm('This will overwrite ALL <?php echo $total_courses; ?> course configs. Are you sure?')) return false; showSaveLoader('Overwriting all courses\u2026', 'Applying to <?php echo $total_courses; ?> courses.')">
            Overwrite ALL courses
            <span style="background:rgba(255,255,255,.2);padding:1px 8px;border-radius:8px;font-size:11px;margin-left:4px;">
                <?php echo $total_courses; ?> courses
            </span>
        </button>
    </div>
</div>

<!-- Action bar -->
<div class="adm-action-bar">
    <div class="adm-action-left">
        <button type="submit" name="post_action" value="save"
            class="btn-primary" onclick="showSaveLoader('Saving defaults\u2026', 'Updating global settings.')">
            Save defaults
        </button>
    </div>
    <a href="<?php echo $back; ?>" class="btn-secondary">Cancel</a>
</div>

</form>
</div>

<!-- Save overlay -->
<div class="adm-save-overlay" id="saveOverlay">
    <div class="sv-scene">
        <div class="sv-orbit sv-orbit-1"></div>
        <div class="sv-orbit sv-orbit-2"></div>
        <div class="sv-orbit sv-orbit-3"></div>
        <div class="sv-center"></div>
    </div>
    <h3 id="save-overlay-msg">Saving defaults&hellip;</h3>
    <p id="save-overlay-sub">Updating global settings.</p>
</div>

<script>
// ── Toggle rule cards ───────────────────────────────────────────────────────
function admToggle(name, enabled) {
    document.getElementById('adm_card_' + name).classList.toggle('enabled', enabled);
    const body = document.getElementById('adm_body_' + name);
    if (body) body.classList.toggle('show', enabled);
}

// ── Email tab switching ─────────────────────────────────────────────────────
function switchEmailTab(tab, btn) {
    document.querySelectorAll('.adm-tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.adm-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('email-tab-' + tab).classList.add('active');
    btn.classList.add('active');
    if (tab === 'preview') updatePreview();
}

// ── Sync plain ↔ HTML editors ─────────────────────────────────────────────
function syncEmailContent() {
    document.getElementById('email_html_editor').value =
        document.getElementById('email_plain').value;
}
function syncHtmlToPlain() {
    document.getElementById('email_plain').value =
        document.getElementById('email_html_editor').value;
}

// ── Live preview ────────────────────────────────────────────────────────────
function updatePreview() {
    const subject = document.querySelector('[name="email_subject"]').value;
    const body    = document.getElementById('email_plain').value;

    document.getElementById('preview-subject').textContent = subject;

    const frame = document.getElementById('preview-frame');
    const doc   = frame.contentDocument || frame.contentWindow.document;
    const html  = (body.includes('<') && body.includes('>'))
        ? body
        : '<div style="font-family:sans-serif;font-size:14px;line-height:1.6;'
          + 'color:#2d3748;padding:20px;">'
          + body.replace(/\n/g, '<br>') + '</div>';

    doc.open();
    doc.write(html);
    doc.close();

    setTimeout(() => {
        try { frame.style.height = doc.body.scrollHeight + 32 + 'px'; } catch(e) {}
    }, 100);
}

// ── Quick HTML templates ────────────────────────────────────────────────────
const htmlTemplates = {
    simple: `<p>Dear {student_name},</p>
<p>You have been identified as needing additional support in <strong>{course_name}</strong>.</p>
<p><strong>Concerns:</strong> {risk_reasons}</p>
<p>Please review your course and contact your teacher if you need help.</p>
<p>Regards,<br>{teacher_name}</p>`,

    professional: `<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
  <div style="background:#6c5ce7;padding:24px;text-align:center;">
    <h2 style="color:#fff;margin:0;font-size:20px;">Academic Support Notice</h2>
  </div>
  <div style="padding:28px;">
    <p style="font-size:15px;color:#2d3748;">Dear <strong>{student_name}</strong>,</p>
    <p style="color:#4a5568;">We have identified that you may need additional support in <strong>{course_name}</strong>.</p>
    <div style="background:#fff5f5;border-left:4px solid #e53e3e;padding:12px 16px;border-radius:4px;margin:16px 0;">
      <strong style="color:#c53030;">Concerns identified:</strong>
      <p style="color:#742a2a;margin:4px 0 0;">{risk_reasons}</p>
    </div>
    <p style="color:#4a5568;">Please log in to Moodle and review your activities. Your teacher is here to help.</p>
    <p style="color:#6c757d;font-size:13px;">Regards,<br><strong>{teacher_name}</strong></p>
  </div>
</div>`,

    marketing: `<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#f4f6f9;padding:20px;">
  <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#6c5ce7,#a855f7);padding:32px;text-align:center;">
      <h1 style="color:#fff;margin:0;font-size:22px;">We're here to support you</h1>
      <p style="color:rgba(255,255,255,.85);margin:8px 0 0;font-size:14px;">{course_name}</p>
    </div>
    <div style="padding:32px;">
      <p style="font-size:16px;color:#1a202c;">Hi <strong>{student_name}</strong>,</p>
      <p style="color:#4a5568;line-height:1.7;">Our system has flagged some concerns. We want to make sure you get the support you need.</p>
      <div style="background:#faf5ff;border:1px solid #d6bcfa;border-radius:8px;padding:16px;margin:20px 0;">
        <p style="margin:0;font-size:13px;color:#553c9a;font-weight:600;">&#9888; Areas needing attention</p>
        <p style="margin:8px 0 0;color:#44337a;font-size:14px;">{risk_reasons}</p>
      </div>
      <p style="color:#718096;font-size:13px;margin-top:20px;">Regards,<br><strong>{teacher_name}</strong></p>
    </div>
    <div style="background:#f7fafc;padding:16px;text-align:center;font-size:11px;color:#a0aec0;">
      This is an automated academic support notification from {course_name}.
    </div>
  </div>
</div>`
};

function insertHtmlTemplate(name) {
    const tpl = htmlTemplates[name];
    if (!tpl) return;
    document.getElementById('email_html_editor').value = tpl;
    document.getElementById('email_plain').value       = tpl;
}

// ── Save overlay ────────────────────────────────────────────────────────────
function showSaveLoader(msg, sub) {
    document.getElementById('save-overlay-msg').textContent = msg || 'Saving defaults\u2026';
    document.getElementById('save-overlay-sub').textContent = sub || 'Updating global settings.';
    document.getElementById('saveOverlay').classList.add('show');
}
</script>
<?php
echo $OUTPUT->footer();