<?php
/**
 * LifeLink – One-Time Migration Script
 * Run once via browser: http://localhost/lifelink/migrate.php
 * DELETE THIS FILE after running it!
 */
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) { die('Access denied.'); }
require_once __DIR__ . '/config/db.php';
$db = getDB();
$steps = [];
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_token VARCHAR(64) NULL DEFAULT NULL"); $steps[] = ['ok','email_token column added']; } catch (Exception $e) { $steps[] = ['err',$e->getMessage()]; }
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_token_expires DATETIME NULL DEFAULT NULL"); $steps[] = ['ok','email_token_expires column added']; } catch (Exception $e) { $steps[] = ['err',$e->getMessage()]; }
try { $chk=$db->query("SHOW INDEX FROM users WHERE Key_name='idx_email_token'")->fetch(); if(!$chk){$db->exec("ALTER TABLE users ADD INDEX idx_email_token (email_token)");$steps[]=['ok','Index created'];} else {$steps[]=['ok','Index already exists'];} } catch(Exception $e){$steps[]=['err',$e->getMessage()];}
$cols=$db->query("SHOW COLUMNS FROM users LIKE 'email_token%'")->fetchAll();
$steps[]=['info','Verified columns: '.implode(', ',array_column($cols,'Field'))];
?><!DOCTYPE html><html><head><meta charset="UTF-8"><title>Migration</title>
<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px}h1{color:#c0392b}.row{padding:10px;border-bottom:1px solid #eee}.ok{color:#16a34a}.err{color:#dc2626}.done{background:#f0fdf4;border-radius:8px;padding:20px;margin-top:20px;text-align:center}</style></head><body>
<h1>&#x1F9B8; LifeLink DB Migration</h1>
<?php foreach($steps as[$t,$m]):?><div class="row <?=$t?>"><b><?=$t==='ok'?'✅':($t==='err'?'❌':'ℹ️')?></b> <?=htmlspecialchars($m)?></div><?php endforeach;?>
<div class="done"><h2>&#x2705; Done!</h2><p>Now <a href="/lifelink/pages/register.php">test registration</a>.</p><p style="color:red"><b>Delete this file after use!</b></p></div>
</body></html>
