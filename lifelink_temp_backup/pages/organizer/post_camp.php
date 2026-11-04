<?php
/**
 * LifeLink – Post Donation Camp
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireRole('organizer');
$db     = getDB();
$userId = currentUserId();
$areas  = getAreas();
$errors = [];
$old    = [];

if (isPost()) {
    if (!csrfValidate()) { $errors[] = 'Invalid form submission.'; }
    else {
        $old = $_POST;
        $title   = sanitizeInput($_POST['title'] ?? '');
        $venue   = sanitizeInput($_POST['venue'] ?? '');
        $areaId  = (int)($_POST['area_id'] ?? 0);
        $date    = $_POST['camp_date'] ?? '';
        $start   = $_POST['start_time'] ?? '';
        $end     = $_POST['end_time'] ?? '';
        $contact = sanitizeInput($_POST['contact'] ?? '');
        $desc    = sanitizeInput($_POST['description'] ?? '');

        if (strlen($title) < 3)   $errors[] = 'Title must be at least 3 characters.';
        if (strlen($venue) < 3)   $errors[] = 'Venue is required.';
        if ($areaId < 1)          $errors[] = 'Select an area.';
        if (!$date || strtotime($date) < strtotime('today')) $errors[] = 'Camp date must be today or in the future.';
        if (!$start)              $errors[] = 'Start time is required.';
        if (!$end || $end <= $start) $errors[] = 'End time must be after start time.';
        if (!validatePhone($contact)) $errors[] = 'Enter a valid contact number.';

        if (empty($errors)) {
            $db->prepare(
                'INSERT INTO camps (organizer_id,title,venue,area_id,camp_date,start_time,end_time,contact,description)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([$userId,$title,$venue,$areaId,$date,$start,$end,$contact,$desc?:null]);
            redirect(APP_URL.'/pages/organizer/dashboard.php','success','Camp submitted for admin approval!');
        }
    }
}

$pageTitle = 'Post Donation Camp';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><div class="container"><h1>Post a Donation Camp</h1><p>Submit a camp for admin approval before it appears publicly.</p></div></div>
<section class="section-sm">
    <div class="container" style="max-width:600px">
        <div class="card"><div class="card-body">
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-4"><ul style="margin:0;padding-left:var(--space-4)"><?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <form method="POST" data-validate novalidate>
                <?= csrfField() ?>
                <div class="form-group mb-4">
                    <label for="title">Camp Title <span class="required">*</span></label>
                    <input type="text" id="title" name="title" required value="<?= e($old['title']??'') ?>" placeholder="e.g. World Blood Donor Day Camp">
                    <span class="field-error"></span>
                </div>
                <div class="form-group mb-4">
                    <label for="venue">Venue <span class="required">*</span></label>
                    <input type="text" id="venue" name="venue" required value="<?= e($old['venue']??'') ?>" placeholder="Hall name, street, landmark">
                    <span class="field-error"></span>
                </div>
                <div class="form-row mb-4">
                    <div class="form-group">
                        <label for="area_id">Area <span class="required">*</span></label>
                        <select id="area_id" name="area_id" required>
                            <option value="">Select area</option>
                            <?php foreach($areas as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= ($old['area_id']??'')==$a['id']?'selected':'' ?>><?= e($a['name']) ?>, <?= e($a['city']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-error"></span>
                    </div>
                    <div class="form-group">
                        <label for="camp_date">Camp Date <span class="required">*</span></label>
                        <input type="date" id="camp_date" name="camp_date" required min="<?= date('Y-m-d') ?>" value="<?= e($old['camp_date']??'') ?>">
                        <span class="field-error"></span>
                    </div>
                </div>
                <div class="form-row mb-4">
                    <div class="form-group">
                        <label for="start_time">Start Time <span class="required">*</span></label>
                        <input type="time" id="start_time" name="start_time" required value="<?= e($old['start_time']??'') ?>">
                    </div>
                    <div class="form-group">
                        <label for="end_time">End Time <span class="required">*</span></label>
                        <input type="time" id="end_time" name="end_time" required value="<?= e($old['end_time']??'') ?>">
                    </div>
                </div>
                <div class="form-group mb-4">
                    <label for="contact">Contact Number <span class="required">*</span></label>
                    <input type="tel" id="contact" name="contact" required value="<?= e($old['contact']??'') ?>" placeholder="10-digit mobile">
                    <span class="field-error"></span>
                </div>
                <div class="form-group mb-6">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Details about the camp, what to bring, etc."><?= e($old['description']??'') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-full btn-lg">Submit for Approval</button>
            </form>
        </div></div>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
