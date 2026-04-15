<?php
/**
 * Student Risk Detector — ML Dashboard
 * Reads predictions from mdl_local_riskdetector_ml (Python Random Forest model)
 *
 * Layout:
 *   Row 1 : 4 KPI cards
 *   Row 2 : Donut chart (risk bands) + Feature importance card
 *   Row 3 : Student risk table with filters, pagination, detail + notify modals
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);
$page     = optional_param('page', 0, PARAM_INT);
$perpage  = 10;

require_login($course);
$context = context_course::instance($courseid);
require_capability('local/riskdetector:viewdashboard', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/riskdetector/dashboard.php', ['courseid' => $courseid]));
$PAGE->set_title('Risk Dashboard — ' . $course->shortname);
$PAGE->set_heading('Student Risk Dashboard');
$PAGE->set_pagelayout('base');

global $USER, $DB, $OUTPUT;

// ── Fetch ML results ──────────────────────────────────────────────────────────
$all_results = local_riskdetector_get_results($courseid);
$enrolled    = local_riskdetector_enrolled_count($courseid);

// ── KPI calculations from ML data ─────────────────────────────────────────────
$atrisk = $inactive_count = $low_grade_count = 0;

foreach ($all_results as $r) {
    if ($r->is_atrisk) $atrisk++;
    if ($r->days_since_active > 14) $inactive_count++;
    if ($r->avg_grade_pct < 50) $low_grade_count++;
}

$risk_pct   = $enrolled > 0 ? round(($atrisk / $enrolled) * 100, 1) : 0;
$safe_count = $enrolled - $atrisk;

// ── Band counts for donut chart ───────────────────────────────────────────────
$band_counts = ['Low' => 0, 'Moderate' => 0, 'High' => 0];
foreach ($all_results as $r) {
    $band = ucfirst(strtolower($r->risk_band));
    if (isset($band_counts[$band])) $band_counts[$band]++;
}

// ── Pagination ────────────────────────────────────────────────────────────────
$total_students = count($all_results);
$total_pages    = $perpage > 0 ? ceil($total_students / $perpage) : 1;
$paged_results  = array_slice(array_values($all_results), $page * $perpage, $perpage);

// ── URLs ──────────────────────────────────────────────────────────────────────
$conf_url = new moodle_url('/local/riskdetector/configure.php', ['courseid' => $courseid]);
$back_url = new moodle_url('/local/riskdetector/index.php');
$tmpl     = local_riskdetector_get_template($courseid);

// ── Last predicted timestamp ──────────────────────────────────────────────────
$first_result  = reset($all_results);
$predicted_str = $first_result ? $first_result->timecalculated : 'Never';

// ── Grade distribution data ──────────────────────────────────────────────────
$grade_bins = ['0–30%' => 0, '30–50%' => 0, '50–70%' => 0, '70–85%' => 0, '85–100%' => 0];
foreach ($all_results as $r) {
    $g = $r->avg_grade_pct;
    if ($g < 30) $grade_bins['0–30%']++;
    elseif ($g < 50) $grade_bins['30–50%']++;
    elseif ($g < 70) $grade_bins['50–70%']++;
    elseif ($g < 85) $grade_bins['70–85%']++;
    else $grade_bins['85–100%']++;
}

// ── Risk score distribution data ─────────────────────────────────────────────
$score_bins = ['0–20' => 0, '20–40' => 0, '40–60' => 0, '60–80' => 0, '80–100' => 0];
foreach ($all_results as $r) {
    $s = $r->risk_score;
    if ($s < 20) $score_bins['0–20']++;
    elseif ($s < 40) $score_bins['20–40']++;
    elseif ($s < 60) $score_bins['40–60']++;
    elseif ($s < 80) $score_bins['60–80']++;
    else $score_bins['80–100']++;
}

// ── Feature averages by risk band ────────────────────────────────────────────
$band_avgs = [];
foreach (['High', 'Moderate', 'Low'] as $b) {
    $band_avgs[$b] = ['grade' => [], 'days' => [], 'att' => [], 'sub' => []];
}
foreach ($all_results as $r) {
    $b = ucfirst(strtolower($r->risk_band));
    if (!isset($band_avgs[$b])) continue;
    $band_avgs[$b]['grade'][] = $r->avg_grade_pct;
    $band_avgs[$b]['days'][]  = $r->days_since_active;
    $band_avgs[$b]['att'][]   = $r->attendance_pct;
    $band_avgs[$b]['sub'][]   = $r->submission_pct;
}
$band_chart = [];
foreach (['High', 'Moderate', 'Low'] as $b) {
    $band_chart[$b] = [
        'grade' => count($band_avgs[$b]['grade']) > 0 ? round(array_sum($band_avgs[$b]['grade']) / count($band_avgs[$b]['grade']), 1) : 0,
        'days'  => count($band_avgs[$b]['days'])  > 0 ? round(array_sum($band_avgs[$b]['days'])  / count($band_avgs[$b]['days']),  1) : 0,
        'att'   => count($band_avgs[$b]['att'])   > 0 ? round(array_sum($band_avgs[$b]['att'])   / count($band_avgs[$b]['att']),   1) : 0,
        'sub'   => count($band_avgs[$b]['sub'])   > 0 ? round(array_sum($band_avgs[$b]['sub'])   / count($band_avgs[$b]['sub']),   1) : 0,
    ];
}

echo $OUTPUT->header();
?>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { background: #f4f6f9 !important; }
#page-content, #region-main, .region-content { padding: 0 !important; }

.rdd-shell {
    max-width: 1280px;
    margin: 0 auto;
    padding: 20px 24px 40px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

/* Topbar */
.rdd-topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.rdd-breadcrumb { font-size: 13px; color: #8a92a6; }
.rdd-breadcrumb a { color: #6c5ce7; text-decoration: none; }
.rdd-topbar-title { font-size: 20px; font-weight: 700; color: #1a202c; margin: 4px 0 0; }
.rdd-topbar-actions { display: flex; gap: 10px; align-items: center; }
.rdd-btn { padding: 8px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
.rdd-btn-ghost { background: #fff; color: #6c757d; border: 1px solid #e2e8f0; }
.rdd-btn-ghost:hover { background: #f8f9fa; color: #444; text-decoration: none; }
.rdd-ml-badge { background: #00b894; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; }

/* KPI row */
.rdd-kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
@media(max-width:900px) { .rdd-kpi-row { grid-template-columns: repeat(2,1fr); } }
@media(max-width:500px) { .rdd-kpi-row { grid-template-columns: 1fr; } }
.rdd-kpi { background: #fff; border-radius: 16px; padding: 20px 22px; border: 1px solid #edf0f7; }
.rdd-kpi.accent { background: #1a1a2e; border-color: #1a1a2e; color: #fff; }
.rdd-kpi-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; margin-bottom: 14px; }
.rdd-kpi.accent .rdd-kpi-icon { background: rgba(255,255,255,0.15); }
.icon-bg-red { background: #fff5f5; }
.icon-bg-orange { background: #fffaf0; }
.icon-bg-green { background: #f0fff4; }
.rdd-kpi-label { font-size: 13px; font-weight: 600; color: #8a92a6; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
.rdd-kpi.accent .rdd-kpi-label { color: rgba(255,255,255,0.6); }
.rdd-kpi-value { font-size: 32px; font-weight: 800; color: #1a202c; line-height: 1; margin-bottom: 6px; }
.rdd-kpi.accent .rdd-kpi-value { color: #fff; }
.rdd-kpi-sub { font-size: 12px; color: #8a92a6; font-weight: 500; }
.rdd-kpi-sub.up { color: #38a169; }
.rdd-kpi-sub.down { color: #e53e3e; }
.rdd-kpi.accent .rdd-kpi-sub { color: rgba(255,255,255,0.5); }

/* Charts row */
.rdd-charts-row { display: grid; grid-template-columns: 320px 1fr; gap: 16px; margin-bottom: 20px; }
@media(max-width:900px) { .rdd-charts-row { grid-template-columns: 1fr; } }
@media(max-width:768px) { .rdd-analytics-row { grid-template-columns: 1fr !important; } }
.rdd-card { background: #fff; border-radius: 16px; padding: 22px 24px; border: 1px solid #edf0f7; }
.rdd-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.rdd-card-title { font-size: 15px; font-weight: 700; color: #1a202c; }
.rdd-card-subtitle { font-size: 12px; color: #8a92a6; margin-top: 2px; }

/* Donut legend */
.rdd-donut-legend { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
.rdd-donut-legend-item { display: flex; align-items: center; justify-content: space-between; font-size: 13px; }
.rdd-donut-legend-left { display: flex; align-items: center; gap: 8px; color: #4a5568; }
.rdd-donut-legend-bar-wrap { flex: 1; margin: 0 12px; background: #f0f0f0; border-radius: 4px; height: 6px; }
.rdd-donut-legend-bar { height: 6px; border-radius: 4px; }
.rdd-donut-count { font-weight: 700; color: #1a202c; min-width: 24px; text-align: right; }

/* Feature importance card */
.fi-item { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.fi-label { font-size: 13px; color: #4a5568; min-width: 130px; font-weight: 500; }
.fi-bar-bg { flex: 1; height: 8px; background: #edf0f7; border-radius: 4px; overflow: hidden; }
.fi-bar { height: 8px; border-radius: 4px; }
.fi-pct { font-size: 13px; font-weight: 700; color: #1a202c; min-width: 45px; text-align: right; }

/* Table card */
.rdd-table-card { background: #fff; border-radius: 16px; border: 1px solid #edf0f7; overflow: hidden; }
.rdd-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.rdd-table-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px 0; flex-wrap: wrap; gap: 12px; }
.rdd-filters { display: flex; gap: 8px; padding: 14px 24px; flex-wrap: wrap; }
.rdd-filter-btn { padding: 5px 16px; border-radius: 20px; font-size: 12px; font-weight: 600; border: 1.5px solid #e2e8f0; background: #fff; color: #6c757d; cursor: pointer; transition: all 0.2s; }
.rdd-filter-btn.active { background: #6c5ce7; border-color: #6c5ce7; color: #fff; }
.rdd-table { width: 100%; border-collapse: collapse; table-layout: auto; }
.rdd-table th { padding: 10px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #8a92a6; background: #f8f9fc; border-bottom: 1px solid #edf0f7; text-align: left; }
.rdd-table td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f4f6f9; vertical-align: middle; color: #2d3748; }
.rdd-table tr:last-child td { border-bottom: none; }
.rdd-table tr:hover td { background: #fafbff; }
.student-name { font-weight: 600; color: #1a202c; font-size: 13px; }
.student-email { font-size: 10px; color: #a0aec0; margin-top: 2px; }
@media(max-width:1100px) {
    .rdd-table th, .rdd-table td { padding: 10px 8px; font-size: 12px; }
    .student-name { font-size: 12px; }
    .score-bar-bg { width: 40px; }
}
@media(max-width:768px) {
    .rdd-table th, .rdd-table td { padding: 8px 6px; font-size: 11px; }
    .student-name { font-size: 11px; }
    .student-email { display: none; }
    .score-bar-bg { width: 30px; }
    .action-btn { padding: 4px 8px; font-size: 11px; }
}
 

.band-pill { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; display: inline-block; }
.band-low { background: #f0fff4; color: #276749; }
.band-moderate { background: #fffbeb; color: #92400e; }
.band-high { background: #fff5f5; color: #c53030; }

.score-wrap { display: flex; align-items: center; gap: 8px; }
.score-bar-bg { width: 60px; height: 6px; background: #edf0f7; border-radius: 4px; overflow: hidden; }
.score-bar-fill { height: 6px; border-radius: 4px; }
.score-num { font-weight: 700; font-size: 13px; color: #2d3748; min-width: 28px; }

.conf-badge { padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.conf-high { background: #f0fff4; color: #276749; }
.conf-mid { background: #fffbeb; color: #92400e; }
.conf-low { background: #fff5f5; color: #c53030; }

.metric-cell { font-size: 12px; color: #4a5568; }
.metric-cell .metric-bad { color: #e53e3e; font-weight: 600; }
.metric-cell .metric-ok { color: #d69e2e; font-weight: 600; }
.metric-cell .metric-good { color: #276749; font-weight: 600; }

.alert-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.alert-yes { background: #fff5f5; color: #e53e3e; }
.alert-no { background: #f0fff4; color: #276749; }

.action-btn { padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
.action-view { background: #eef2ff; color: #4c63d2; margin-right: 6px; }
.action-view:hover { background: #e0e7ff; }
.action-remind { background: #fff5f5; color: #e53e3e; }
.action-remind:hover { background: #fed7d7; }

/* Pagination */
.rdd-pagination { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 20px; }
.pg-btn { width: 34px; height: 34px; border-radius: 50%; border: 1.5px solid #e2e8f0; background: #fff; font-size: 13px; font-weight: 600; color: #4a5568; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; text-decoration: none; }
.pg-btn:hover { background: #eef2ff; border-color: #6c5ce7; color: #6c5ce7; text-decoration: none; }
.pg-btn.active { background: #6c5ce7; border-color: #6c5ce7; color: #fff; }
.pg-btn.dots { border: none; background: none; cursor: default; color: #8a92a6; }

/* Modals */
.rdd-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,20,40,0.55); z-index: 9999; align-items: center; justify-content: center; }
.rdd-overlay.open { display: flex; }
.rdd-modal { background: #fff; border-radius: 20px; width: 90%; max-width: 580px; max-height: 88vh; overflow-y: auto; padding: 32px; position: relative; }
.rdd-modal-close { position: absolute; top: 18px; right: 22px; width: 32px; height: 32px; border-radius: 50%; border: none; background: #f4f6f9; font-size: 18px; cursor: pointer; color: #6c757d; display: flex; align-items: center; justify-content: center; }
.rdd-modal-close:hover { background: #e2e8f0; }
.rdd-modal-title { font-size: 20px; font-weight: 700; color: #1a202c; margin: 0 0 4px; }
.rdd-modal-sub { font-size: 13px; color: #8a92a6; margin: 0 0 22px; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
.detail-item { background: #f8f9fc; border-radius: 12px; padding: 14px 16px; }
.detail-item .d-label { font-size: 11px; color: #8a92a6; text-transform: uppercase; font-weight: 600; margin-bottom: 6px; }
.detail-item .d-value { font-size: 16px; font-weight: 700; color: #1a202c; }
</style>

<div class="rdd-shell">

    <!-- Topbar -->
    <div class="rdd-topbar">
        <div>
            <div class="rdd-breadcrumb">
                <a href="<?php echo $back_url; ?>">Risk Detector</a> &rsaquo; Dashboard
            </div>
            <div class="rdd-topbar-title">
                <?php echo s($course->shortname); ?> &mdash; Student Risk Dashboard
            </div>
        </div>
        <div class="rdd-topbar-actions">
            <span class="rdd-ml-badge">ML Powered</span>
            <a href="<?php echo $conf_url; ?>" class="rdd-btn rdd-btn-ghost">&#9881; Configure</a>
        </div>
    </div>

    <!-- KPI row -->
    <div class="rdd-kpi-row">
        <div class="rdd-kpi accent">
            <div class="rdd-kpi-icon">&#128101;</div>
            <div class="rdd-kpi-label">Total Enrolled</div>
            <div class="rdd-kpi-value"><?php echo $enrolled; ?></div>
            <div class="rdd-kpi-sub"><?php echo $safe_count; ?> not at risk</div>
        </div>
        <div class="rdd-kpi">
            <div class="rdd-kpi-icon icon-bg-red">&#128680;</div>
            <div class="rdd-kpi-label">At-Risk Students</div>
            <div class="rdd-kpi-value" style="color:#e53e3e"><?php echo $atrisk; ?></div>
            <div class="rdd-kpi-sub <?php echo $atrisk > 0 ? 'down' : 'up'; ?>"><?php echo $risk_pct; ?>% of class</div>
        </div>
        <div class="rdd-kpi">
            <div class="rdd-kpi-icon icon-bg-orange">&#128276;</div>
            <div class="rdd-kpi-label">Inactive 14+ Days</div>
            <div class="rdd-kpi-value" style="color:#d69e2e"><?php echo $inactive_count; ?></div>
            <div class="rdd-kpi-sub">No course activity</div>
        </div>
        <div class="rdd-kpi">
            <div class="rdd-kpi-icon icon-bg-green">&#9989;</div>
            <div class="rdd-kpi-label">Low Grade (&lt;50%)</div>
            <div class="rdd-kpi-value" style="color:#c53030"><?php echo $low_grade_count; ?></div>
            <div class="rdd-kpi-sub">Below passing threshold</div>
        </div>
    </div>

    <!-- Charts row -->
    <div class="rdd-charts-row">

        <!-- Donut chart -->
        <div class="rdd-card">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Risk Categories</div>
                    <div class="rdd-card-subtitle">ML classification distribution</div>
                </div>
            </div>
            <canvas id="donutChart" height="180"></canvas>
            <div class="rdd-donut-legend">
                <?php
                $donut_items = [
                    ['label' => 'High risk',     'color' => '#e53e3e', 'count' => $band_counts['High']],
                    ['label' => 'Moderate risk',  'color' => '#ecc94b', 'count' => $band_counts['Moderate']],
                    ['label' => 'Low risk',       'color' => '#48bb78', 'count' => $band_counts['Low']],
                ];
                foreach ($donut_items as $di):
                    $pct = $enrolled > 0 ? round(($di['count'] / $enrolled) * 100) : 0;
                ?>
                <div class="rdd-donut-legend-item">
                    <div class="rdd-donut-legend-left">
                        <span style="width:10px;height:10px;border-radius:50%;background:<?php echo $di['color']; ?>;display:inline-block;flex-shrink:0;"></span>
                        <?php echo $di['label']; ?>
                    </div>
                    <div class="rdd-donut-legend-bar-wrap">
                        <div class="rdd-donut-legend-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $di['color']; ?>;"></div>
                    </div>
                    <div class="rdd-donut-count"><?php echo $di['count']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Feature importance card -->
        <div class="rdd-card">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">ML Feature Importance</div>
                    <div class="rdd-card-subtitle">What the Random Forest model weighs most when classifying risk</div>
                </div>
            </div>
            <div style="margin-top:10px;">
                <div class="fi-item">
                    <span class="fi-label">Average Grade</span>
                    <div class="fi-bar-bg"><div class="fi-bar" style="width:54.8%;background:#6c5ce7;"></div></div>
                    <span class="fi-pct">54.8%</span>
                </div>
                <div class="fi-item">
                    <span class="fi-label">Days Inactive</span>
                    <div class="fi-bar-bg"><div class="fi-bar" style="width:21.3%;background:#00b894;"></div></div>
                    <span class="fi-pct">21.3%</span>
                </div>
                <div class="fi-item">
                    <span class="fi-label">Submission Rate</span>
                    <div class="fi-bar-bg"><div class="fi-bar" style="width:20.8%;background:#fdcb6e;"></div></div>
                    <span class="fi-pct">20.8%</span>
                </div>
                <div class="fi-item">
                    <span class="fi-label">Attendance</span>
                    <div class="fi-bar-bg"><div class="fi-bar" style="width:3.1%;background:#fc8181;"></div></div>
                    <span class="fi-pct">3.1%</span>
                </div>
            </div>
            <div style="margin-top:16px;padding:12px 14px;background:#f0edff;border-radius:10px;font-size:12px;color:#4a5568;line-height:1.6;">
                <strong style="color:#6c5ce7;">Model:</strong> Random Forest (100 trees) &bull;
                <strong>Accuracy:</strong> 95% &bull;
                <strong>Last run:</strong> <?php echo s($predicted_str); ?>
            </div>
        </div>

    </div>

    <!-- Analytics row: Grade distribution + Risk score distribution -->
    <!-- <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
        <div class="rdd-card">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Grade Distribution</div>
                    <div class="rdd-card-subtitle">Number of students in each grade range</div>
                </div>
            </div>
            <canvas id="gradeDistChart" height="200"></canvas>
        </div>
        <div class="rdd-card">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Risk Score Distribution</div>
                    <div class="rdd-card-subtitle">Spread of risk scores across the class</div>
                </div>
            </div>
            <canvas id="scoreDistChart" height="200"></canvas>
        </div>
    </div> -->

    <!-- Analytics row: Feature comparison by band -->
    <!-- <div class="rdd-card" style="margin-bottom:20px;">
        <div class="rdd-card-header">
            <div>
                <div class="rdd-card-title">Feature Comparison by Risk Band</div>
                <div class="rdd-card-subtitle">Average metric values for High, Moderate, and Low risk students</div>
            </div>
            <div style="display:flex;gap:16px;font-size:12px;color:#6c757d;flex-wrap:wrap;">
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:#e53e3e;display:inline-block;"></span> High</span>
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:#ecc94b;display:inline-block;"></span> Moderate</span>
                <span style="display:flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:#48bb78;display:inline-block;"></span> Low</span>
            </div>
        </div>
        <canvas id="bandCompareChart" height="100"></canvas>
    </div> -->

    <!-- Student risk table -->
    <div class="rdd-table-card">
        <div class="rdd-table-header">
            <div>
                <div class="rdd-card-title">Student Risk Classifications</div>
                <div class="rdd-card-subtitle" style="margin-top:2px;"><?php echo $total_students; ?> students in this course</div>
            </div>
        </div>

        <div class="rdd-filters">
            <button class="rdd-filter-btn active" onclick="filterRows('all',this)">All</button>
            <button class="rdd-filter-btn" onclick="filterRows('atrisk',this)">At Risk</button>
            <button class="rdd-filter-btn" onclick="filterRows('High',this)">High</button>
            <button class="rdd-filter-btn" onclick="filterRows('Moderate',this)">Moderate</button>
            <button class="rdd-filter-btn" onclick="filterRows('Low',this)">Low</button>
        </div>

        <div class="rdd-table-wrap">
        <table class="rdd-table" id="studentTable">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Risk Band</th>
                    <th>Score</th>
                    <th>Confidence</th>
                    <th>Avg Grade</th>
                    <th>Days Inactive</th>
                    <th>Attendance</th>
                    <th>Submissions</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($paged_results as $r):
                $band       = ucfirst(strtolower($r->risk_band));
                $band_lower = strtolower($r->risk_band);
                $score      = round($r->risk_score, 1);
                $conf       = round($r->ml_confidence, 1);
                $bar_color  = $band_lower === 'high' ? '#e53e3e' : ($band_lower === 'moderate' ? '#ecc94b' : '#48bb78');

                // Confidence badge class
                $conf_class = $conf >= 80 ? 'conf-high' : ($conf >= 60 ? 'conf-mid' : 'conf-low');

                // Grade color
                $grade_val = round($r->avg_grade_pct, 1);
                $grade_class = $grade_val < 50 ? 'metric-bad' : ($grade_val < 70 ? 'metric-ok' : 'metric-good');

                // Days inactive color
                $days_val = round($r->days_since_active, 1);
                $days_class = $days_val > 14 ? 'metric-bad' : ($days_val > 7 ? 'metric-ok' : 'metric-good');

                // Attendance color
                $att_val = round($r->attendance_pct, 1);
                $att_class = $att_val < 50 ? 'metric-bad' : ($att_val < 75 ? 'metric-ok' : 'metric-good');

                // Submission color
                $sub_val = round($r->submission_pct, 1);
                $sub_class = $sub_val < 50 ? 'metric-bad' : ($sub_val < 75 ? 'metric-ok' : 'metric-good');

                $name_clean  = s($r->firstname . ' ' . $r->lastname);
                $email_clean = s($r->email);

                // Check if notification was sent
                $notif_sent = $DB->record_exists('local_riskdetector_notif', [
                    'courseid'  => $courseid,
                    'studentid' => $r->userid,
                ]);
            ?>
                <tr data-band="<?php echo $band; ?>" data-atrisk="<?php echo $r->is_atrisk; ?>">
                    <td>
                        <div class="student-name"><?php echo $name_clean; ?></div>
                        <div class="student-email"><?php echo $email_clean; ?></div>
                    </td>
                    <td><span class="band-pill band-<?php echo $band_lower; ?>"><?php echo $band; ?></span></td>
                    <td>
                        <div class="score-wrap">
                            <div class="score-bar-bg">
                                <div class="score-bar-fill" style="width:<?php echo min(100, $score); ?>%;background:<?php echo $bar_color; ?>;"></div>
                            </div>
                            <span class="score-num"><?php echo $score; ?></span>
                        </div>
                    </td>
                    <td><span class="conf-badge <?php echo $conf_class; ?>"><?php echo $conf; ?>%</span></td>
                    <td class="metric-cell"><span class="<?php echo $grade_class; ?>"><?php echo $grade_val; ?>%</span></td>
                    <td class="metric-cell"><span class="<?php echo $days_class; ?>"><?php echo $days_val; ?> days</span></td>
                    <td class="metric-cell"><span class="<?php echo $att_class; ?>"><?php echo $att_val; ?>%</span></td>
                    <td class="metric-cell"><span class="<?php echo $sub_class; ?>"><?php echo $sub_val; ?>%</span></td>
                    <td>
                        <button class="action-btn action-view"
                            onclick="showDetail('<?php echo $name_clean; ?>','<?php echo $email_clean; ?>','<?php echo $band; ?>',<?php echo $score; ?>,<?php echo $conf; ?>,<?php echo $grade_val; ?>,<?php echo $days_val; ?>,<?php echo $att_val; ?>,<?php echo $sub_val; ?>)">
                            View
                        </button>
                        <button class="action-btn action-remind"
                            onclick="showNotify(<?php echo $r->userid; ?>,'<?php echo $name_clean; ?>','<?php echo $email_clean; ?>')">
                            Remind
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="rdd-pagination">
            <?php
            $base_url = new moodle_url('/local/riskdetector/dashboard.php', ['courseid' => $courseid]);
            if ($page > 0): ?>
                <a href="<?php echo $base_url . '&page=' . ($page-1); ?>" class="pg-btn">&#8249;</a>
            <?php endif;
            for ($p = 0; $p < $total_pages; $p++):
                if ($total_pages > 7 && $p > 1 && $p < $total_pages - 2 && abs($p - $page) > 1):
                    if ($p == 2 || $p == $total_pages - 3):
                        echo '<span class="pg-btn dots">...</span>';
                    endif;
                    continue;
                endif;
            ?>
                <a href="<?php echo $base_url . '&page=' . $p; ?>"
                   class="pg-btn <?php echo $p === $page ? 'active' : ''; ?>">
                    <?php echo $p + 1; ?>
                </a>
            <?php endfor;
            if ($page < $total_pages - 1): ?>
                <a href="<?php echo $base_url . '&page=' . ($page+1); ?>" class="pg-btn">&#8250;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Detail modal -->
<div class="rdd-overlay" id="detailModal">
    <div class="rdd-modal">
        <button class="rdd-modal-close" onclick="closeModal('detailModal')">&#10005;</button>
        <div class="rdd-modal-title" id="dm-name"></div>
        <div class="rdd-modal-sub" id="dm-email"></div>
        <div class="detail-grid">
            <div class="detail-item"><div class="d-label">Risk Band</div><div class="d-value" id="dm-band"></div></div>
            <div class="detail-item"><div class="d-label">Risk Score</div><div class="d-value" id="dm-score"></div></div>
            <div class="detail-item"><div class="d-label">ML Confidence</div><div class="d-value" id="dm-conf"></div></div>
            <div class="detail-item"><div class="d-label">Avg Grade</div><div class="d-value" id="dm-grade"></div></div>
            <div class="detail-item"><div class="d-label">Days Inactive</div><div class="d-value" id="dm-days"></div></div>
            <div class="detail-item"><div class="d-label">Attendance</div><div class="d-value" id="dm-att"></div></div>
            <div class="detail-item"><div class="d-label">Submission Rate</div><div class="d-value" id="dm-sub"></div></div>
        </div>
        <div style="margin-top:8px;">
            <div style="font-size:13px;font-weight:700;color:#1a202c;margin-bottom:12px;">Student Performance Profile</div>
            <canvas id="radarChart" height="220"></canvas>
        </div>
    </div>
</div>

<!-- Notify modal -->
<div class="rdd-overlay" id="notifyModal">
<div class="rdd-modal" style="max-width:620px;padding:0;overflow:hidden;">
    <div style="padding:22px 24px 16px;border-bottom:1px solid #edf0f7;display:flex;align-items:flex-start;justify-content:space-between;">
        <div>
            <div class="rdd-modal-title" style="margin:0 0 4px;">Notify student</div>
            <div class="rdd-modal-sub" id="nm-name" style="color:#8a92a6;font-size:13px;"></div>
        </div>
        <button class="rdd-modal-close" onclick="closeModal('notifyModal')">&#10005;</button>
    </div>

    <!-- Tab bar -->
    <div style="display:flex;border-bottom:2px solid #edf0f7;padding:0 24px;">
        <button type="button" id="tab-moodle-btn" onclick="switchTab('moodle')"
            style="padding:12px 16px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2.5px solid #6c5ce7;color:#6c5ce7;margin-bottom:-2px;">
            &#128276; Moodle notification
        </button>
        <button type="button" id="tab-email-btn" onclick="switchTab('email')"
            style="padding:12px 16px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2.5px solid transparent;color:#8a92a6;margin-bottom:-2px;">
            &#9993; Email
        </button>
    </div>

    <!-- TAB 1: Moodle notification -->
    <div id="tab-moodle" style="padding:20px 24px;">
        <div style="background:#f0edff;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#4a5568;line-height:1.6;">
            <strong style="color:#6c5ce7;">&#128276; Moodle notification</strong><br>
            Sends directly inside Moodle. Student sees it in their <strong>bell icon</strong>.
        </div>
        <div style="margin-bottom:12px;">
            <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">Subject</label>
            <input type="text" id="nm-moodle-subject" value="<?php echo s($tmpl['subject']); ?>"
                style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;color:#2d3748;box-sizing:border-box;font-family:inherit;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">Message</label>
            <textarea id="nm-moodle-message" rows="6"
                style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;color:#2d3748;box-sizing:border-box;line-height:1.5;"><?php echo s($tmpl['template']); ?></textarea>
        </div>
        <div id="nm-moodle-status" style="display:none;margin-bottom:14px;padding:12px 14px;border-radius:8px;font-size:13px;font-weight:500;line-height:1.5;"></div>
        <div style="display:flex;gap:10px;">
            <button type="button" id="nm-moodle-send-btn" onclick="sendMoodleNotification()"
                style="background:#6c5ce7;color:#fff;border:none;padding:10px 22px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;">
                &#128276; Send notification
            </button>
            <button type="button" onclick="closeModal('notifyModal')"
                style="background:#f7f7f7;color:#555;border:1.5px solid #e2e8f0;padding:10px 18px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
        </div>
    </div>

    <!-- TAB 2: Email -->
    <div id="tab-email" style="display:none;padding:20px 24px;">
    <div style="background:#f0edff;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#4a5568;line-height:1.6;">
        <strong style="color:#6c5ce7;">&#9993; Email notification</strong><br>
        Sends a direct email to the student's registered address via SMTP.
    </div>
 
    <!-- To field (read-only, auto-filled) -->
    <div style="margin-bottom:12px;">
        <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">To</label>
        <div id="nm-email-to"
             style="width:100%;padding:9px 12px;border:1.5px solid #edf0f7;border-radius:8px;
                    font-size:13px;color:#6c757d;background:#f8f9fc;box-sizing:border-box;">
        </div>
    </div>
 
    <!-- Subject -->
    <div style="margin-bottom:12px;">
        <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">Subject</label>
        <input type="text" id="nm-email-subject"
               value="<?php echo s($tmpl['subject']); ?>"
               style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;
                      font-size:14px;color:#2d3748;box-sizing:border-box;font-family:inherit;">
    </div>
 
    <!-- Message body -->
    <div style="margin-bottom:14px;">
        <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">Message</label>
        <textarea id="nm-email-message" rows="8"
                  style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;
                         font-size:13px;font-family:inherit;resize:vertical;color:#2d3748;
                         box-sizing:border-box;line-height:1.6;"><?php echo s($tmpl['template']); ?></textarea>
    </div>
 
    <!-- Placeholder help -->
    <div style="background:#f8f9fc;border-radius:8px;padding:10px 14px;margin-bottom:14px;
                font-size:12px;color:#6c757d;line-height:1.6;">
        <strong>Available placeholders:</strong>
        <code>{student_name}</code> &middot;
        <code>{course_name}</code> &middot;
        <code>{risk_reasons}</code> &middot;
        <code>{last_login}</code> &middot;
        <code>{teacher_name}</code>
    </div>
 
    <!-- Status message -->
    <div id="nm-email-status"
         style="display:none;margin-bottom:14px;padding:12px 14px;border-radius:8px;
                font-size:13px;font-weight:500;line-height:1.5;">
    </div>
 
    <!-- Buttons -->
    <div style="display:flex;gap:10px;">
        <button type="button" id="nm-email-send-btn" onclick="sendEmailNotification()"
                style="background:#6c5ce7;color:#fff;border:none;padding:10px 22px;border-radius:9px;
                       font-size:14px;font-weight:600;cursor:pointer;">
            &#9993; Send email
        </button>
        <button type="button" onclick="closeModal('notifyModal')"
                style="background:#f7f7f7;color:#555;border:1.5px solid #e2e8f0;padding:10px 18px;
                       border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;">
            Cancel
        </button>
    </div>
</div>

</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// ── Global variables ──
var currentStudentId = null;
var currentStudentEmail = null;
var radarChartInstance = null;

// ── Donut chart ──
new Chart(document.getElementById('donutChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['High', 'Moderate', 'Low'],
        datasets: [{
            data: [<?php echo $band_counts['High']; ?>, <?php echo $band_counts['Moderate']; ?>, <?php echo $band_counts['Low']; ?>],
            backgroundColor: ['#e53e3e', '#ecc94b', '#48bb78'],
            borderWidth: 3, borderColor: '#fff',
        }]
    },
    options: {
        responsive: true, cutout: '68%',
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' students' } }
        }
    }
});

// ── Grade distribution chart ──
new Chart(document.getElementById('gradeDistChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($grade_bins)); ?>,
        datasets: [{
            label: 'Students',
            data: <?php echo json_encode(array_values($grade_bins)); ?>,
            backgroundColor: ['#e53e3e', '#fc8181', '#ecc94b', '#48bb78', '#276749'],
            borderRadius: 6,
            borderWidth: 0,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function(ctx) { return ' ' + ctx.parsed.y + ' students'; } } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#8a92a6', font: { size: 12 } } },
            y: { grid: { color: '#f4f6f9' }, ticks: { color: '#8a92a6', font: { size: 12 }, stepSize: 1 }, beginAtZero: true,
                 title: { display: true, text: 'Students', color: '#8a92a6', font: { size: 11 } } }
        }
    }
});

// ── Risk score distribution chart ──
new Chart(document.getElementById('scoreDistChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($score_bins)); ?>,
        datasets: [{
            label: 'Students',
            data: <?php echo json_encode(array_values($score_bins)); ?>,
            backgroundColor: ['#48bb78', '#ecc94b', '#fc8181', '#e53e3e', '#9b2c2c'],
            borderRadius: 6,
            borderWidth: 0,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function(ctx) { return ' ' + ctx.parsed.y + ' students'; } } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#8a92a6', font: { size: 12 } },
                 title: { display: true, text: 'Risk Score', color: '#8a92a6', font: { size: 11 } } },
            y: { grid: { color: '#f4f6f9' }, ticks: { color: '#8a92a6', font: { size: 12 }, stepSize: 1 }, beginAtZero: true,
                 title: { display: true, text: 'Students', color: '#8a92a6', font: { size: 11 } } }
        }
    }
});

// ── Feature comparison by band chart ──
new Chart(document.getElementById('bandCompareChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: ['Avg Grade %', 'Attendance %', 'Submission %', 'Days Inactive'],
        datasets: [
            {
                label: 'High',
                data: [
                    <?php echo $band_chart['High']['grade']; ?>,
                    <?php echo $band_chart['High']['att']; ?>,
                    <?php echo $band_chart['High']['sub']; ?>,
                    <?php echo $band_chart['High']['days']; ?>
                ],
                backgroundColor: '#e53e3e',
                borderRadius: 4,
            },
            {
                label: 'Moderate',
                data: [
                    <?php echo $band_chart['Moderate']['grade']; ?>,
                    <?php echo $band_chart['Moderate']['att']; ?>,
                    <?php echo $band_chart['Moderate']['sub']; ?>,
                    <?php echo $band_chart['Moderate']['days']; ?>
                ],
                backgroundColor: '#ecc94b',
                borderRadius: 4,
            },
            {
                label: 'Low',
                data: [
                    <?php echo $band_chart['Low']['grade']; ?>,
                    <?php echo $band_chart['Low']['att']; ?>,
                    <?php echo $band_chart['Low']['sub']; ?>,
                    <?php echo $band_chart['Low']['days']; ?>
                ],
                backgroundColor: '#48bb78',
                borderRadius: 4,
            },
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#8a92a6', font: { size: 12 } } },
            y: { grid: { color: '#f4f6f9' }, ticks: { color: '#8a92a6', font: { size: 12 } }, beginAtZero: true }
        }
    }
});

// ── Filter rows ──
function filterRows(type, btn) {
    document.querySelectorAll('.rdd-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#studentTable tbody tr').forEach(row => {
        if (type === 'all') { row.style.display = ''; return; }
        if (type === 'atrisk') { row.style.display = row.dataset.atrisk == '1' ? '' : 'none'; return; }
        row.style.display = row.dataset.band === type ? '' : 'none';
    });
}

// ── Detail modal ──
function showDetail(name, email, band, score, conf, grade, days, att, sub) {
    document.getElementById('dm-name').textContent  = name;
    document.getElementById('dm-email').textContent = email;
    document.getElementById('dm-band').textContent  = band;
    document.getElementById('dm-score').textContent = score + ' / 100';
    document.getElementById('dm-conf').textContent  = conf + '%';
    document.getElementById('dm-grade').textContent = grade + '%';
    document.getElementById('dm-days').textContent  = days + ' days';
    document.getElementById('dm-att').textContent   = att + '%';
    document.getElementById('dm-sub').textContent   = sub + '%';

    // Destroy old radar chart if it exists
    if (radarChartInstance) { radarChartInstance.destroy(); }

    // Invert days_since_active: 0 days = 100 (best), 84 days = 0 (worst)
    var activityScore = Math.max(0, Math.round(100 - (days / 84 * 100)));

    var bandColor = band === 'High' ? '#e53e3e' : (band === 'Moderate' ? '#ecc94b' : '#48bb78');
    var bandBg    = band === 'High' ? 'rgba(229,62,62,0.15)' : (band === 'Moderate' ? 'rgba(236,201,75,0.15)' : 'rgba(72,187,120,0.15)');

    radarChartInstance = new Chart(document.getElementById('radarChart').getContext('2d'), {
        type: 'radar',
        data: {
            labels: ['Avg Grade', 'Activity', 'Attendance', 'Submissions'],
            datasets: [{
                label: name,
                data: [grade, activityScore, att, sub],
                borderColor: bandColor,
                backgroundColor: bandBg,
                borderWidth: 2.5,
                pointBackgroundColor: bandColor,
                pointRadius: 5,
                pointHoverRadius: 7,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: { stepSize: 25, color: '#8a92a6', font: { size: 10 }, backdropColor: 'transparent' },
                    grid: { color: '#edf0f7' },
                    pointLabels: { color: '#4a5568', font: { size: 12, weight: '600' } },
                    angleLines: { color: '#edf0f7' },
                }
            }
        }
    });

    document.getElementById('detailModal').classList.add('open');
}

// ── Notify modal ──
function showNotify(uid, name, email) {
    currentStudentId = uid;
    currentStudentEmail = email;
    document.getElementById('nm-name').textContent = 'To: ' + name + ' (' + email + ')';
    var toField = document.getElementById('nm-email-to');
    if (toField) toField.textContent = name + ' <' + email + '>';

    document.getElementById('nm-moodle-status').style.display = 'none';
    var mBtn = document.getElementById('nm-moodle-send-btn');
    mBtn.disabled = false; mBtn.textContent = '🔔 Send notification'; mBtn.style.background = '#6c5ce7';

    document.getElementById('nm-email-status').style.display = 'none';
    var eBtn = document.getElementById('nm-email-send-btn');
    eBtn.disabled = false; eBtn.textContent = '✉ Send email'; eBtn.style.background = '#6c5ce7';

    switchTab('moodle');
    document.getElementById('notifyModal').classList.add('open');
}

function switchTab(tab) {
    document.getElementById('tab-moodle').style.display = tab === 'moodle' ? 'block' : 'none';
    document.getElementById('tab-email').style.display  = tab === 'email'  ? 'block' : 'none';
    document.getElementById('tab-moodle-btn').style.borderBottomColor = tab === 'moodle' ? '#6c5ce7' : 'transparent';
    document.getElementById('tab-moodle-btn').style.color = tab === 'moodle' ? '#6c5ce7' : '#8a92a6';
    document.getElementById('tab-email-btn').style.borderBottomColor = tab === 'email' ? '#6c5ce7' : 'transparent';
    document.getElementById('tab-email-btn').style.color = tab === 'email' ? '#6c5ce7' : '#8a92a6';
}

function sendMoodleNotification() {
    if (!currentStudentId) return;
    var subject = document.getElementById('nm-moodle-subject').value.trim();
    var message = document.getElementById('nm-moodle-message').value.trim();
    var btn = document.getElementById('nm-moodle-send-btn');
    if (!subject || !message) { showNotifyStatus('error', '⚠️ Please fill in both subject and message.'); return; }
    btn.disabled = true; btn.textContent = 'Sending...'; btn.style.background = '#8a92a6';

    var formData = new FormData();
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('courseid', '<?php echo $courseid; ?>');
    formData.append('studentid', currentStudentId);
    formData.append('subject', subject);
    formData.append('message', message);
    formData.append('channel', 'moodle');
    formData.append('ajax', '1');

    fetch('<?php echo $CFG->wwwroot; ?>/local/riskdetector/notify.php', {
        method: 'POST', body: formData, credentials: 'same-origin',
    })
    .then(function(res) { if (!res.ok) 
        { return res.text().then(function(t) { throw new Error('Server error ' + res.status); }); }  return res.json(); })
    .then(function(data) {
        if (data.sent === true) {
            showNotifyStatus('success', '✓ Notification delivered to ' + data.student + '.');
            btn.textContent = '✓ Sent'; btn.style.background = '#00b894';
            updateAlertBadge(currentStudentId, true);
        } else {
            showNotifyStatus('error', '✗ ' + (data.notice || 'Delivery failed.'));
            btn.disabled = false; btn.textContent = '🔔 Retry'; btn.style.background = '#6c5ce7';
        }
    })
    .catch(function(err) {
        showNotifyStatus('error', '✗ Error: ' + err.message);
        btn.disabled = false; btn.textContent = '🔔 Send notification'; btn.style.background = '#6c5ce7';
    });
}

function showNotifyStatus(type, message) {
    var el = document.getElementById('nm-moodle-status');
    el.style.display = 'block';
    el.style.background = type === 'success' ? '#f0fff4' : '#fff5f5';
    el.style.border = type === 'success' ? '1.5px solid #c6f6d5' : '1.5px solid #fed7d7';
    el.style.color = type === 'success' ? '#276749' : '#c53030';
    el.textContent = message;
}

function sendEmailNotification() {
    if (!currentStudentId) return;
 
    var subject = document.getElementById('nm-email-subject').value.trim();
    var message = document.getElementById('nm-email-message').value.trim();
    var btn     = document.getElementById('nm-email-send-btn');
    var status  = document.getElementById('nm-email-status');
 
    if (!subject || !message) {
        showEmailStatus('error', '⚠️ Please fill in both subject and message.');
        return;
    }
 
    btn.disabled = true;
    btn.textContent = 'Sending...';
    btn.style.background = '#8a92a6';
    status.style.display = 'none';
 
    var formData = new FormData();
    formData.append('sesskey',   '<?php echo sesskey(); ?>');
    formData.append('courseid',  '<?php echo $courseid; ?>');
    formData.append('studentid', currentStudentId);
    formData.append('subject',   subject);
    formData.append('message',   message);
 
    fetch('<?php echo $CFG->wwwroot; ?>/local/riskdetector/email.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
    })
    .then(function(res) {
        if (!res.ok) {
            return res.text().then(function(t) {
                throw new Error('Server error ' + res.status);
            });
        }
        return res.json();
    })
    .then(function(data) {
        if (data.sent === true) {
            showEmailStatus('success', '✓ ' + data.notice);
            btn.textContent = '✓ Sent';
            btn.style.background = '#00b894';
            updateAlertBadge(currentStudentId, true);
        } else {
            showEmailStatus('error', '✗ ' + (data.notice || 'Email delivery failed.'));
            btn.disabled = false;
            btn.textContent = '✉ Retry';
            btn.style.background = '#6c5ce7';
        }
    })
    .catch(function(err) {
        showEmailStatus('error', '✗ Error: ' + err.message);
        btn.disabled = false;
        btn.textContent = '✉ Send email';
        btn.style.background = '#6c5ce7';
    });
}
 
function showEmailStatus(type, message) {
    var el = document.getElementById('nm-email-status');
    el.style.display = 'block';
    el.style.background = type === 'success' ? '#f0fff4' : '#fff5f5';
    el.style.border     = type === 'success' ? '1.5px solid #c6f6d5' : '1.5px solid #fed7d7';
    el.style.color      = type === 'success' ? '#276749' : '#c53030';
    el.textContent = message;
}

function showEmailStatus(type, message) {
    var el = document.getElementById('nm-email-status');
    el.style.display = 'block';
    el.style.background = type === 'success' ? '#f0fff4' : '#fff5f5';
    el.style.border = type === 'success' ? '1.5px solid #c6f6d5' : '1.5px solid #fed7d7';
    el.style.color = type === 'success' ? '#276749' : '#c53030';
    el.textContent = message;
}

function updateAlertBadge(uid, sent) {
    document.querySelectorAll('#studentTable tbody tr').forEach(function(row) {
        var remindBtn = row.querySelector('.action-remind');
        if (remindBtn && remindBtn.getAttribute('onclick').includes('showNotify(' + uid + ',')) {
            var badge = row.querySelector('.alert-badge');
            if (badge) {
                badge.className = sent ? 'alert-badge alert-yes' : 'alert-badge alert-no';
                badge.innerHTML = sent ? '&#10003; Yes' : '&#8212; No';
            }
        }
    });
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
</script>
<?php
echo $OUTPUT->footer();