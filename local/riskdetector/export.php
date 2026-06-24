<?php
/**
 * Student Risk Detector — Export endpoint
 * ============================================================================
 * Generates CSV or printable-PDF export of student risk data.
 *
 * GET params:
 *   courseid — required
 *   format   — 'csv' or 'pdf' (default csv)
 *   ids      — optional comma-separated student IDs (if omitted = export all)
 *   band     — optional filter: all|atrisk|High|Moderate|Low (default all)
 *
 * PDF is rendered as a styled HTML page that auto-triggers the browser's
 * print dialog — the user saves as PDF via their browser. No external
 * PDF library required.
 * ============================================================================
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/riskdetector/lib.php');

$courseid  = required_param('courseid', PARAM_INT);
$format    = optional_param('format', 'csv', PARAM_ALPHA);
$ids_raw   = optional_param('ids', '', PARAM_RAW_TRIMMED);
$band      = optional_param('band', 'all', PARAM_ALPHANUMEXT);

$course    = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
require_capability('local/riskdetector:viewdashboard', $context);

global $DB;

// ── Load all results, then filter ──────────────────────────────────────────
$all = local_riskdetector_get_results($courseid);

// Filter by ids (if provided — the dashboard passes a comma-separated list
// when the user exports a selection)
$id_whitelist = null;
if ($ids_raw !== '') {
    $parts = array_filter(array_map('intval', explode(',', $ids_raw)));
    if (!empty($parts)) {
        $id_whitelist = array_flip($parts);
    }
}

// Filter by band — mirrors the dashboard's filter buttons
$rows = [];
foreach ($all as $r) {
    if ($id_whitelist !== null && !isset($id_whitelist[(int)$r->userid])) {
        continue;
    }
    if ($band !== 'all') {
        if ($band === 'atrisk') {
            if (!$r->is_atrisk) {
                continue;
            }
        } else {
            if (strcasecmp($r->risk_band, $band) !== 0) {
                continue;
            }
        }
    }
    $rows[] = $r;
}

// ── Column definitions (shared by CSV + PDF) ──────────────────────────────
$headers = [
    'ID Number',
    'First Name',
    'Last Name',
    'Email',
    'Risk Band',
    'Risk Score',
    'ML Confidence (%)',
    'At Risk',
    'Avg Grade (%)',
    'Days Inactive',
    'Attendance (%)',
    'Submission Rate (%)',
    'Last Predicted',
];

$row_to_array = function($r) {
    return [
        $r->idnumber ?: '',
        $r->firstname,
        $r->lastname,
        $r->email,
        ucfirst(strtolower($r->risk_band)),
        round($r->risk_score, 1),
        round($r->ml_confidence, 1),
        $r->is_atrisk ? 'Yes' : 'No',
        round($r->avg_grade_pct, 1),
        round($r->days_since_active, 1),
        round($r->attendance_pct, 1),
        round($r->submission_pct, 1),
        $r->timecalculated
            ? userdate($r->timecalculated, '%Y-%m-%d %H:%M')
            : '',
    ];
};

$safe_slug = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $course->shortname);
$date_slug = date('Y-m-d_His');

// ──────────────────────────────────────────────────────────────────────────
// CSV EXPORT
// ──────────────────────────────────────────────────────────────────────────
if ($format === 'csv') {

    $filename = 'risk_report_' . $safe_slug . '_' . $date_slug . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    // UTF-8 BOM so Excel on Windows opens accents correctly
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // Metadata rows (Excel-friendly)
    fputcsv($out, ['Risk Report']);
    fputcsv($out, ['Course',    $course->fullname . ' (' . $course->shortname . ')']);
    fputcsv($out, ['Generated', userdate(time(), '%Y-%m-%d %H:%M')]);
    fputcsv($out, ['Filter',    $band === 'all' ? 'All students'
                                : ($band === 'atrisk' ? 'At-risk only'
                                : $band . ' risk only')]);
    fputcsv($out, ['Rows',      count($rows)]);
    fputcsv($out, []);

    // Data
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $row_to_array($r));
    }
    fclose($out);
    exit;
}

// ──────────────────────────────────────────────────────────────────────────
// PDF EXPORT (browser print-to-PDF)
// ──────────────────────────────────────────────────────────────────────────
if ($format === 'pdf') {

    // Build summary stats for the report header
    $total   = count($rows);
    $atrisk  = 0;
    $bcounts = ['High' => 0, 'Moderate' => 0, 'Low' => 0];
    foreach ($rows as $r) {
        if ($r->is_atrisk) $atrisk++;
        $b = ucfirst(strtolower($r->risk_band));
        if (isset($bcounts[$b])) $bcounts[$b]++;
    }
    $risk_pct = $total > 0 ? round(($atrisk / $total) * 100, 1) : 0;

    $filter_label = $band === 'all'   ? 'All students'
                  : ($band === 'atrisk' ? 'At-risk students only'
                  : $band . ' risk only');

    header('Content-Type: text/html; charset=utf-8');

    ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Risk Report — <?php echo s($course->shortname); ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
    margin: 0; padding: 24px 28px; color: #1a202c; background: #fff;
    font-size: 11px; line-height: 1.5;
  }
  .no-print { margin-bottom: 20px; }
  .btn {
    background: #6c5ce7; color: #fff; border: none; padding: 10px 22px;
    border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .btn.ghost { background: #f7f7f7; color: #333; border: 1px solid #e2e8f0; }

  .report-head {
    border-bottom: 2.5px solid #1a1a2e; padding-bottom: 14px; margin-bottom: 18px;
  }
  .report-head h1 {
    margin: 0 0 4px; font-size: 20px; color: #1a1a2e; font-weight: 800;
  }
  .report-head .sub { color: #6c757d; font-size: 12px; }
  .report-head .meta {
    margin-top: 10px; font-size: 10.5px; color: #4a5568;
    display: flex; gap: 18px; flex-wrap: wrap;
  }
  .report-head .meta span strong { color: #1a202c; margin-right: 4px; }

  .kpi-row {
    display: grid; grid-template-columns: repeat(5, 1fr);
    gap: 10px; margin-bottom: 18px;
  }
  .kpi {
    border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px;
    background: #fafbff;
  }
  .kpi .l { font-size: 9.5px; color: #8a92a6; text-transform: uppercase;
            letter-spacing: 0.4px; font-weight: 700; margin-bottom: 4px; }
  .kpi .v { font-size: 20px; font-weight: 800; color: #1a202c; }
  .kpi.total .v   { color: #1a1a2e; }
  .kpi.risk .v    { color: #e53e3e; }
  .kpi.high .v    { color: #e53e3e; }
  .kpi.mod .v     { color: #d69e2e; }
  .kpi.low .v     { color: #276749; }

  table.risk {
    width: 100%; border-collapse: collapse; font-size: 10px;
  }
  table.risk th {
    background: #1a1a2e; color: #fff; padding: 8px 7px;
    font-size: 9.5px; font-weight: 700; letter-spacing: 0.3px;
    text-align: left; text-transform: uppercase;
  }
  table.risk td {
    padding: 7px; border-bottom: 1px solid #edf0f7;
    vertical-align: middle;
  }
  table.risk tbody tr:nth-child(even) td { background: #fafbff; }
  table.risk td.num { text-align: right; font-variant-numeric: tabular-nums; }

  .pill {
    display: inline-block; padding: 2px 8px; border-radius: 20px;
    font-size: 9px; font-weight: 700;
  }
  .pill.high  { background: #fff5f5; color: #c53030; }
  .pill.mod   { background: #fffbeb; color: #92400e; }
  .pill.low   { background: #f0fff4; color: #276749; }
  .pill.yes   { background: #fff5f5; color: #c53030; }
  .pill.no    { background: #f0fff4; color: #276749; }

  .foot {
    margin-top: 16px; padding-top: 10px; border-top: 1px solid #edf0f7;
    font-size: 9.5px; color: #8a92a6; text-align: center;
  }

  /* Print rules */
  @page { size: A4 landscape; margin: 12mm; }
  @media print {
    body { padding: 0; font-size: 10px; }
    .no-print { display: none !important; }
    table.risk { page-break-inside: auto; }
    table.risk tr { page-break-inside: avoid; page-break-after: auto; }
    table.risk thead { display: table-header-group; }
  }
