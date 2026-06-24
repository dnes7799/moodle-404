<?php
/**
 * Student Risk Detector — ML Dashboard
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$course   = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
require_capability('local/riskdetector:viewdashboard', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/riskdetector/dashboard.php', ['courseid' => $courseid]));
$PAGE->set_title('Risk Dashboard — ' . $course->shortname);
$PAGE->set_heading('Student Risk Dashboard');
$PAGE->set_pagelayout('base');

global $USER, $DB, $OUTPUT;

// ── ML results ──
$all_results = local_riskdetector_get_results($courseid);
$enrolled    = local_riskdetector_enrolled_count($courseid);

// ── KPI stats + tooltip lists ──
$atrisk = $inactive_count = $low_grade_count = 0;
$atrisk_names   = [];
$inactive_names = [];
$lowgrade_names = [];
$safe_names     = [];

foreach ($all_results as $r) {
    $n = trim($r->firstname . ' ' . $r->lastname);
    if ($r->is_atrisk) {
        $atrisk++;
        $atrisk_names[] = ['n' => $n, 'v' => round($r->risk_score, 1), 'b' => ucfirst(strtolower($r->risk_band))];
    } else {
        $safe_names[] = ['n' => $n, 'v' => round($r->avg_grade_pct, 1)];
    }
    if ($r->days_since_active > 14) {
        $inactive_count++;
        $inactive_names[] = ['n' => $n, 'v' => round($r->days_since_active, 0)];
    }
    if ($r->avg_grade_pct < 50) {
        $low_grade_count++;
        $lowgrade_names[] = ['n' => $n, 'v' => round($r->avg_grade_pct, 1)];
    }
}

$risk_pct   = $enrolled > 0 ? round(($atrisk / $enrolled) * 100, 1) : 0;
$safe_count = $enrolled - $atrisk;

usort($atrisk_names,   function($a, $b) { return $b['v'] <=> $a['v']; });
usort($inactive_names, function($a, $b) { return $b['v'] <=> $a['v']; });
usort($lowgrade_names, function($a, $b) { return $a['v'] <=> $b['v']; });
usort($safe_names,     function($a, $b) { return $b['v'] <=> $a['v']; });

// ── Band counts ──
$band_counts = ['Low' => 0, 'Moderate' => 0, 'High' => 0];
foreach ($all_results as $r) {
    $band = ucfirst(strtolower($r->risk_band));
    if (isset($band_counts[$band])) $band_counts[$band]++;
}

$total_students = count($all_results);

$conf_url = new moodle_url('/local/riskdetector/configure.php', ['courseid' => $courseid]);
$back_url = new moodle_url('/local/riskdetector/index.php');
$tmpl     = local_riskdetector_get_template($courseid);

$first_result  = reset($all_results);
$predicted_str = $first_result ? $first_result->timecalculated : 'Never';

// ── Metric averages by band ──
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

// ── Distribution bins ──
$grade_bins = ['0–30%' => 0, '30–50%' => 0, '50–70%' => 0, '70–85%' => 0, '85–100%' => 0];
$att_bins   = ['0–30%' => 0, '30–50%' => 0, '50–75%' => 0, '75–90%' => 0, '90–100%' => 0];
$sub_bins   = ['0–30%' => 0, '30–50%' => 0, '50–75%' => 0, '75–90%' => 0, '90–100%' => 0];
$days_bins  = ['0–3 d' => 0, '4–7 d' => 0, '8–14 d' => 0, '15–30 d' => 0, '30+ d' => 0];
$score_bins = ['0–20' => 0, '20–40' => 0, '40–60' => 0, '60–80' => 0, '80–100' => 0];

$scatter_points = [];
foreach ($all_results as $r) {
    $g = $r->avg_grade_pct;
    if     ($g < 30) $grade_bins['0–30%']++;
    elseif ($g < 50) $grade_bins['30–50%']++;
    elseif ($g < 70) $grade_bins['50–70%']++;
    elseif ($g < 85) $grade_bins['70–85%']++;
    else             $grade_bins['85–100%']++;

    $a = $r->attendance_pct;
    if     ($a < 30) $att_bins['0–30%']++;
    elseif ($a < 50) $att_bins['30–50%']++;
    elseif ($a < 75) $att_bins['50–75%']++;
    elseif ($a < 90) $att_bins['75–90%']++;
    else             $att_bins['90–100%']++;

    $s = $r->submission_pct;
    if     ($s < 30) $sub_bins['0–30%']++;
    elseif ($s < 50) $sub_bins['30–50%']++;
    elseif ($s < 75) $sub_bins['50–75%']++;
    elseif ($s < 90) $sub_bins['75–90%']++;
    else             $sub_bins['90–100%']++;

    $d = $r->days_since_active;
    if     ($d <= 3)  $days_bins['0–3 d']++;
    elseif ($d <= 7)  $days_bins['4–7 d']++;
    elseif ($d <= 14) $days_bins['8–14 d']++;
    elseif ($d <= 30) $days_bins['15–30 d']++;
    else              $days_bins['30+ d']++;

    $sc = $r->risk_score;
    if     ($sc < 20) $score_bins['0–20']++;
    elseif ($sc < 40) $score_bins['20–40']++;
    elseif ($sc < 60) $score_bins['40–60']++;
    elseif ($sc < 80) $score_bins['60–80']++;
    else              $score_bins['80–100']++;

    $scatter_points[] = [
        'x' => round($r->avg_grade_pct, 1),
        'y' => round($r->risk_score, 1),
        'name' => trim($r->firstname . ' ' . $r->lastname),
        'band' => ucfirst(strtolower($r->risk_band))
    ];
}

$top_risk = array_slice($atrisk_names, 0, 8);

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
.rdd-btn { padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s; }
.rdd-btn-ghost { background: #fff; color: #6c757d; border: 1px solid #e2e8f0; }
.rdd-btn-ghost:hover { background: #f8f9fa; color: #444; text-decoration: none; }
.rdd-ml-badge { background: #00b894; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; }

.rdd-settings-wrap { position: relative; }
.rdd-settings-btn {
    background: #fff; color: #4a5568; border: 1px solid #e2e8f0;
    padding: 0; border-radius: 10px; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    width: 38px; height: 38px; transition: all 0.15s;
}
.rdd-settings-btn:hover, .rdd-settings-btn.open { background: #f0edff; color: #6c5ce7; border-color: #c9c2f7; }
.rdd-settings-btn svg { width: 18px; height: 18px; }
.rdd-settings-menu {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    box-shadow: 0 10px 25px rgba(15,20,40,0.12);
    min-width: 280px; padding: 8px; display: none; z-index: 100;
}
.rdd-settings-menu.open { display: block; }
.rdd-settings-menu-head { font-size: 11px; color: #8a92a6; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; padding: 10px 12px 6px; }
.rdd-settings-menu-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 8px; cursor: pointer; transition: background 0.12s; }
.rdd-settings-menu-row:hover { background: #f0edff; }
.rdd-settings-menu-row input { width: 16px; height: 16px; accent-color: #6c5ce7; cursor: pointer; margin: 0; }
.rdd-settings-menu-row span { font-size: 13px; color: #2d3748; font-weight: 500; flex: 1; }
.rdd-settings-menu-sep { height: 1px; background: #edf0f7; margin: 4px 2px; }

/* KPI cards */
.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
@media(max-width: 900px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } }
@media(max-width: 500px) { .kpi-row { grid-template-columns: 1fr; } }

