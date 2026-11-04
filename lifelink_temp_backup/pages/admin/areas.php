<?php
/**
 * LifeLink – Admin: Areas & Adjacency Management
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireAdmin();
$db = getDB();
$errors = [];

if (isPost() && csrfValidate()) {
    $pAction = sanitizeInput($_POST['form_action'] ?? '');

    if ($pAction === 'add_area') {
        $name = sanitizeInput($_POST['area_name'] ?? '');
        $city = sanitizeInput($_POST['city'] ?? '');
        if (strlen($name) >= 2 && strlen($city) >= 2) {
            $db->prepare('INSERT INTO areas (name,city) VALUES (?,?)')->execute([$name,$city]);
            setFlash('success', 'Area added.');
        } else { $errors[] = 'Area name and city are required.'; }
    }

    if ($pAction === 'add_adjacency') {
        $a = (int)$_POST['area1'];
        $b = (int)$_POST['area2'];
        if ($a && $b && $a !== $b) {
            $db->prepare('INSERT IGNORE INTO area_adjacency (area_id,adjacent_area_id) VALUES (?,?),(?,?)')->execute([$a,$b,$b,$a]);
            setFlash('success', 'Adjacency added (bidirectional).');
        }
    }

    if ($pAction === 'delete_adjacency') {
        $a = (int)$_POST['area1'];
        $b = (int)$_POST['area2'];
        $db->prepare('DELETE FROM area_adjacency WHERE (area_id=? AND adjacent_area_id=?) OR (area_id=? AND adjacent_area_id=?)')->execute([$a,$b,$b,$a]);
        setFlash('success', 'Adjacency removed.');
    }

    if ($pAction === 'update_threshold') {
        $val = (int)$_POST['shortage_threshold'];
        if ($val > 0) {
            $db->prepare("UPDATE settings SET `value`=? WHERE `key`='shortage_threshold'")->execute([$val]);
            setFlash('success', 'Threshold updated.');
        }
    }

    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

$areas     = $db->query('SELECT * FROM areas ORDER BY city, name')->fetchAll();
$adjacency = $db->query('SELECT aa.*, a1.name AS a1_name, a2.name AS a2_name FROM area_adjacency aa JOIN areas a1 ON a1.id=aa.area_id JOIN areas a2 ON a2.id=aa.adjacent_area_id WHERE aa.area_id < aa.adjacent_area_id ORDER BY a1.name')->fetchAll();
$threshold = getSetting('shortage_threshold', SHORTAGE_THRESHOLD);

$pageTitle = 'Area Management';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><div class="container"><h1>📍 Area Management</h1></div></div>
<section class="section-sm">
    <div class="container">
        <a href="<?= APP_URL ?>/pages/admin/dashboard.php" class="btn btn-secondary btn-sm mb-6">← Admin Dashboard</a>
        <?php if($errors): ?><div class="alert alert-danger mb-4"><?php foreach($errors as $e): ?><p><?= e($e) ?></p><?php endforeach; ?></div><?php endif; ?>

        <div class="grid-2">
            <!-- Add area -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-base)">Add New Area</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="form_action" value="add_area">
                        <div class="form-group mb-4"><label>Area Name</label><input type="text" name="area_name" required placeholder="e.g. Kodambakkam"></div>
                        <div class="form-group mb-4"><label>City</label><input type="text" name="city" required placeholder="e.g. Chennai"></div>
                        <button type="submit" class="btn btn-primary">Add Area</button>
                    </form>
                </div>
            </div>

            <!-- Add adjacency -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-base)">Add Area Adjacency</h2></div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="form_action" value="add_adjacency">
                        <div class="form-group mb-4">
                            <label>Area 1</label>
                            <select name="area1" required>
                                <?php foreach($areas as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-4">
                            <label>Area 2 (Adjacent)</label>
                            <select name="area2" required>
                                <?php foreach($areas as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Add Adjacency</button>
                    </form>
                </div>
            </div>

            <!-- Shortage threshold -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-base)">Shortage Threshold</h2></div>
                <div class="card-body">
                    <p class="text-sm text-muted mb-4">Areas with fewer than this many donors per blood group are flagged as shortage zones.</p>
                    <form method="POST" style="display:flex;gap:var(--space-3);align-items:flex-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="form_action" value="update_threshold">
                        <div class="form-group" style="flex:1"><label>Threshold</label><input type="number" name="shortage_threshold" min="1" max="20" value="<?= (int)$threshold ?>"></div>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </form>
                </div>
            </div>

            <!-- Current areas -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-base)">All Areas (<?= count($areas) ?>)</h2></div>
                <div style="max-height:250px;overflow-y:auto">
                    <table style="width:100%;font-size:var(--text-sm)">
                        <thead><tr style="background:var(--gray-50)"><th style="padding:8px">ID</th><th style="padding:8px">Name</th><th style="padding:8px">City</th></tr></thead>
                        <tbody>
                        <?php foreach($areas as $a): ?>
                        <tr style="border-bottom:1px solid var(--gray-100)"><td style="padding:8px"><?= $a['id'] ?></td><td style="padding:8px"><?= e($a['name']) ?></td><td style="padding:8px"><?= e($a['city']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Adjacency list -->
        <div class="card mt-6">
            <div class="card-header"><h2 style="font-size:var(--text-base)">Area Adjacency List</h2></div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Area</th><th>Adjacent To</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach($adjacency as $adj): ?>
                    <tr>
                        <td><?= e($adj['a1_name']) ?></td>
                        <td><?= e($adj['a2_name']) ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_action" value="delete_adjacency">
                                <input type="hidden" name="area1" value="<?= $adj['area_id'] ?>">
                                <input type="hidden" name="area2" value="<?= $adj['adjacent_area_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" data-confirm="Remove this adjacency?">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
