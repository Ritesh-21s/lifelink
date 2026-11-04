<?php
/**
 * LifeLink – Donation Camp Calendar (public)
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

startSecureSession();
$db    = getDB();
$areas = getAreas();

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }
$filterArea = (int)($_GET['area_id'] ?? 0);

// Fetch all approved camps for the month
$sql = "SELECT c.*, a.name AS area_name, u.name AS organizer_name,
               (SELECT COUNT(*) FROM camp_interest ci WHERE ci.camp_id=c.id) AS interested
        FROM camps c JOIN areas a ON a.id=c.area_id JOIN users u ON u.id=c.organizer_id
        WHERE c.status='approved'
          AND YEAR(c.camp_date)=? AND MONTH(c.camp_date)=?";
$params = [$year, $month];
if ($filterArea) { $sql .= ' AND c.area_id=?'; $params[] = $filterArea; }
$sql .= ' ORDER BY c.camp_date, c.start_time';
$stmt = $db->prepare($sql); $stmt->execute($params);
$camps = $stmt->fetchAll();

// Index camps by day
$campsByDay = [];
foreach ($camps as $c) {
    $day = (int)date('j', strtotime($c['camp_date']));
    $campsByDay[$day][] = $c;
}

// Upcoming camps (next 3 months)
$upcoming = $db->query(
    "SELECT c.*, a.name AS area_name, (SELECT COUNT(*) FROM camp_interest ci WHERE ci.camp_id=c.id) AS interested
     FROM camps c JOIN areas a ON a.id=c.area_id
     WHERE c.status='approved' AND c.camp_date >= CURDATE()
     ORDER BY c.camp_date LIMIT 10"
)->fetchAll();

// Check if current user already marked interest
$userInterests = [];
if (isLoggedIn() && currentRole() === 'donor') {
    $intStmt = $db->prepare("SELECT camp_id FROM camp_interest WHERE donor_id=?");
    $intStmt->execute([currentUserId()]);
    $userInterests = $intStmt->fetchAll(PDO::FETCH_COLUMN);
}

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$daysInMonth   = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfWeek = (int)date('w', mktime(0,0,0,$month,1,$year)); // 0=Sun

$pageTitle       = 'Donation Camp Calendar';
$metaDescription = 'View upcoming blood donation camps near you. Join a camp and donate.';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <h1>🏕️ Donation Camp Calendar</h1>
        <p>Find and join blood donation camps near you</p>
    </div>
</div>
<section class="section-sm">
    <div class="container">

        <!-- Area filter -->
        <form method="GET" style="display:flex;gap:var(--space-4);align-items:center;margin-bottom:var(--space-6);flex-wrap:wrap">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <div class="form-group" style="margin:0;flex:1;min-width:200px">
                <select name="area_id" id="calAreaFilter" onchange="this.form.submit()">
                    <option value="">All Areas</option>
                    <?php foreach($areas as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $filterArea==$a['id']?'selected':'' ?>><?= e($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if($filterArea): ?><a href="?year=<?= $year ?>&month=<?= $month ?>" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
        </form>

        <!-- Calendar nav -->
        <div class="card mb-6">
            <div class="card-header">
                <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&area_id=<?= $filterArea ?>" class="btn btn-secondary btn-sm">← Prev</a>
                <h2 style="font-size:var(--text-xl)"><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></h2>
                <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&area_id=<?= $filterArea ?>" class="btn btn-secondary btn-sm">Next →</a>
            </div>
            <div class="card-body" style="padding:0">
                <!-- Day headers -->
                <div class="calendar-grid-header">
                    <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                    <div><?= $d ?></div>
                    <?php endforeach; ?>
                </div>
                <!-- Calendar days -->
                <div class="calendar-days">
                    <!-- Leading empty cells -->
                    <?php for($i=0;$i<$firstDayOfWeek;$i++): ?>
                        <div class="calendar-day other-month"></div>
                    <?php endfor; ?>
                    <!-- Days of month -->
                    <?php for($day=1;$day<=$daysInMonth;$day++):
                        $isToday = ($day==(int)date('j') && $month==(int)date('n') && $year==(int)date('Y'));
                        $hasCamp = isset($campsByDay[$day]);
                    ?>
                    <div class="calendar-day <?= $isToday?'today':'' ?> <?= $hasCamp?'has-camp':'' ?>"
                         <?php if($hasCamp): ?>onclick="showCampDay(<?= $day ?>)"<?php endif; ?>>
                        <div class="day-number">
                            <?php if($isToday): ?><span class="day-today"><?= $day ?></span><?php else: ?><?= $day ?><?php endif; ?>
                        </div>
                        <?php if($hasCamp): ?>
                            <?php foreach($campsByDay[$day] as $c): ?>
                            <div class="camp-dot" title="<?= e($c['title']) ?>"><?= e(substr($c['title'],0,12)) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Upcoming camps list -->
        <h2 style="margin-bottom:var(--space-4)">📅 Upcoming Camps</h2>
        <?php if(empty($upcoming)): ?>
            <div class="empty-state card"><div class="empty-icon">🏕️</div><h3>No upcoming camps</h3><p>Check back soon or ask your organizer to post a camp.</p></div>
        <?php else: ?>
            <div class="grid-2">
            <?php foreach($upcoming as $camp):
                $attending = in_array($camp['id'], $userInterests);
            ?>
                <div class="camp-card">
                    <div class="camp-card-header">
                        <div style="display:flex;align-items:flex-start;gap:var(--space-3)">
                            <div class="camp-date-badge">
                                <span class="camp-date-month"><?= date('M', strtotime($camp['camp_date'])) ?></span>
                                <span class="camp-date-day"><?= date('d', strtotime($camp['camp_date'])) ?></span>
                            </div>
                            <div>
                                <h3 style="color:var(--white);font-size:var(--text-base)"><?= e($camp['title']) ?></h3>
                                <p style="color:rgba(255,255,255,0.75);font-size:var(--text-xs)">by <?= e($camp['organizer_name']) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="camp-card-body">
                        <p style="font-size:var(--text-sm);margin-bottom:var(--space-2)">📍 <?= e($camp['venue']) ?>, <?= e($camp['area_name']) ?></p>
                        <p style="font-size:var(--text-sm);color:var(--gray-500);margin-bottom:var(--space-2)">
                            🕐 <?= date('h:i A',strtotime($camp['start_time'])) ?> – <?= date('h:i A',strtotime($camp['end_time'])) ?>
                        </p>
                        <p style="font-size:var(--text-sm);color:var(--gray-500);margin-bottom:var(--space-3)">📞 <?= e($camp['contact']) ?></p>
                        <?php if($camp['description']): ?>
                            <p style="font-size:var(--text-xs);color:var(--gray-600);margin-bottom:var(--space-3)"><?= e(substr($camp['description'],0,120)) ?>...</p>
                        <?php endif; ?>
                        <div style="display:flex;align-items:center;justify-content:space-between">
                            <span style="font-size:var(--text-xs);color:var(--gray-500)">
                                👥 <span data-camp-count="<?= $camp['id'] ?>"><?= (int)$camp['interested'] ?></span> interested
                            </span>
                            <?php if(isLoggedIn() && currentRole()==='donor'): ?>
                                <button class="btn btn-sm <?= $attending?'btn-success':'btn-outline' ?>"
                                        data-camp-interest="<?= $camp['id'] ?>">
                                    <?= $attending ? "✓ Attending" : "I'll Attend" ?>
                                </button>
                            <?php elseif(!isLoggedIn()): ?>
                                <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-outline btn-sm">Login to Attend</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Day popup -->
<div id="dayPopup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9000;align-items:center;justify-content:center">
    <div style="background:var(--white);border-radius:var(--radius-xl);padding:var(--space-6);max-width:500px;width:90%;max-height:80vh;overflow-y:auto">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4)">
            <h3 id="popupTitle">Camps on this day</h3>
            <button onclick="document.getElementById('dayPopup').style.display='none'" class="btn btn-secondary btn-sm">✕ Close</button>
        </div>
        <div id="popupContent"></div>
    </div>
</div>

<script>
const campsByDay = <?= json_encode($campsByDay, JSON_HEX_TAG) ?>;
function showCampDay(day) {
    const camps = campsByDay[day] || [];
    let html = '';
    camps.forEach(c => {
        html += `<div style="border:1px solid var(--gray-200);border-radius:var(--radius-md);padding:var(--space-4);margin-bottom:var(--space-3)">
            <strong>${escHtml(c.title)}</strong>
            <p style="font-size:var(--text-sm);color:var(--gray-600);margin-top:4px">📍 ${escHtml(c.venue)}, ${escHtml(c.area_name)}</p>
            <p style="font-size:var(--text-sm);color:var(--gray-500)">🕐 ${escHtml(c.start_time)} – ${escHtml(c.end_time)}</p>
        </div>`;
    });
    document.getElementById('popupTitle').textContent = `Camps on ${day} <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>`;
    document.getElementById('popupContent').innerHTML = html;
    document.getElementById('dayPopup').style.display = 'flex';
}
function escHtml(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