</style>
</head>
<body>

<div class="no-print">
  <button class="btn" onclick="window.print()">&#128424; Print / Save as PDF</button>
  <button class="btn ghost" onclick="window.close()">Close</button>
  
</div>

<div class="report-head">
  <h1>Student Risk Report</h1>
  <div class="sub"><?php echo s($course->fullname); ?> (<?php echo s($course->shortname); ?>)</div>
  <div class="meta">
    <span><strong>Generated:</strong><?php echo userdate(time(), '%d %b %Y, %H:%M'); ?></span>
    <span><strong>Filter:</strong><?php echo s($filter_label); ?></span>
    <span><strong>Rows:</strong><?php echo $total; ?></span>
    <span><strong>Source:</strong>ML Random Forest classifier</span>
  </div>
</div>

<div class="kpi-row">
  <div class="kpi total"><div class="l">Students</div><div class="v"><?php echo $total; ?></div></div>
  <div class="kpi risk"><div class="l">At Risk</div>
      <div class="v"><?php echo $atrisk; ?></div>
      <div style="font-size:10px;color:#8a92a6;"><?php echo $risk_pct; ?>% of group</div>
  </div>
  <div class="kpi high"><div class="l">High</div><div class="v"><?php echo $bcounts['High']; ?></div></div>
  <div class="kpi mod"><div class="l">Moderate</div><div class="v"><?php echo $bcounts['Moderate']; ?></div></div>
  <div class="kpi low"><div class="l">Low</div><div class="v"><?php echo $bcounts['Low']; ?></div></div>