.kpi {
    position: relative;
    background: #fff;
    border: 1px solid #e8ebf1;
    border-radius: 14px;
    padding: 18px 20px;
    display: grid;
    grid-template-columns: 52px 1fr;
    gap: 14px;
    align-items: center;
    transition: border-color 0.18s, box-shadow 0.18s, transform 0.18s;
}
.kpi:hover {
    border-color: #d4d9e3;
    box-shadow: 0 6px 18px rgba(15,20,40,0.07);
    transform: translateY(-1px);
}
.kpi::before {
    content: '';
    position: absolute;
    left: -1px; top: 14px; bottom: 14px;
    width: 4px;
    border-radius: 4px;
    background: var(--kpi-accent, #6c5ce7);
}
.kpi-icon {
    width: 52px; height: 52px;
    border-radius: 12px;
    background: var(--kpi-icon-bg, #f4f6f9);
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--kpi-accent, #6c5ce7);
}
.kpi-icon svg { width: 24px; height: 24px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.kpi-body { min-width: 0; }
.kpi-label {
    font-size: 11.5px; font-weight: 600;
    color: #8a92a6; text-transform: uppercase;
    letter-spacing: 0.5px; margin-bottom: 6px;
    display: flex; align-items: center; gap: 6px;
}
.kpi-value {
    font-size: 26px; font-weight: 800;
    color: #1a202c; line-height: 1.05;
    font-variant-numeric: tabular-nums;
}
.kpi-sub {
    font-size: 12px; color: #8a92a6; font-weight: 500;
    margin-top: 4px;
}
.kpi-sub .pct {
    display: inline-block; padding: 1px 7px;
    border-radius: 10px; font-weight: 700;
    font-size: 11px; background: #f4f6f9; color: #4a5568;
    margin-right: 4px;
}
.kpi.kpi-red   { --kpi-accent: #e53e3e; --kpi-icon-bg: #fff5f5; }
.kpi.kpi-amber { --kpi-accent: #d69e2e; --kpi-icon-bg: #fffaf0; }
.kpi.kpi-rose  { --kpi-accent: #c53030; --kpi-icon-bg: #fff5f5; }
.kpi.kpi-purple{ --kpi-accent: #6c5ce7; --kpi-icon-bg: #f0edff; }
.kpi.kpi-red   .kpi-value { color: #e53e3e; }
.kpi.kpi-amber .kpi-value { color: #d69e2e; }
.kpi.kpi-rose  .kpi-value { color: #c53030; }
.kpi.kpi-red   .kpi-sub .pct { background: #fff5f5; color: #c53030; }
.kpi.kpi-amber .kpi-sub .pct { background: #fffaf0; color: #92400e; }
.kpi.kpi-rose  .kpi-sub .pct { background: #fff5f5; color: #c53030; }
.kpi.kpi-purple .kpi-sub .pct { background: #f0edff; color: #6c5ce7; }

.kpi-tip {
    position: absolute;
    bottom: calc(100% + 10px); right: 8px;
    width: 290px; max-width: calc(100vw - 40px);
    background: #ffffff; color: #1a202c;
    border: 1px solid #e2e8f0;
    border-radius: 12px; padding: 14px 16px;
    box-shadow: 0 12px 28px rgba(15,20,40,0.12), 0 2px 6px rgba(15,20,40,0.05);
    opacity: 0; pointer-events: none; transform: translateY(6px);
    transition: opacity 0.15s, transform 0.15s;
    z-index: 200;
}
.kpi:hover .kpi-tip { opacity: 1; transform: translateY(0); }
.kpi-tip::after {
    content: ''; position: absolute; top: 100%; right: 20px;
    border: 7px solid transparent; border-top-color: #ffffff;
    filter: drop-shadow(0 2px 1px rgba(15,20,40,0.08));
}
.kpi-tip::before {
    content: ''; position: absolute; top: 100%; right: 19px;
    border: 8px solid transparent; border-top-color: #e2e8f0;
    margin-top: 0;
}
.kpi-tip .t {
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
    font-weight: 700; color: #8a92a6; margin-bottom: 10px;
    padding-bottom: 8px; border-bottom: 1px solid #edf0f7;
}
.kpi-tip ul { list-style: none; margin: 0; padding: 0; max-height: 200px; overflow-y: auto; }
.kpi-tip li {
    display: flex; justify-content: space-between; gap: 10px;
    padding: 6px 0; font-size: 12.5px;
}
.kpi-tip li + li { border-top: 1px dashed #edf0f7; }
.kpi-tip li .nm { color: #2d3748; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.kpi-tip li .vl {
    color: #6c5ce7; font-weight: 700; flex-shrink: 0;
    font-variant-numeric: tabular-nums;
}
.kpi-tip .more { margin-top: 8px; font-size: 11px; color: #8a92a6; text-align: center; padding-top: 8px; border-top: 1px solid #edf0f7; }
.kpi-tip .empty { text-align: center; font-size: 12px; color: #8a92a6; font-style: italic; padding: 6px 0; }

.kpi-tip-colour-red    .t { color: #c53030; }
.kpi-tip-colour-red    li .vl { color: #c53030; }
.kpi-tip-colour-amber  .t { color: #92400e; }
.kpi-tip-colour-amber  li .vl { color: #92400e; }
.kpi-tip-colour-rose   .t { color: #c53030; }
.kpi-tip-colour-rose   li .vl { color: #c53030; }
.kpi-tip-colour-purple .t { color: #6c5ce7; }
.kpi-tip-colour-purple li .vl { color: #6c5ce7; }

/* Charts grid */
.rdd-charts-grid {
    display: grid;
    grid-template-columns: repeat(12, 1fr);
    gap: 14px;
    margin-bottom: 16px;
    width: 100%;
}
@media(max-width: 900px) { .rdd-charts-grid { grid-template-columns: repeat(6, 1fr); } }
@media(max-width: 500px) { .rdd-charts-grid { grid-template-columns: 1fr; } }

.rdd-card {
    background: #fff; border-radius: 14px; padding: 18px 20px;
    border: 1px solid #e8ebf1;
    min-width: 0;
    display: flex; flex-direction: column;
}
.rdd-card.span-donut  { grid-column: span 4; }
.rdd-card.span-metric { grid-column: span 8; }
.rdd-card.span-score  { grid-column: span 6; }
.rdd-card.span-top    { grid-column: span 6; }
.rdd-card.span-dist   { grid-column: span 6; }
.rdd-card.span-scatter{ grid-column: span 6; }

@media(max-width: 900px) {
    .rdd-card.span-donut, .rdd-card.span-metric,
    .rdd-card.span-score, .rdd-card.span-top,
    .rdd-card.span-dist, .rdd-card.span-scatter { grid-column: span 6; }
}
@media(max-width: 500px) {
    .rdd-card.span-donut, .rdd-card.span-metric,
    .rdd-card.span-score, .rdd-card.span-top,
    .rdd-card.span-dist, .rdd-card.span-scatter { grid-column: span 1; }
}

.rdd-card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; gap: 10px; flex-wrap: wrap; }
.rdd-card-title { font-size: 14px; font-weight: 700; color: #1a202c; }
.rdd-card-subtitle { font-size: 11.5px; color: #8a92a6; margin-top: 2px; }

.chart-frame {
    position: relative;
    height: 240px;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}
.chart-frame canvas {
    width: 100% !important;
    height: 100% !important;
    max-width: 100% !important;
}

.rdd-donut-legend { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
.rdd-donut-legend-item { display: flex; align-items: center; justify-content: space-between; font-size: 12.5px; }
.rdd-donut-legend-left { display: flex; align-items: center; gap: 8px; color: #4a5568; }
.rdd-donut-legend-bar-wrap { flex: 1; margin: 0 10px; background: #f0f0f0; border-radius: 4px; height: 5px; }
.rdd-donut-legend-bar { height: 5px; border-radius: 4px; }
.rdd-donut-count { font-weight: 700; color: #1a202c; min-width: 22px; text-align: right; font-size: 12px; }

.rdd-metric-controls { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.rdd-seg { display: inline-flex; background: #f4f6f9; border-radius: 8px; padding: 3px; gap: 2px; }
.rdd-seg button { border: none; background: transparent; padding: 4px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 600; color: #8a92a6; cursor: pointer; transition: all 0.15s; }
.rdd-seg button.active { background: #fff; color: #6c5ce7; box-shadow: 0 1px 3px rgba(15,20,40,0.08); }
.rdd-select { padding: 5px 8px; border: 1.5px solid #e2e8f0; border-radius: 7px; font-size: 12px; color: #2d3748; background: #fff; cursor: pointer; font-family: inherit; font-weight: 500; }
.rdd-select:focus { outline: none; border-color: #6c5ce7; }

/* Table */
.rdd-table-card { background: #fff; border-radius: 14px; border: 1px solid #e8ebf1; overflow: hidden; }
.rdd-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.rdd-table-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px 0; flex-wrap: wrap; gap: 12px; }

.rdd-filter-row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; padding: 14px 22px; flex-wrap: wrap;
}
.rdd-filters { display: flex; gap: 8px; flex-wrap: wrap; }
.rdd-filter-btn { padding: 5px 16px; border-radius: 20px; font-size: 12px; font-weight: 600; border: 1.5px solid #e2e8f0; background: #fff; color: #6c757d; cursor: pointer; transition: all 0.15s; }
.rdd-filter-btn:hover { border-color: #c9c2f7; color: #6c5ce7; }
.rdd-filter-btn.active { background: #6c5ce7; border-color: #6c5ce7; color: #fff; }

.rdd-search { position: relative; display: inline-flex; align-items: center; }
.rdd-search svg { position: absolute; left: 10px; width: 14px; height: 14px; color: #8a92a6; pointer-events: none; }
.rdd-search input {
    width: 220px;
    padding: 7px 30px 7px 30px;
    border: 1.5px solid #e2e8f0; border-radius: 20px;
    font-size: 13px; color: #2d3748; background: #fff;
    font-family: inherit;
    transition: all 0.15s;
}
.rdd-search input::placeholder { color: #a0aec0; }
.rdd-search input:focus {
    outline: none;
    border-color: #6c5ce7;
    box-shadow: 0 0 0 3px rgba(108,92,231,0.12);
}
.rdd-search-clear {
    position: absolute; right: 6px; width: 20px; height: 20px;
    border: none; background: transparent;
    color: #a0aec0; cursor: pointer; display: none;
    align-items: center; justify-content: center;
    border-radius: 50%; transition: all 0.12s;
    padding: 0;
}
.rdd-search-clear:hover { background: #edf0f7; color: #4a5568; }
.rdd-search-clear.show { display: inline-flex; }
.rdd-search-clear svg { width: 10px; height: 10px; }
.rdd-search .hits {
    position: absolute; right: -10px; top: -8px;
    background: #6c5ce7; color: #fff;
    font-size: 10px; font-weight: 700;
    min-width: 18px; height: 18px;
    border-radius: 9px; padding: 0 5px;
    display: none; align-items: center; justify-content: center;
    box-shadow: 0 2px 6px rgba(108,92,231,0.35);
}
.rdd-search .hits.show { display: inline-flex; }

@media (max-width: 720px) {
    .rdd-filter-row { flex-direction: column; align-items: stretch; }
    .rdd-search input { width: 100%; }
}

.rdd-table { width: 100%; border-collapse: collapse; table-layout: auto; }
.rdd-table th { padding: 10px 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #8a92a6; background: #f8f9fc; border-bottom: 1px solid #edf0f7; text-align: left; }
.rdd-table td { padding: 12px; font-size: 13px; border-bottom: 1px solid #f4f6f9; vertical-align: middle; color: #2d3748; }
.rdd-table tr:last-child td { border-bottom: none; }
.rdd-table tr:hover td { background: #fafbff; }
.rdd-table tr.rdd-row-selected td { background: #f0edff; }
.student-name { font-weight: 600; color: #1a202c; font-size: 13px; }
.student-email { font-size: 10px; color: #a0aec0; margin-top: 2px; }

.hl-match { background: #fff3bf; color: #744210; padding: 0 1px; border-radius: 2px; font-weight: 600; }

.rdd-empty-row td { padding: 50px 20px !important; text-align: center; color: #8a92a6; font-size: 13px; }

.rdd-check-col { width: 38px; padding-left: 16px !important; padding-right: 4px !important; text-align: center; }
.rdd-check { width: 16px; height: 16px; cursor: pointer; accent-color: #6c5ce7; margin: 0; vertical-align: middle; }

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

.action-buttons {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    min-width: 0;
}
.action-btn {
    padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
    border: none; cursor: pointer; transition: all 0.15s;
    white-space: nowrap;
    flex: 0 0 auto;
}
.action-view { background: #eef2ff; color: #4c63d2; }
.action-view:hover { background: #e0e7ff; }
.action-remind { background: #fff5f5; color: #e53e3e; }
.action-remind:hover { background: #fed7d7; }

.rdd-pagination { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 22px; flex-wrap: wrap; border-top: 1px solid #f4f6f9; }
.rdd-page-info { font-size: 12px; color: #8a92a6; }
.rdd-page-info b { color: #2d3748; font-weight: 700; }
.rdd-page-nav { display: flex; align-items: center; gap: 4px; }
.pg-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 8px; border: 1.5px solid #e2e8f0; background: #fff; font-size: 13px; font-weight: 600; color: #4a5568; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.15s; }
.pg-btn:hover:not(:disabled):not(.active) { background: #eef2ff; border-color: #6c5ce7; color: #6c5ce7; }
.pg-btn.active { background: #6c5ce7; border-color: #6c5ce7; color: #fff; }
.pg-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.pg-btn.dots { border: none; background: none; cursor: default; color: #8a92a6; }
.pg-btn.dots:hover { background: none; color: #8a92a6; border: none; }

.rdd-bulk-bar {
    position: fixed; bottom: 24px; left: 50%;
    transform: translateX(-50%) translateY(30px);
    background: #1a1a2e; color: #fff;
    padding: 12px 16px 12px 20px; border-radius: 14px;
    box-shadow: 0 10px 30px rgba(15,20,40,0.25);
    display: flex; align-items: center; gap: 14px;
    font-size: 13px; z-index: 500;
    opacity: 0; pointer-events: none;
    transition: opacity 0.25s, transform 0.25s;
    flex-wrap: wrap; max-width: calc(100% - 40px);
}
.rdd-bulk-bar.show { opacity: 1; pointer-events: auto; transform: translateX(-50%) translateY(0); }
.rdd-bulk-count { font-weight: 700; }
.rdd-bulk-count b { color: #fdcb6e; font-size: 15px; margin-right: 2px; }
.rdd-bulk-sep { width: 1px; height: 22px; background: rgba(255,255,255,0.2); }
.rdd-bulk-btn { background: #6c5ce7; color: #fff; border: none; padding: 7px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background 0.15s; }
.rdd-bulk-btn:hover { background: #5a4bd1; }
.rdd-bulk-btn.ghost { background: rgba(255,255,255,0.1); }
.rdd-bulk-btn.ghost:hover { background: rgba(255,255,255,0.18); }

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

.bulk-progress-wrap { margin-top: 14px; }
.bulk-progress-bar { height: 10px; background: #edf0f7; border-radius: 5px; overflow: hidden; margin-bottom: 8px; }
.bulk-progress-fill { height: 100%; background: linear-gradient(90deg,#6c5ce7,#a855f7); width: 0%; transition: width 0.2s; }
.bulk-progress-txt { font-size: 12px; color: #4a5568; display:flex; justify-content:space-between; }
.bulk-log { margin-top: 12px; max-height: 160px; overflow-y: auto; background: #f8f9fc; border: 1px solid #edf0f7; border-radius: 8px; padding: 10px 12px; font-size: 12px; line-height: 1.7; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.bulk-log .ok { color: #276749; }
.bulk-log .err { color: #c53030; }
.bulk-log .pend { color: #8a92a6; }

.rdd-info-banner {
    background: #f0edff;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #4a5568;
    line-height: 1.6;
}
.rdd-info-banner strong { color: #6c5ce7; }
.rdd-info-banner small { color: #8a92a6; }
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
            <a href="<?php echo $conf_url; ?>" class="rdd-btn rdd-btn-ghost">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                Configure
            </a>

            <div class="rdd-settings-wrap">
                <button type="button" class="rdd-settings-btn" id="settingsBtn" onclick="toggleSettingsMenu(event)" title="Dashboard settings">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                </button>
                <div class="rdd-settings-menu" id="settingsMenu">
                    <div class="rdd-settings-menu-head">Show on dashboard</div>
                    <label class="rdd-settings-menu-row">
                        <input type="checkbox" id="cfg-chart-donut" checked onchange="onSettingChange()">
                        <span>Risk Categories (donut)</span>
                    </label>
                    <label class="rdd-settings-menu-row">
                        <input type="checkbox" id="cfg-chart-metric" checked onchange="onSettingChange()">
                        <span>Metric Explorer (bar)</span>
                    </label>
                    <label class="rdd-settings-menu-row">
                        <input type="checkbox" id="cfg-chart-score" onchange="onSettingChange()">
                        <span>Risk Score Spread (line)</span>
                    </label>
                    <label class="rdd-settings-menu-row">
                        <input type="checkbox" id="cfg-chart-top" onchange="onSettingChange()">
                        <span>Top At-Risk Students (bar)</span>
                    </label>
                    <label class="rdd-settings-menu-row">
                        <input type="checkbox" id="cfg-chart-dist" onchange="onSettingChange()">
                        <span>Metric Distribution (bar)</span>
                    </label>
                    <label class="rdd-settings-menu-row">
                        <input type="checkbox" id="cfg-chart-scatter" onchange="onSettingChange()">
                        <span>Grade vs Risk Score (scatter)</span>
                    </label>
                    <div class="rdd-settings-menu-sep"></div>
                    <div style="padding:10px 12px 6px; font-size: 11px; color: #a0aec0; line-height: 1.5;">
                        Saved per-course in your browser. Active charts are
                        included when you export to PDF.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="kpi-row">

        <!-- Total Enrolled -->
        <div class="kpi kpi-purple">
            <div class="kpi-icon">
                <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Total Enrolled</div>
                <div class="kpi-value"><?php echo $enrolled; ?></div>
                <div class="kpi-sub"><span class="pct"><?php echo $safe_count; ?></span>not at risk</div>
            </div>
            <div class="kpi-tip kpi-tip-colour-purple">
                <div class="t">Safe students &middot; top grades</div>
                <?php if (empty($safe_names)): ?>
                    <div class="empty">No safe students yet.</div>
                <?php else: ?>
                <ul>
                    <?php foreach (array_slice($safe_names, 0, 8) as $s): ?>
                        <li><span class="nm"><?php echo s($s['n']); ?></span><span class="vl"><?php echo $s['v']; ?>%</span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($safe_names) > 8): ?>
                    <div class="more">+ <?php echo count($safe_names) - 8; ?> more</div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- At-Risk -->
        <div class="kpi kpi-red">
            <div class="kpi-icon">
                <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">At-Risk Students</div>
                <div class="kpi-value"><?php echo $atrisk; ?></div>
                <div class="kpi-sub"><span class="pct"><?php echo $risk_pct; ?>%</span>of class</div>
            </div>
            <div class="kpi-tip kpi-tip-colour-red">
                <div class="t">At-risk students (highest score)</div>
                <?php if (empty($atrisk_names)): ?>
                    <div class="empty">No at-risk students — great work!</div>
                <?php else: ?>
                <ul>
                    <?php foreach (array_slice($atrisk_names, 0, 8) as $s): ?>
                        <li><span class="nm"><?php echo s($s['n']); ?></span><span class="vl"><?php echo $s['v']; ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($atrisk_names) > 8): ?>
                    <div class="more">+ <?php echo count($atrisk_names) - 8; ?> more</div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Inactive -->
        <div class="kpi kpi-amber">
            <div class="kpi-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Inactive 14+ Days</div>
                <div class="kpi-value"><?php echo $inactive_count; ?></div>
                <div class="kpi-sub">No course activity</div>
            </div>
            <div class="kpi-tip kpi-tip-colour-amber">
                <div class="t">Most inactive students</div>
                <?php if (empty($inactive_names)): ?>
                    <div class="empty">Everyone active in the last 14 days</div>
                <?php else: ?>
                <ul>
                    <?php foreach (array_slice($inactive_names, 0, 8) as $s): ?>
                        <li><span class="nm"><?php echo s($s['n']); ?></span><span class="vl"><?php echo $s['v']; ?> d</span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($inactive_names) > 8): ?>
                    <div class="more">+ <?php echo count($inactive_names) - 8; ?> more</div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Low grade -->
        <div class="kpi kpi-rose">
            <div class="kpi-icon">
                <svg viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline><polyline points="17 18 23 18 23 12"></polyline></svg>
            </div>
            <div class="kpi-body">
                <div class="kpi-label">Low Grade (&lt;50%)</div>
                <div class="kpi-value"><?php echo $low_grade_count; ?></div>
                <div class="kpi-sub">Below passing threshold</div>
            </div>
            <div class="kpi-tip kpi-tip-colour-rose">
                <div class="t">Below 50% (lowest first)</div>
                <?php if (empty($lowgrade_names)): ?>
                    <div class="empty">No students below the pass mark</div>
                <?php else: ?>
                <ul>
                    <?php foreach (array_slice($lowgrade_names, 0, 8) as $s): ?>
                        <li><span class="nm"><?php echo s($s['n']); ?></span><span class="vl"><?php echo $s['v']; ?>%</span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($lowgrade_names) > 8): ?>
                    <div class="more">+ <?php echo count($lowgrade_names) - 8; ?> more</div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Charts grid -->
    <div class="rdd-charts-grid" id="chartsGrid">

        <div class="rdd-card span-donut" id="card-donut">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Risk Categories</div>
                    <div class="rdd-card-subtitle">ML classification distribution</div>
                </div>
            </div>
            <div class="chart-frame"><canvas id="donutChart"></canvas></div>
            <div class="rdd-donut-legend">
                <?php
                $donut_items = [
                    ['label' => 'High risk',     'color' => '#e53e3e', 'count' => $band_counts['High']],
                    ['label' => 'Moderate risk', 'color' => '#ecc94b', 'count' => $band_counts['Moderate']],
                    ['label' => 'Low risk',      'color' => '#48bb78', 'count' => $band_counts['Low']],
                ];
                foreach ($donut_items as $di):
                    $pct = $enrolled > 0 ? round(($di['count'] / $enrolled) * 100) : 0;
                ?>
                <div class="rdd-donut-legend-item">
                    <div class="rdd-donut-legend-left">
                        <span style="width:9px;height:9px;border-radius:50%;background:<?php echo $di['color']; ?>;display:inline-block;flex-shrink:0;"></span>
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

        <div class="rdd-card span-metric" id="card-metric">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Metric Explorer</div>
                    <div class="rdd-card-subtitle">Pick a metric, compare across bands</div>
                </div>
                <div class="rdd-metric-controls">
                    <select id="metricSelect" class="rdd-select" onchange="renderMetricChart()">
                        <option value="grade">Average Grade</option>
                        <option value="days">Days Inactive</option>
                        <option value="att">Attendance</option>
                        <option value="sub">Submission Rate</option>
                    </select>
                    <div class="rdd-seg">
                        <button type="button" id="viewBand" class="active" onclick="setMetricView('band')">By Band</button>
                        <button type="button" id="viewDist" onclick="setMetricView('dist')">Distribution</button>
                    </div>
                </div>
            </div>
            <div class="chart-frame"><canvas id="metricChart"></canvas></div>
        </div>

        <div class="rdd-card span-score" id="card-score">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Risk Score Spread</div>
                    <div class="rdd-card-subtitle">Distribution curve across score ranges</div>
                </div>
            </div>
            <div class="chart-frame"><canvas id="scoreChart"></canvas></div>
        </div>

        <div class="rdd-card span-top" id="card-top">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Top At-Risk Students</div>
                    <div class="rdd-card-subtitle">Highest risk scores first</div>
                </div>
            </div>
            <div class="chart-frame"><canvas id="topChart"></canvas></div>
        </div>

        <div class="rdd-card span-dist" id="card-dist">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Metric Distribution</div>
                    <div class="rdd-card-subtitle">Spread across ranges</div>
                </div>
                <div class="rdd-metric-controls">
                    <select id="distSelect" class="rdd-select" onchange="renderDistChart()">
                        <option value="grade">Grades</option>
                        <option value="att">Attendance</option>
                        <option value="sub">Submission Rate</option>
                        <option value="days">Days Inactive</option>
                    </select>
                </div>
            </div>
            <div class="chart-frame"><canvas id="distChart"></canvas></div>
        </div>

        <div class="rdd-card span-scatter" id="card-scatter">
            <div class="rdd-card-header">
                <div>
                    <div class="rdd-card-title">Grade vs Risk Score</div>
                    <div class="rdd-card-subtitle">Each dot is a student — coloured by band</div>
                </div>
            </div>
            <div class="chart-frame"><canvas id="scatterChart"></canvas></div>
        </div>

    </div>

    <!-- Student table -->
    <div class="rdd-table-card">
        <div class="rdd-table-header">
            <div>
                <div class="rdd-card-title">Student Risk Classifications</div>
                <div class="rdd-card-subtitle" style="margin-top:2px;">
                    <?php echo $total_students; ?> students in this course
                </div>
            </div>
        </div>

        <div class="rdd-filter-row">
            <div class="rdd-filters">
                <button class="rdd-filter-btn active" data-filter="all" onclick="setFilter('all', this)">All</button>
                <button class="rdd-filter-btn" data-filter="atrisk" onclick="setFilter('atrisk', this)">At Risk</button>
                <button class="rdd-filter-btn" data-filter="High" onclick="setFilter('High', this)">High</button>
                <button class="rdd-filter-btn" data-filter="Moderate" onclick="setFilter('Moderate', this)">Moderate</button>
                <button class="rdd-filter-btn" data-filter="Low" onclick="setFilter('Low', this)">Low</button>
            </div>

            <div class="rdd-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="searchInput" placeholder="Search student, email, ID…"
                       oninput="onSearchInput(this.value)" autocomplete="off">
                <button type="button" class="rdd-search-clear" id="searchClear" onclick="clearSearch()" title="Clear search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
                <span class="hits" id="searchHits">0</span>
            </div>
        </div>

        <div class="rdd-table-wrap">
        <table class="rdd-table" id="studentTable">
            <thead>
                <tr>
                    <th class="rdd-check-col">
                        <input type="checkbox" class="rdd-check" id="selectAllChk"
                               onclick="toggleSelectAll(this.checked)" title="Select all visible">
                    </th>
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
            <tbody id="studentTableBody">
            <?php foreach ($all_results as $r):
                $band       = ucfirst(strtolower($r->risk_band));
                $band_lower = strtolower($r->risk_band);
                $score      = round($r->risk_score, 1);
                $conf       = round($r->ml_confidence, 1);
                $bar_color  = $band_lower === 'high' ? '#e53e3e' : ($band_lower === 'moderate' ? '#ecc94b' : '#48bb78');
                $conf_class = $conf >= 80 ? 'conf-high' : ($conf >= 60 ? 'conf-mid' : 'conf-low');
                $grade_val  = round($r->avg_grade_pct, 1);
                $grade_class = $grade_val < 50 ? 'metric-bad' : ($grade_val < 70 ? 'metric-ok' : 'metric-good');
                $days_val   = round($r->days_since_active, 1);
                $days_class = $days_val > 14 ? 'metric-bad' : ($days_val > 7 ? 'metric-ok' : 'metric-good');
                $att_val    = round($r->attendance_pct, 1);
                $att_class  = $att_val < 50 ? 'metric-bad' : ($att_val < 75 ? 'metric-ok' : 'metric-good');
                $sub_val    = round($r->submission_pct, 1);
                $sub_class  = $sub_val < 50 ? 'metric-bad' : ($sub_val < 75 ? 'metric-ok' : 'metric-good');
                $name_clean  = s($r->firstname . ' ' . $r->lastname);
                $email_clean = s($r->email);

                $search_blob = strtolower(
                    ($r->firstname ?? '') . ' ' . ($r->lastname ?? '') . ' ' .
                    ($r->email ?? '') . ' ' . ($r->idnumber ?? '')
                );
            ?>
                <tr data-band="<?php echo $band; ?>" data-atrisk="<?php echo $r->is_atrisk; ?>"
                    data-userid="<?php echo (int)$r->userid; ?>"
                    data-name="<?php echo $name_clean; ?>"
                    data-email="<?php echo $email_clean; ?>"
                    data-search="<?php echo s($search_blob); ?>">
                    <td class="rdd-check-col">
                        <input type="checkbox" class="rdd-check rdd-row-check"
                               value="<?php echo (int)$r->userid; ?>"
                               onclick="onRowCheck(this)">
                    </td>
                    <td>
                        <div class="student-name" data-orig="<?php echo $name_clean; ?>"><?php echo $name_clean; ?></div>
                        <div class="student-email" data-orig="<?php echo $email_clean; ?>"><?php echo $email_clean; ?></div>
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
                        <div class="action-buttons">
                            <button class="action-btn action-view"
                                onclick="showDetail('<?php echo $name_clean; ?>','<?php echo $email_clean; ?>','<?php echo $band; ?>',<?php echo $score; ?>,<?php echo $conf; ?>,<?php echo $grade_val; ?>,<?php echo $days_val; ?>,<?php echo $att_val; ?>,<?php echo $sub_val; ?>)">
                                View
                            </button>
                            <button class="action-btn action-remind"
                                onclick="showNotify(<?php echo $r->userid; ?>,'<?php echo $name_clean; ?>','<?php echo $email_clean; ?>')">
                                Notify
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="rdd-pagination" id="paginationWrap" style="display:none;">
            <div class="rdd-page-info" id="pageInfo">&nbsp;</div>
            <div class="rdd-page-nav" id="pageNav"></div>
        </div>
    </div>

</div>

<!-- Bulk action bar -->
<div class="rdd-bulk-bar" id="bulkBar">
    <div class="rdd-bulk-count"><b id="bulkCount">0</b> selected</div>
    <div class="rdd-bulk-sep"></div>
    <button type="button" class="rdd-bulk-btn" onclick="openBulkNotify()">Bulk notify</button>
    <button type="button" class="rdd-bulk-btn ghost" onclick="doExport('csv')">Export CSV</button>
    <button type="button" class="rdd-bulk-btn ghost" onclick="doExport('pdf')">Export PDF</button>
    <button type="button" class="rdd-bulk-btn ghost" onclick="clearSelection()">Clear</button>
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

<!-- Single notify modal (unified — no email/popup tabs) -->
<div class="rdd-overlay" id="notifyModal">
<div class="rdd-modal" style="max-width:620px;padding:0;overflow:hidden;">
    <div style="padding:22px 24px 16px;border-bottom:1px solid #edf0f7;display:flex;align-items:flex-start;justify-content:space-between;">
        <div>
            <div class="rdd-modal-title" style="margin:0 0 4px;">Send risk alert</div>
            <div class="rdd-modal-sub" id="nm-name" style="color:#8a92a6;font-size:13px;"></div>
        </div>
        <button class="rdd-modal-close" onclick="closeModal('notifyModal')">&#10005;</button>
    </div>
    <div style="padding:20px 24px;">
        <div class="rdd-info-banner">
            <strong>How it's delivered</strong><br>
            One notification is sent. The student receives it via the channels they have enabled
            in their <em>Notification preferences</em> — typically a popup in the bell menu plus
            an email. Mobile users with the Moodle app may also get a push.
        </div>
        <div style="margin-bottom:12px;">
            <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">Subject (optional)</label>
            <input type="text" id="nm-subject" placeholder="Leave blank to use default subject"
                value="<?php echo s($tmpl['subject']); ?>"
                style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;color:#2d3748;box-sizing:border-box;font-family:inherit;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">Message (optional)</label>
            <textarea id="nm-message" rows="6"
                placeholder="Leave blank to send the default message with auto-generated risk reasons."
                style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;color:#2d3748;box-sizing:border-box;line-height:1.5;"><?php echo s($tmpl['template']); ?></textarea>
            <div style="font-size:11px;color:#8a92a6;margin-top:4px;">
                If left blank, the system message will include the student's risk reasons
                (low grade, attendance, etc.) automatically.
            </div>
        </div>
        <div id="nm-status" style="display:none;margin-bottom:14px;padding:12px 14px;border-radius:8px;font-size:13px;font-weight:500;line-height:1.5;"></div>
        <div style="display:flex;gap:10px;">
            <button type="button" id="nm-send-btn" onclick="sendNotification()"
                style="background:#6c5ce7;color:#fff;border:none;padding:10px 22px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;">
                Send notification
            </button>
            <button type="button" onclick="closeModal('notifyModal')"
                style="background:#f7f7f7;color:#555;border:1.5px solid #e2e8f0;padding:10px 18px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
        </div>
    </div>
</div>
</div>

<!-- Bulk notify modal (unified) -->
<div class="rdd-overlay" id="bulkNotifyModal">
<div class="rdd-modal" style="max-width:660px;padding:0;overflow:hidden;">
    <div style="padding:22px 24px 16px;border-bottom:1px solid #edf0f7;display:flex;align-items:flex-start;justify-content:space-between;">
        <div>
            <div class="rdd-modal-title" style="margin:0 0 4px;">Bulk notify students</div>
            <div class="rdd-modal-sub" id="bnm-count" style="color:#8a92a6;font-size:13px;">0 students selected</div>
        </div>
        <button class="rdd-modal-close" onclick="closeModal('bulkNotifyModal')">&#10005;</button>
    </div>
    <div style="padding:20px 24px;">
        <div class="rdd-info-banner">
            <strong>How it's delivered</strong><br>
            Each selected student receives one notification, routed by their own
            preferences (popup, email, mobile).
        </div>
        <div style="margin-bottom:12px;">
            <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">Subject (optional)</label>
            <input type="text" id="bnm-subject" placeholder="Leave blank to use default"
                value="<?php echo s($tmpl['subject']); ?>"
                style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;color:#2d3748;box-sizing:border-box;font-family:inherit;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="font-size:13px;font-weight:600;color:#4a5568;display:block;margin-bottom:6px;">Message (optional)</label>
            <textarea id="bnm-message" rows="6"
                placeholder="Leave blank to send each student the default message with their personalised risk reasons."
                style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical;color:#2d3748;box-sizing:border-box;line-height:1.5;"><?php echo s($tmpl['template']); ?></textarea>
        </div>
        <div id="bnm-progress" class="bulk-progress-wrap" style="display:none;">
            <div class="bulk-progress-bar"><div class="bulk-progress-fill" id="bnm-bar"></div></div>
            <div class="bulk-progress-txt"><span id="bnm-ptext">Starting&hellip;</span><span id="bnm-pcount">0 / 0</span></div>
            <div id="bnm-log" class="bulk-log"></div>
        </div>
        <div style="display:flex;gap:10px;margin-top:16px;">
            <button type="button" id="bnm-send-btn" onclick="sendBulkNotifications()"
                style="background:#6c5ce7;color:#fff;border:none;padding:10px 22px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;">
                Send to <span id="bnm-btn-count">0</span> students
            </button>
            <button type="button" onclick="closeModal('bulkNotifyModal')"
                style="background:#f7f7f7;color:#555;border:1.5px solid #e2e8f0;padding:10px 18px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;">Close</button>
        </div>
    </div>
</div>
</div>

<!-- Export form -->
<form id="pdfExportForm" method="post" action="<?php echo new moodle_url('/local/riskdetector/export.php'); ?>" target="_blank" style="display:none;">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="courseid" value="<?php echo (int)$courseid; ?>">
    <input type="hidden" name="format" value="pdf">
    <input type="hidden" name="ids" id="pdfExportIds" value="">
    <input type="hidden" name="band" id="pdfExportBand" value="all">
    <input type="hidden" name="charts_json" id="pdfExportCharts" value="">
</form>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
var RDD = {
    courseid:   <?php echo (int)$courseid; ?>,
    sesskey:    '<?php echo sesskey(); ?>',
    wwwroot:    '<?php echo $CFG->wwwroot; ?>',
    notifyUrl:  '<?php echo $CFG->wwwroot; ?>/local/riskdetector/notify.php',
    exportUrl:  '<?php echo $CFG->wwwroot; ?>/local/riskdetector/export.php'
};

var BAND_COUNTS  = { High: <?php echo $band_counts['High']; ?>, Moderate: <?php echo $band_counts['Moderate']; ?>, Low: <?php echo $band_counts['Low']; ?> };
var BAND_METRICS = <?php echo json_encode($band_chart); ?>;
var DIST_BINS = {
    grade: { labels: <?php echo json_encode(array_keys($grade_bins)); ?>, data: <?php echo json_encode(array_values($grade_bins)); ?> },
    days:  { labels: <?php echo json_encode(array_keys($days_bins)); ?>,  data: <?php echo json_encode(array_values($days_bins)); ?> },
    att:   { labels: <?php echo json_encode(array_keys($att_bins)); ?>,   data: <?php echo json_encode(array_values($att_bins)); ?> },
    sub:   { labels: <?php echo json_encode(array_keys($sub_bins)); ?>,   data: <?php echo json_encode(array_values($sub_bins)); ?> }
};
var SCORE_BINS   = { labels: <?php echo json_encode(array_keys($score_bins)); ?>, data: <?php echo json_encode(array_values($score_bins)); ?> };
var TOP_RISK     = <?php echo json_encode($top_risk); ?>;
var SCATTER_PTS  = <?php echo json_encode($scatter_points); ?>;
var METRIC_LABELS = { grade: 'Avg Grade %', days: 'Days Inactive', att: 'Attendance %', sub: 'Submission Rate %' };
var METRIC_UNIT   = { grade: '%', days: ' days', att: '%', sub: '%' };

var currentStudentId = null;
var radarChartInstance = null;
var metricChartInstance = null;
var distChartInstance   = null;
var donutInstance = null, scoreInstance = null, topInstance = null, scatterInstance = null;
var metricView = 'band';
var currentFilter = 'all';
var currentSearch = '';
var currentPage   = 1;
var PER_PAGE      = 20;

// ── Settings ──
var SETTINGS_KEY = 'rdd_graph_settings_v4_course_' + RDD.courseid;
var CHART_IDS = ['donut', 'metric', 'score', 'top', 'dist', 'scatter'];

function loadSettings() {
    var defaults = { donut: true, metric: true, score: false, top: false, dist: false, scatter: false };
    try {
        var raw = localStorage.getItem(SETTINGS_KEY);
        if (!raw) return defaults;
        var s = JSON.parse(raw);
        return Object.assign({}, defaults, s);
    } catch (e) { return defaults; }
}
function saveSettings(s) { try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(s)); } catch (e) {} }

function applySettings(s) {
    var active = [];
    CHART_IDS.forEach(function(id) {
        var card = document.getElementById('card-' + id);
        var show = !!s[id];
        card.style.display = show ? 'flex' : 'none';
        if (show) active.push(id);
    });
    CHART_IDS.forEach(function(id) {
        document.getElementById('card-' + id).style.gridColumn = '';
    });
    if (active.length === 1) {
        document.getElementById('card-' + active[0]).style.gridColumn = '4 / span 6';
    } else if (active.length === 2) {
        active.forEach(function(id) {
            document.getElementById('card-' + id).style.gridColumn = 'span 6';
        });
    }

    if (s.donut)   ensureDonut();
    if (s.metric)  renderMetricChart();
    if (s.score)   ensureScore();
    if (s.top)     ensureTop();
    if (s.dist)    renderDistChart();
    if (s.scatter) ensureScatter();
}

function onSettingChange() {
    var s = {};
    CHART_IDS.forEach(function(id) {
        s[id] = document.getElementById('cfg-chart-' + id).checked;
    });
    saveSettings(s);
    applySettings(s);
}

function toggleSettingsMenu(e) {
    e.stopPropagation();
    document.getElementById('settingsMenu').classList.toggle('open');
    document.getElementById('settingsBtn').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    var menu = document.getElementById('settingsMenu');
    if (menu && menu.classList.contains('open') &&
        !menu.contains(e.target) &&
        !document.getElementById('settingsBtn').contains(e.target)) {
        menu.classList.remove('open');
        document.getElementById('settingsBtn').classList.remove('open');
    }
});

// ── Charts ──
var COMMON = { responsive: true, maintainAspectRatio: false, animation: { duration: 400 } };

function ensureDonut() {
    if (donutInstance) return;
    donutInstance = new Chart(document.getElementById('donutChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['High', 'Moderate', 'Low'],
            datasets: [{
                data: [BAND_COUNTS.High, BAND_COUNTS.Moderate, BAND_COUNTS.Low],
                backgroundColor: ['#e53e3e', '#ecc94b', '#48bb78'],
                borderWidth: 3, borderColor: '#fff'
            }]
        },
        options: Object.assign({}, COMMON, {
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(ctx) { return ' ' + ctx.label + ': ' + ctx.parsed + ' students'; } } }
            }
        })
    });
}

function setMetricView(v) {
    metricView = v;
    document.getElementById('viewBand').classList.toggle('active', v === 'band');
    document.getElementById('viewDist').classList.toggle('active', v === 'dist');
    renderMetricChart();
}
function renderMetricChart() {
    var metric = document.getElementById('metricSelect').value;
    if (metricChartInstance) metricChartInstance.destroy();

    var labels, data, colors, title;
    if (metricView === 'band') {
        labels = ['High risk', 'Moderate risk', 'Low risk'];
        data = [BAND_METRICS.High[metric] || 0, BAND_METRICS.Moderate[metric] || 0, BAND_METRICS.Low[metric] || 0];
        colors = ['#e53e3e', '#ecc94b', '#48bb78'];
        title = 'Average ' + METRIC_LABELS[metric] + ' per risk band';
    } else {
        labels = DIST_BINS[metric].labels;
        data   = DIST_BINS[metric].data;
        colors = ['#e53e3e', '#fc8181', '#ecc94b', '#68d391', '#48bb78'];
        title = METRIC_LABELS[metric] + ' distribution';
    }
    var unit = METRIC_UNIT[metric] || '';

    metricChartInstance = new Chart(document.getElementById('metricChart').getContext('2d'), {
        type: 'bar',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderRadius: 6, borderWidth: 0, maxBarThickness: 60 }] },
        options: Object.assign({}, COMMON, {
            plugins: {
                legend: { display: false },
                title: { display: true, text: title, color: '#8a92a6', font: { size: 11, weight: '500' }, padding: { bottom: 6 } },
                tooltip: { callbacks: { label: function(ctx) { return metricView === 'band' ? ' ' + ctx.parsed.y + unit : ' ' + ctx.parsed.y + ' students'; } } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#8a92a6', font: { size: 11 } } },
                y: { grid: { color: '#f4f6f9' }, ticks: { color: '#8a92a6', font: { size: 11 } }, beginAtZero: true }
            }
        })
    });
}

function ensureScore() {
    if (scoreInstance) return;
    var ctx = document.getElementById('scoreChart').getContext('2d');
    var gradient = ctx.createLinearGradient(0, 0, 0, 240);
    gradient.addColorStop(0, 'rgba(108,92,231,0.32)');
    gradient.addColorStop(1, 'rgba(108,92,231,0.02)');

    scoreInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: SCORE_BINS.labels,
            datasets: [{
                data: SCORE_BINS.data,
                borderColor: '#6c5ce7',
                backgroundColor: gradient,
                borderWidth: 2.5,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#6c5ce7',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 7
            }]
        },
        options: Object.assign({}, COMMON, {
            plugins: {
                legend: { display: false },
                title: { display: true, text: 'Curve of student counts across risk-score ranges', color: '#8a92a6', font: { size: 11, weight: '500' }, padding: { bottom: 6 } },
                tooltip: { callbacks: { label: function(ctx) { return ' ' + ctx.parsed.y + ' students'; } } }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Risk score range', color: '#8a92a6', font: { size: 10 } },
                    grid: { display: false },
                    ticks: { color: '#8a92a6', font: { size: 11 } }
                },
                y: {
                    title: { display: true, text: 'Students', color: '#8a92a6', font: { size: 10 } },
                    grid: { color: '#f4f6f9' },
                    ticks: { color: '#8a92a6', font: { size: 11 }, precision: 0 },
                    beginAtZero: true
                }
            }
        })
    });
}

function ensureTop() {
    if (topInstance) return;
    var labels = TOP_RISK.map(function(s) { return s.n.length > 18 ? s.n.slice(0,17) + '…' : s.n; });
    var values = TOP_RISK.map(function(s) { return s.v; });
    var colors = TOP_RISK.map(function(s) {
        return s.b === 'High' ? '#e53e3e' : (s.b === 'Moderate' ? '#ecc94b' : '#48bb78');
    });
    topInstance = new Chart(document.getElementById('topChart').getContext('2d'), {
        type: 'bar',
        data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderRadius: 6, borderWidth: 0 }] },
        options: Object.assign({}, COMMON, {
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                title: { display: true, text: 'Top ' + TOP_RISK.length + ' at-risk (risk score)', color: '#8a92a6', font: { size: 11, weight: '500' }, padding: { bottom: 6 } },
                tooltip: { callbacks: { label: function(ctx) { return ' Risk score: ' + ctx.parsed.x; } } }
            },
            scales: {
                x: { grid: { color: '#f4f6f9' }, ticks: { color: '#8a92a6', font: { size: 11 } }, beginAtZero: true, max: 100 },
                y: { grid: { display: false }, ticks: { color: '#4a5568', font: { size: 11 } } }
            }
        })
    });
}

function renderDistChart() {
    var metric = document.getElementById('distSelect').value;
    if (distChartInstance) distChartInstance.destroy();
    var bins = DIST_BINS[metric];
    distChartInstance = new Chart(document.getElementById('distChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: bins.labels,
            datasets: [{ data: bins.data, backgroundColor: '#6c5ce7', borderRadius: 6, borderWidth: 0, maxBarThickness: 60 }]
        },
        options: Object.assign({}, COMMON, {
            plugins: {
                legend: { display: false },
                title: { display: true, text: METRIC_LABELS[metric] + ' distribution', color: '#8a92a6', font: { size: 11, weight: '500' }, padding: { bottom: 6 } },
                tooltip: { callbacks: { label: function(ctx) { return ' ' + ctx.parsed.y + ' students'; } } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#8a92a6', font: { size: 11 } } },
                y: { grid: { color: '#f4f6f9' }, ticks: { color: '#8a92a6', font: { size: 11 } }, beginAtZero: true }
            }
        })
    });
}

function ensureScatter() {
    if (scatterInstance) return;
    var high = [], mod = [], low = [];
    SCATTER_PTS.forEach(function(p) {
        var pt = { x: p.x, y: p.y, name: p.name };
        if (p.band === 'High')          high.push(pt);
        else if (p.band === 'Moderate') mod.push(pt);
        else                            low.push(pt);
    });

    scatterInstance = new Chart(document.getElementById('scatterChart').getContext('2d'), {
        type: 'scatter',
        data: {
            datasets: [
                { label: 'High risk (' + high.length + ')', data: high, backgroundColor: 'rgba(229,62,62,0.72)', borderColor: '#c53030', borderWidth: 1, pointRadius: 5, pointHoverRadius: 7 },
                { label: 'Moderate (' + mod.length + ')',   data: mod,  backgroundColor: 'rgba(236,201,75,0.78)', borderColor: '#b7791f', borderWidth: 1, pointRadius: 5, pointHoverRadius: 7 },
                { label: 'Low risk (' + low.length + ')',   data: low,  backgroundColor: 'rgba(72,187,120,0.7)',  borderColor: '#276749', borderWidth: 1, pointRadius: 5, pointHoverRadius: 7 }
            ]
        },
        options: Object.assign({}, COMMON, {
            plugins: {
                legend: { display: true, position: 'top', align: 'end', labels: { font: { size: 11 }, color: '#4a5568', boxWidth: 8, boxHeight: 8, usePointStyle: true, padding: 14 } },
                title: { display: true, text: 'Lower grades cluster toward higher risk scores', color: '#8a92a6', font: { size: 11, weight: '500' }, padding: { bottom: 6 } },
                tooltip: { callbacks: { label: function(ctx) { var d = ctx.raw; return ' ' + d.name + ' — grade ' + d.x + '%, risk ' + d.y; } } }
            },
            scales: {
                x: { title: { display: true, text: 'Average grade (%)', color: '#8a92a6', font: { size: 10 } }, grid: { color: '#f4f6f9' }, ticks: { color: '#8a92a6', font: { size: 11 }, stepSize: 20 }, min: 0, max: 100 },
                y: { title: { display: true, text: 'Risk score', color: '#8a92a6', font: { size: 10 } }, grid: { color: '#f4f6f9' }, ticks: { color: '#8a92a6', font: { size: 11 }, stepSize: 20 }, min: 0, max: 100 }
            }
        })
    });
}

// ── Table: filter + search + pagination ──
function allRows() { return Array.from(document.querySelectorAll('#studentTableBody tr')); }
function matchesSearch(tr, q) { if (!q) return true; var blob = tr.dataset.search || ''; return blob.indexOf(q) !== -1; }
function matchesBand(tr) {
    if (currentFilter === 'all')    return true;
    if (currentFilter === 'atrisk') return tr.dataset.atrisk === '1';
    return tr.dataset.band === currentFilter;
}
function filteredRows() {
    var q = currentSearch.toLowerCase().trim();
    return allRows().filter(function(tr) { return matchesBand(tr) && matchesSearch(tr, q); });
}
function setFilter(type, btn) {
    currentFilter = type;
    document.querySelectorAll('.rdd-filter-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    currentPage = 1;
    renderTable();
}
function escRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
function highlightInCell(cell, q) {
    var orig = cell.dataset.orig;
    if (!orig) return;
    if (!q) { cell.textContent = orig; return; }
    try {
        var re = new RegExp('(' + escRe(q) + ')', 'ig');
        var escaped = orig.replace(/[&<>"']/g, function(c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
        });
        cell.innerHTML = escaped.replace(re, '<span class="hl-match">$1</span>');
    } catch (e) {
        cell.textContent = orig;
    }
}
function renderTable() {
    var q = currentSearch.toLowerCase().trim();
    var rows = filteredRows();
    var total = rows.length;
    var pages = Math.max(1, Math.ceil(total / PER_PAGE));
    if (currentPage > pages) currentPage = pages;
    if (currentPage < 1) currentPage = 1;
    var start = (currentPage - 1) * PER_PAGE, end = start + PER_PAGE;

    allRows().forEach(function(tr) { tr.style.display = 'none'; });
    rows.forEach(function(tr, i) {
        tr.style.display = (i >= start && i < end) ? '' : 'none';
        var nameCell  = tr.querySelector('.student-name');
        var emailCell = tr.querySelector('.student-email');
        if (nameCell)  highlightInCell(nameCell, q);
        if (emailCell) highlightInCell(emailCell, q);
    });

    var existingEmpty = document.getElementById('emptyRow');
    if (existingEmpty) existingEmpty.remove();
    if (total === 0) {
        var tbody = document.getElementById('studentTableBody');
        var tr = document.createElement('tr');
        tr.id = 'emptyRow'; tr.className = 'rdd-empty-row';
        var msg = q ? 'No students match "' + q.replace(/</g, '&lt;') + '" in the current filter.' : 'No students match this filter.';
        tr.innerHTML = '<td colspan="10">' + msg + '</td>';
        tbody.appendChild(tr);
    }

    renderPagination(total, pages);

    var hits = document.getElementById('searchHits');
    if (q && total > 0) { hits.textContent = total; hits.classList.add('show'); }
    else { hits.classList.remove('show'); }
    document.getElementById('searchClear').classList.toggle('show', q.length > 0);

    allRows().forEach(function(tr) {
        if (tr.style.display === 'none') {
            var cb = tr.querySelector('.rdd-row-check');
            if (cb && cb.checked) { cb.checked = false; tr.classList.remove('rdd-row-selected'); }
        }
    });
    syncSelectAllCheckbox(); updateSelectionUI();
}

function onSearchInput(val) { currentSearch = val; currentPage = 1; renderTable(); }
function clearSearch() {
    var input = document.getElementById('searchInput');
    input.value = ''; currentSearch = ''; currentPage = 1;
    renderTable(); input.focus();
}

function renderPagination(total, pages) {
    var wrap = document.getElementById('paginationWrap');
    var info = document.getElementById('pageInfo');
    var nav  = document.getElementById('pageNav');
    if (total === 0) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    var start = (currentPage - 1) * PER_PAGE + 1;
    var end   = Math.min(currentPage * PER_PAGE, total);
    info.innerHTML = 'Showing <b>' + start + '–' + end + '</b> of <b>' + total + '</b> students';
    nav.innerHTML = '';
    if (pages <= 1) return;

    var prev = document.createElement('button'); prev.className = 'pg-btn'; prev.innerHTML = '&#8249;';
    prev.disabled = currentPage === 1;
    prev.onclick = function() { if (currentPage > 1) { currentPage--; renderTable(); } };
    nav.appendChild(prev);

    var nums = [];
    if (pages <= 7) for (var p = 1; p <= pages; p++) nums.push(p);
    else {
        nums.push(1);
        if (currentPage > 3) nums.push('...');
        var s = Math.max(2, currentPage - 1), e = Math.min(pages - 1, currentPage + 1);
        for (var p2 = s; p2 <= e; p2++) nums.push(p2);
        if (currentPage < pages - 2) nums.push('...');
        nums.push(pages);
    }
    nums.forEach(function(n) {
        var b = document.createElement('button');
        b.className = 'pg-btn' + (n === currentPage ? ' active' : '') + (n === '...' ? ' dots' : '');
        b.textContent = n;
        if (n !== '...') b.onclick = function() { currentPage = n; renderTable(); window.scrollTo({ top: document.querySelector('.rdd-table-card').offsetTop - 20, behavior: 'smooth' }); };
        nav.appendChild(b);
    });
    var next = document.createElement('button'); next.className = 'pg-btn'; next.innerHTML = '&#8250;';
    next.disabled = currentPage === pages;
    next.onclick = function() { if (currentPage < pages) { currentPage++; renderTable(); } };
    nav.appendChild(next);
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
    if (radarChartInstance) radarChartInstance.destroy();

    var activityScore = Math.max(0, Math.round(100 - (days / 84 * 100)));
    var bandColor = band === 'High' ? '#e53e3e' : (band === 'Moderate' ? '#ecc94b' : '#48bb78');
    var bandBg    = band === 'High' ? 'rgba(229,62,62,0.15)' : (band === 'Moderate' ? 'rgba(236,201,75,0.15)' : 'rgba(72,187,120,0.15)');
    radarChartInstance = new Chart(document.getElementById('radarChart').getContext('2d'), {
        type: 'radar',
        data: {
            labels: ['Avg Grade', 'Activity', 'Attendance', 'Submissions'],
            datasets: [{ label: name, data: [grade, activityScore, att, sub], borderColor: bandColor, backgroundColor: bandBg, borderWidth: 2.5, pointBackgroundColor: bandColor, pointRadius: 5, pointHoverRadius: 7 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { r: { beginAtZero: true, max: 100, ticks: { stepSize: 25, color: '#8a92a6', font: { size: 10 }, backdropColor: 'transparent' }, grid: { color: '#edf0f7' }, pointLabels: { color: '#4a5568', font: { size: 12, weight: '600' } }, angleLines: { color: '#edf0f7' } } } }
    });
    document.getElementById('detailModal').classList.add('open');
}

// ── Notify (single, unified) ──
function showNotify(uid, name, email) {
    currentStudentId = uid;
    document.getElementById('nm-name').textContent = 'To: ' + name + ' (' + email + ')';
    document.getElementById('nm-status').style.display = 'none';
    var btn = document.getElementById('nm-send-btn');
    btn.disabled = false; btn.textContent = 'Send notification'; btn.style.background = '#6c5ce7';
    document.getElementById('notifyModal').classList.add('open');
}
function sendNotification() {
    if (!currentStudentId) return;
    var subject = document.getElementById('nm-subject').value.trim();
    var message = document.getElementById('nm-message').value.trim();
    var btn = document.getElementById('nm-send-btn');

    btn.disabled = true; btn.textContent = 'Sending...'; btn.style.background = '#8a92a6';

    var fd = new FormData();
    fd.append('sesskey', RDD.sesskey);
    fd.append('courseid', RDD.courseid);
    fd.append('studentid', currentStudentId);
    fd.append('subject', subject);
    fd.append('message', message);
    fd.append('ajax', '1');

    fetch(RDD.notifyUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.sent === true) {
            showNotifyStatus('success', 'Notification delivered to ' + d.student + '.');
            btn.textContent = 'Sent'; btn.style.background = '#00b894';
        } else {
            showNotifyStatus('error', d.notice || 'Delivery failed.');
            btn.disabled = false; btn.textContent = 'Retry'; btn.style.background = '#6c5ce7';
        }
    }).catch(function(err) {
        showNotifyStatus('error', 'Error: ' + err.message);
        btn.disabled = false; btn.textContent = 'Send notification'; btn.style.background = '#6c5ce7';
    });
}
function showNotifyStatus(type, message) {
    var el = document.getElementById('nm-status');
    el.style.display = 'block';
    el.style.background = type === 'success' ? '#f0fff4' : '#fff5f5';
    el.style.border = type === 'success' ? '1.5px solid #c6f6d5' : '1.5px solid #fed7d7';
    el.style.color = type === 'success' ? '#276749' : '#c53030';
    el.textContent = message;
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── Selection + bulk + export ──
function visibleRowChecks() { return allRows().filter(function(tr) { return tr.style.display !== 'none'; }).map(function(tr) { return tr.querySelector('.rdd-row-check'); }).filter(Boolean); }
function getSelectedRows() { return allRows().filter(function(tr) { var cb = tr.querySelector('.rdd-row-check'); return cb && cb.checked; }); }
function getSelectedIds() { return getSelectedRows().map(function(tr) { return parseInt(tr.dataset.userid, 10); }); }
function toggleSelectAll(checked) { visibleRowChecks().forEach(function(cb) { cb.checked = checked; var tr = cb.closest('tr'); if (tr) tr.classList.toggle('rdd-row-selected', checked); }); updateSelectionUI(); }
function onRowCheck(cb) { var tr = cb.closest('tr'); if (tr) tr.classList.toggle('rdd-row-selected', cb.checked); syncSelectAllCheckbox(); updateSelectionUI(); }
function syncSelectAllCheckbox() {
    var vis = visibleRowChecks();
    var checkedCount = vis.filter(function(c) { return c.checked; }).length;
    var all = document.getElementById('selectAllChk');
    if (all) { all.checked = vis.length > 0 && checkedCount === vis.length; all.indeterminate = checkedCount > 0 && checkedCount < vis.length; }
}
function clearSelection() {
    document.querySelectorAll('.rdd-row-check').forEach(function(cb) { cb.checked = false; });
    allRows().forEach(function(tr) { tr.classList.remove('rdd-row-selected'); });
    var all = document.getElementById('selectAllChk'); if (all) { all.checked = false; all.indeterminate = false; }
    updateSelectionUI();
}
function updateSelectionUI() {
    var count = getSelectedIds().length;
    document.getElementById('bulkCount').textContent = count;
    document.getElementById('bulkBar').classList.toggle('show', count > 0);
    document.getElementById('bnm-btn-count').textContent = count;
    document.getElementById('bnm-count').textContent = count + ' student' + (count === 1 ? '' : 's') + ' selected';
}

function collectChartSnapshots() {
    var snaps = [];
    var settings = loadSettings();
    var canvasMap = {
        donut:   { title: 'Risk Categories',       id: 'donutChart' },
        metric:  { title: 'Metric Explorer',       id: 'metricChart' },
        score:   { title: 'Risk Score Spread',     id: 'scoreChart' },
        top:     { title: 'Top At-Risk Students',  id: 'topChart' },
        dist:    { title: 'Metric Distribution',   id: 'distChart' },
        scatter: { title: 'Grade vs Risk Score',   id: 'scatterChart' }
    };
    CHART_IDS.forEach(function(key) {
        if (!settings[key]) return;
        var info = canvasMap[key];
        var c = document.getElementById(info.id);
        if (!c) return;
        try { snaps.push({ title: info.title, img: c.toDataURL('image/png', 1.0) }); } catch (e) {}
    });
    return snaps;
}

function doExport(format) {
    var ids = getSelectedIds();
    if (format === 'pdf') {
        document.getElementById('pdfExportIds').value  = ids.length > 0 ? ids.join(',') : '';
        document.getElementById('pdfExportBand').value = ids.length > 0 ? 'all' : currentFilter;
        document.getElementById('pdfExportCharts').value = JSON.stringify(collectChartSnapshots());
        document.getElementById('pdfExportForm').submit();
    } else {
        var url = RDD.exportUrl + '?courseid=' + RDD.courseid + '&format=csv';
        if (ids.length > 0) url += '&ids=' + ids.join(',');
        else                url += '&band=' + encodeURIComponent(currentFilter);
        window.location.href = url;
    }
}

function openBulkNotify() {
    var count = getSelectedIds().length;
    if (count === 0) { alert('Please select at least one student.'); return; }
    updateSelectionUI();
    document.getElementById('bnm-progress').style.display = 'none';
    document.getElementById('bnm-log').innerHTML = '';
    document.getElementById('bnm-bar').style.width = '0%';
    var btn = document.getElementById('bnm-send-btn');
    btn.disabled = false; btn.style.background = '#6c5ce7';
    document.getElementById('bulkNotifyModal').classList.add('open');
}

function sendBulkNotifications() {
    var subject = document.getElementById('bnm-subject').value.trim();
    var message = document.getElementById('bnm-message').value.trim();
    var rows = getSelectedRows();
    var total = rows.length;
    if (total === 0) return;

    var bar    = document.getElementById('bnm-bar');
    var ptext  = document.getElementById('bnm-ptext');
    var pcount = document.getElementById('bnm-pcount');
    var wrap   = document.getElementById('bnm-progress');
    var log    = document.getElementById('bnm-log');
    var btn    = document.getElementById('bnm-send-btn');

    wrap.style.display = 'block';
    log.innerHTML = '';
    bar.style.width = '0%';
    pcount.textContent = '0 / ' + total;
    ptext.textContent = 'Sending…';
    btn.disabled = true; btn.style.background = '#8a92a6';

    var ok = 0, fail = 0, i = 0;
    function next() {
        if (i >= total) {
            ptext.textContent = 'Done — ' + ok + ' sent, ' + fail + ' failed';
            bar.style.width = '100%';
            btn.disabled = false; btn.style.background = fail === 0 ? '#00b894' : '#6c5ce7';
            btn.textContent = fail === 0 ? 'All sent' : 'Retry failed';
            return;
        }
        var tr = rows[i], uid = tr.dataset.userid, name = tr.dataset.name;
        var line = document.createElement('div'); line.className = 'pend';
        line.innerHTML = '• ' + name + ' … sending';
        log.appendChild(line); log.scrollTop = log.scrollHeight;

        var fd = new FormData();
        fd.append('sesskey', RDD.sesskey);
        fd.append('courseid', RDD.courseid);
        fd.append('studentid', uid);
        fd.append('subject', subject);
        fd.append('message', message);
        fd.append('ajax', '1');

        fetch(RDD.notifyUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.sent === true) { ok++; line.className = 'ok'; line.innerHTML = '✓ ' + name + ' — delivered'; }
                else { fail++; line.className = 'err'; line.innerHTML = '✗ ' + name + ' — ' + (d.notice || 'failed'); }
            })
            .catch(function(e) {
                fail++; line.className = 'err'; line.innerHTML = '✗ ' + name + ' — ' + e.message;
            })
            .finally(function() {
                i++; pcount.textContent = i + ' / ' + total;
                bar.style.width = Math.round((i / total) * 100) + '%';
                setTimeout(next, 150);
            });
    }
    next();
}

// ── Init ──
(function init() {
    var s = loadSettings();
    CHART_IDS.forEach(function(id) {
        document.getElementById('cfg-chart-' + id).checked = !!s[id];
    });
    applySettings(s);
    renderTable();

    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'f' && !e.shiftKey) {
            var input = document.getElementById('searchInput');
            if (input) { e.preventDefault(); input.focus(); input.select(); }
        }
        if (e.key === 'Escape' && document.activeElement === document.getElementById('searchInput')) {
            clearSearch();
        }
    });
})();
</script>
<?php
echo $OUTPUT->footer();