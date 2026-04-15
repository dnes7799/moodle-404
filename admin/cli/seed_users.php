<?php
/**
 * Moodle 4.4 — User Seeder (CLI)
 *
 * Seeds 10 teachers, 10 students, and 10 guest users.
 * Assigns system-level roles via mdl_role_assignments.
 *
 * Usage (run from Moodle root):
 *   php admin/cli/seed_users.php
 *
 * Place this file at: {moodle_root}/admin/cli/seed_users.php
 */

// ─── Bootstrap Moodle ────────────────────────────────────────────────────────
define('CLI_SCRIPT', true);

// Adjust this path if your config.php is elsewhere relative to this file.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/moodlelib.php');

// ─── Configuration ───────────────────────────────────────────────────────────

/**
 * Default Moodle 4.x role IDs (system defaults — verify with your DB if
 * you have a custom install):
 *
 *   1 = manager
 *   2 = coursecreator
 *   3 = editingteacher   ← we use this for "teacher"
 *   4 = teacher (non-editing)
 *   5 = student
 *   6 = guest
 *
 * Run this SQL to confirm on your server:
 *   SELECT id, shortname FROM mdl_role ORDER BY id;
 */
$ROLE_SHORTNAMES = [
    'teacher' => 'editingteacher',   // shortname in mdl_role
    'student' => 'student',
    'guest'   => 'guest',
];

$USERS_PER_ROLE = 10;
$DEFAULT_PASSWORD = 'Moodle@1234!'; // Change before production use

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Fetch a role ID from mdl_role by shortname.
 */
function get_role_id(string $shortname): int {
    global $DB;
    $role = $DB->get_record('role', ['shortname' => $shortname], 'id', MUST_EXIST);
    return (int) $role->id;
}

/**
 * Fetch (or create) the system context — contextlevel=10, instanceid=0.
 * This is where system-wide role assignments live.
 */
function get_system_context_id(): int {
    return (int) context_system::instance()->id;
}

/**
 * Create one Moodle user and return the new user id.
 */
function create_moodle_user(string $role_label, int $index, string $password): int {
    global $DB;

    $username  = strtolower($role_label) . '_user_' . str_pad($index, 2, '0', STR_PAD_LEFT);
    $email     = $username . '@example.com';
    $firstname = ucfirst($role_label);
    $lastname  = 'User ' . $index;

    // Skip if user already exists (idempotent re-runs)
    if ($DB->record_exists('user', ['username' => $username])) {
        $existing = $DB->get_record('user', ['username' => $username], 'id');
        cli_writeln("  [SKIP] User '{$username}' already exists (id={$existing->id}).");
        return (int) $existing->id;
    }

    $user_data = [
        'auth'        => 'manual',
        'confirmed'   => 1,
        'username'    => $username,
        'email'       => $email,
        'firstname'   => $firstname,
        'lastname'    => $lastname,
        'password'    => hash_internal_user_password($password),
        'mnethostid'  => 1,           // local mnet host
        'timecreated' => time(),
        'timemodified'=> time(),
        'country'     => 'AU',        // change if needed
        'lang'        => 'en',
        'theme'       => '',
        'timezone'    => '99',
        'mailformat'  => 1,
        'deleted'     => 0,
        'suspended'   => 0,
    ];

    $user_id = $DB->insert_record('user', (object) $user_data);
    cli_writeln("  [OK]   Created user '{$username}' (id={$user_id}).");
    return (int) $user_id;
}

/**
 * Assign a system-level role to a user via mdl_role_assignments.
 * Skips if the assignment already exists.
 */
function assign_system_role(int $user_id, int $role_id, int $context_id): void {
    global $DB;

    $existing = $DB->record_exists('role_assignments', [
        'userid'    => $user_id,
        'roleid'    => $role_id,
        'contextid' => $context_id,
    ]);

    if ($existing) {
        cli_writeln("       Role already assigned — skipping.");
        return;
    }

    // Use Moodle's built-in API — handles events, logs, and caches.
    role_assign($role_id, $user_id, $context_id);
    cli_writeln("       Role id={$role_id} assigned at system context.");
}

// ─── Main ─────────────────────────────────────────────────────────────────────

cli_writeln('');
cli_writeln('========================================');
cli_writeln(' Moodle 4.4 — User Seeder');
cli_writeln('========================================');

$context_id = get_system_context_id();
cli_writeln("System context id: {$context_id}");
cli_writeln('');

foreach ($ROLE_SHORTNAMES as $label => $shortname) {
    $role_id = get_role_id($shortname);
    cli_writeln("--- Seeding {$USERS_PER_ROLE} {$label}s (role_id={$role_id}, shortname={$shortname}) ---");

    for ($i = 1; $i <= $USERS_PER_ROLE; $i++) {
        $user_id = create_moodle_user($label, $i, $DEFAULT_PASSWORD);
        assign_system_role($user_id, $role_id, $context_id);
    }

    cli_writeln('');
}

cli_writeln('========================================');
cli_writeln(' Seeding complete.');
cli_writeln('========================================');
cli_writeln('');
cli_writeln('Credentials for all seeded users:');
cli_writeln("  Password: {$DEFAULT_PASSWORD}");
cli_writeln('  Usernames follow the pattern:');
cli_writeln('    teacher_user_01 ... teacher_user_10');
cli_writeln('    student_user_01 ... student_user_10');
cli_writeln('    guest_user_01   ... guest_user_10');
cli_writeln('');
cli_writeln('IMPORTANT: Change the default password before using on a public server.');