</div>

<?php if (empty($rows)): ?>
  <div style="padding:30px;text-align:center;color:#8a92a6;border:1.5px dashed #e2e8f0;border-radius:10px;">
    No students match this filter.
  </div>
<?php else: ?>

<table class="risk">
  <thead>
    <tr>
      <th style="width:24px;">#</th>
      <th>ID</th>
      <th>Student</th>
      <th>Email</th>
      <th>Band</th>
      <th class="num">Score</th>
      <th class="num">Conf.</th>
      <th>At Risk</th>
      <th class="num">Grade</th>
      <th class="num">Days</th>
      <th class="num">Att.</th>
      <th class="num">Sub.</th>
    </tr>
  </thead>
  <tbody>
  <?php $i = 0; foreach ($rows as $r):
      $i++;
      $b      = ucfirst(strtolower($r->risk_band));
      $bclass = $b === 'High' ? 'high' : ($b === 'Moderate' ? 'mod' : 'low');
  ?>
    <tr>
      <td><?php echo $i; ?></td>
      <td><?php echo s($r->idnumber ?: '—'); ?></td>
      <td style="font-weight:600;"><?php echo s(trim($r->firstname . ' ' . $r->lastname)); ?></td>
      <td style="color:#6c757d;"><?php echo s($r->email); ?></td>
      <td><span class="pill <?php echo $bclass; ?>"><?php echo $b; ?></span></td>
      <td class="num" style="font-weight:700;"><?php echo round($r->risk_score, 1); ?></td>
      <td class="num"><?php echo round($r->ml_confidence, 1); ?>%</td>
      <td><span class="pill <?php echo $r->is_atrisk ? 'yes' : 'no'; ?>">
          <?php echo $r->is_atrisk ? 'Yes' : 'No'; ?></span></td>
      <td class="num"><?php echo round($r->avg_grade_pct, 1); ?>%</td>
      <td class="num"><?php echo round($r->days_since_active, 1); ?></td>
      <td class="num"><?php echo round($r->attendance_pct, 1); ?>%</td>
      <td class="num"><?php echo round($r->submission_pct, 1); ?>%</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php endif; ?>

<div class="foot">
  Generated by Student Risk Detector — Moodle plugin &bull;
  <?php echo userdate(time(), '%d %b %Y %H:%M'); ?>
</div>

<script>
  // Auto-open print dialog shortly after render so the user can save as PDF
  window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 300);
  });
</script>

</body>
</html>
    <?php
    exit;
}

// Unknown format
throw new moodle_exception('Invalid export format');