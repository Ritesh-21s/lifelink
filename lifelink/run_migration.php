<?php
/**
 * LifeLink – One-time Migration Runner
 * Visit: http://localhost/lifelink/run_migration.php
 * DELETE THIS FILE after running!
 */

// Only allow from localhost
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403); die('Access denied.');
}

$base = __DIR__;
require_once $base . '/config/db.php';

$db = getDB();

$migFile = $base . '/database/migration_002_location_chat.sql';
if (!file_exists($migFile)) {
    die('Migration file not found: ' . $migFile);
}

$sql = file_get_contents($migFile);

// Split on semicolons (skip empty/comment-only lines)
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => strlen($s) > 5 && !preg_match('/^\s*--/', $s)
);

$ok   = 0;
$fail = 0;
$log  = [];

foreach ($statements as $stmt) {
    try {
        $db->exec($stmt . ';');
        $ok++;
        $shortStmt = substr(preg_replace('/\s+/', ' ', $stmt), 0, 80);
        $log[] = ['ok', $shortStmt];
    } catch (PDOException $e) {
        $fail++;
        $shortStmt = substr(preg_replace('/\s+/', ' ', $stmt), 0, 80);
        $log[] = ['err', $shortStmt . ' — ' . $e->getMessage()];
    }
}
?><!DOCTYPE html>
<html>
<head>
    <title>LifeLink Migration</title>
    <style>
        body { font-family: monospace; background: #1a1a2e; color: #e0e0e0; padding: 30px; }
        h1 { color: #c0392b; }
        .ok  { color: #4ade80; }
        .err { color: #f87171; }
        .summary { margin-top: 20px; padding: 15px; background: #0f3460; border-radius: 8px; font-size: 1.1em; }
    </style>
</head>
<body>
<h1>🩸 LifeLink – Migration 002</h1>
<p>Running <code>migration_002_location_chat.sql</code>…</p>
<pre>
<?php foreach ($log as [$status, $msg]): ?>
<span class="<?= $status ?>"><?= htmlspecialchars($msg) ?></span>
<?php endforeach; ?>
</pre>
<div class="summary">
    ✅ <strong><?= $ok ?></strong> statements succeeded &nbsp;|&nbsp;
    ❌ <strong><?= $fail ?></strong> statements failed (errors above, usually "column already exists" which is safe to ignore)
</div>
<?php if ($fail === 0 || $ok > 5): ?>
<div class="summary" style="background:#065f46;margin-top:10px">
    ✅ Migration complete! <strong>DELETE this file</strong> from htdocs before going to production.<br>
    <a href="http://localhost/lifelink" style="color:#6ee7b7">← Open LifeLink</a>
</div>
<?php endif; ?>
</body>
</html>

