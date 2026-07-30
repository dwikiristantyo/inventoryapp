<?php
// modules/user_group/index.php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/layout.php';

// PROTEKSI HANYA UNTUK ADMINISTRATOR
$logged_user_group = $_SESSION['group_id'] ?? 'admin'; // Sesuaikan variabel session Anda
if ($logged_user_group !== 'admin') {
    die("<h3 style='color:red;'>Akses Ditolak: Halaman ini hanya untuk Administrator!</h3>");
}

$message = '';
$edit_group = null;
$group_details = [];

// DAFTAR MENU SISTEM (Dapat disesuaikan dengan daftar menu aplikasi Anda)
$available_menus = [
    'm_invmain'        => 'Main Inventory',
    'm_invmaster'     => 'Master Inventory',
    'm_invwhmast'     => 'Master Warehouse',
    'm_item'           => 'Master Item',
    'm_invtransaction' => 'Inventory Transaction',
    'm_invothin'       => 'Other In Transaction',
    'm_outgoingstock'  => 'Outgoing Stock',
    'm_stockopname'    => 'Stock Opname',
    'm_adjustmentadj'  => 'Adjustment',
    'm_transferorder'  => 'Transfer Order',
    'm_postingtransinv'=> 'Posting Transaction',
    'm_updatesrn'      => 'Update SRN',
    'm_report'         => 'Report Main',
    'm_reportstock'    => 'Report Stock',
    'm_reportstockitem' => 'Report Stock Item',
    'm_admmain'        => 'Admin Main',
    'm_systemsetup'    => 'System Setup',
    'm_admuser'        => 'Master User',
    'm_admgroup'       => 'Master Group'
];

// HANDLE FORM ACTIONS (SAVE / UPDATE / DELETE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'SAVE_GROUP') {
        $group_id   = strtolower(trim($_POST['group_id']));
        $group_desc = trim($_POST['group_desc']);
        $menus      = $_POST['menus'] ?? [];

        if (!empty($group_id) && !empty($group_desc)) {
            try {
                $pdo->beginTransaction();

                // 1. UPSERT HEADER (sysgrouphdr)
                $stmtHdr = $pdo->prepare("
                    INSERT INTO sysgrouphdr (group_id, group_desc) 
                    VALUES (:gid, :gdesc)
                    ON DUPLICATE KEY UPDATE group_desc = VALUES(group_desc)
                ");
                $stmtHdr->execute([':gid' => $group_id, ':gdesc' => $group_desc]);

                // 2. RESET & INSERT DETAIL PERMISSION (sysgroupdet)
                $stmtDel = $pdo->prepare("DELETE FROM sysgroupdet WHERE group_id = :gid");
                $stmtDel->execute([':gid' => $group_id]);

                $stmtIns = $pdo->prepare("
                    INSERT INTO sysgroupdet 
                    (group_id, menu_id, group_add, group_modify, group_delete, group_s1, group_s2, group_s3, group_s4, group_s5)
                    VALUES (:gid, :mid, :add, :mod, :del, :s1, :s2, :s3, :s4, :s5)
                ");

                foreach ($available_menus as $menu_id => $label) {
                    $has_access = isset($menus[$menu_id]['access']) ? 1 : 0;
                    if ($has_access) {
                        $stmtIns->execute([
                            ':gid' => $group_id,
                            ':mid' => $menu_id,
                            ':add' => isset($menus[$menu_id]['add']) ? 1 : 0,
                            ':mod' => isset($menus[$menu_id]['mod']) ? 1 : 0,
                            ':del' => isset($menus[$menu_id]['del']) ? 1 : 0,
                            ':s1'  => isset($menus[$menu_id]['s1'])  ? 1 : 0,
                            ':s2'  => isset($menus[$menu_id]['s2'])  ? 1 : 0,
                            ':s3'  => isset($menus[$menu_id]['s3'])  ? 1 : 0,
                            ':s4'  => isset($menus[$menu_id]['s4'])  ? 1 : 0,
                            ':s5'  => isset($menus[$menu_id]['s5'])  ? 1 : 0,
                        ]);
                    }
                }

                $pdo->commit();
                $message = "Group Hak Akses '{$group_id}' berhasil disimpan!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Gagal Simpan Data: " . $e->getMessage();
            }
        }
    } elseif ($action === 'DELETE') {
        $group_id = $_POST['group_id'];
        if ($group_id !== 'admin') {
            $pdo->prepare("DELETE FROM sysgroupdet WHERE group_id = :gid")->execute([':gid' => $group_id]);
            $pdo->prepare("DELETE FROM sysgrouphdr WHERE group_id = :gid")->execute([':gid' => $group_id]);
            $message = "Group {$group_id} berhasil dihapus.";
        } else {
            $message = "Group 'admin' tidak dapat dihapus!";
        }
    }
}

// HANDLE EDIT MODE
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $stmtHdr = $pdo->prepare("SELECT * FROM sysgrouphdr WHERE group_id = :gid");
    $stmtHdr->execute([':gid' => $edit_id]);
    $edit_group = $stmtHdr->fetch(PDO::FETCH_ASSOC);

    if ($edit_group) {
        $stmtDet = $pdo->prepare("SELECT * FROM sysgroupdet WHERE group_id = :gid");
        $stmtDet->execute([':gid' => $edit_id]);
        $rows = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $group_details[$r['menu_id']] = $r;
        }
    }
}

// FETCH DAFTAR GROUP
$groups = $pdo->query("SELECT * FROM sysgrouphdr ORDER BY group_id ASC")->fetchAll(PDO::FETCH_ASSOC);
render_header("Master User Group");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Master User Group & Permission</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 20px; background: #f8f9fa; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 6px; max-width: 1000px; margin: 0 auto 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .row { display: flex; gap: 15px; margin-bottom: 12px; }
        label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; }
        input[type="text"] { width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; font-size: 13px; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #0d6efd; color: #fff; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-secondary { background: #6c757d; color: #fff; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px; }
        th, td { border: 1px solid #dee2e6; padding: 6px 8px; text-align: left; }
        th { background: #f1f3f5; }
        .text-center { text-align: center; }
    </style>
</head>
<body>

<div class="card">
    <h2>Master Group User (sysgrouphdr & sysgroupdet)</h2>
    <?php if ($message): ?>
        <div style="padding: 10px; background: #e2e3e5; border-radius: 4px; margin-bottom: 15px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="SAVE_GROUP">
        <div class="row">
            <div style="width: 200px;">
                <label>Group ID</label>
                <input type="text" name="group_id" placeholder="cth: farm" value="<?= htmlspecialchars($edit_group['group_id'] ?? '') ?>" <?= $edit_group ? 'readonly' : 'required' ?>>
            </div>
            <div style="flex: 1;">
                <label>Group Deskripsi</label>
                <input type="text" name="group_desc" placeholder="cth: Farm Operator" value="<?= htmlspecialchars($edit_group['group_desc'] ?? '') ?>" required>
            </div>
        </div>

        <h4>Setting Hak Akses Menu</h4>
        <table>
            <thead>
                <tr>
                    <th width="30">Akses</th>
                    <th>Menu ID</th>
                    <th class="text-center">Add</th>
                    <th class="text-center">Modify</th>
                    <th class="text-center">Delete</th>
                    <th class="text-center">S1</th>
                    <th class="text-center">S2</th>
                    <th class="text-center">S3</th>
                    <th class="text-center">S4</th>
                    <th class="text-center">S5</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($available_menus as $mid => $mname): 
                    $det = $group_details[$mid] ?? null;
                    $has_access = $det ? true : false;
                ?>
                <tr>
                    <td class="text-center">
                        <input type="checkbox" name="menus[<?= $mid ?>][access]" value="1" <?= $has_access ? 'checked' : '' ?>>
                    </td>
                    <td><strong><?= $mid ?></strong> <small>(<?= $mname ?>)</small></td>
                    <td class="text-center"><input type="checkbox" name="menus[<?= $mid ?>][add]" value="1" <?= ($det['group_add'] ?? 0) ? 'checked' : '' ?>></td>
                    <td class="text-center"><input type="checkbox" name="menus[<?= $mid ?>][mod]" value="1" <?= ($det['group_modify'] ?? 0) ? 'checked' : '' ?>></td>
                    <td class="text-center"><input type="checkbox" name="menus[<?= $mid ?>][del]" value="1" <?= ($det['group_delete'] ?? 0) ? 'checked' : '' ?>></td>
                    <td class="text-center"><input type="checkbox" name="menus[<?= $mid ?>][s1]" value="1" <?= ($det['group_s1'] ?? 0) ? 'checked' : '' ?>></td>
                    <td class="text-center"><input type="checkbox" name="menus[<?= $mid ?>][s2]" value="1" <?= ($det['group_s2'] ?? 0) ? 'checked' : '' ?>></td>
                    <td class="text-center"><input type="checkbox" name="menus[<?= $mid ?>][s3]" value="1" <?= ($det['group_s3'] ?? 0) ? 'checked' : '' ?>></td>
                    <td class="text-center"><input type="checkbox" name="menus[<?= $mid ?>][s4]" value="1" <?= ($det['group_s4'] ?? 0) ? 'checked' : '' ?>></td>
                    <td class="text-center"><input type="checkbox" name="menus[<?= $mid ?>][s5]" value="1" <?= ($det['group_s5'] ?? 0) ? 'checked' : '' ?>></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="text-align: right; margin-top: 15px;">
            <?php if ($edit_group): ?>
                <button type="submit" class="btn btn-warning">Update Group</button>
                <a href="index.php" class="btn btn-secondary">Batal</a>
            <?php else: ?>
                <button type="submit" class="btn btn-primary">Simpan Group Baru</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- DAFTAR GROUP LIST -->
<div class="card">
    <h3>Daftar Group User</h3>
    <table>
        <thead>
            <tr>
                <th width="150">Group ID</th>
                <th>Deskripsi Group</th>
                <th width="150" class="text-center">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $g): ?>
            <tr>
                <td><strong><?= htmlspecialchars($g['group_id']) ?></strong></td>
                <td><?= htmlspecialchars($g['group_desc']) ?></td>
                <td class="text-center">
                    <a href="index.php?edit_id=<?= urlencode($g['group_id']) ?>" class="btn btn-warning" style="padding: 3px 8px; font-size:11px;">Edit Perms</a>
                    <?php if ($g['group_id'] !== 'admin'): ?>
                        <form method="POST" onsubmit="return confirm('Hapus group ini beserta seluruh hak aksesnya?');" style="display:inline;">
                            <input type="hidden" name="action" value="DELETE">
                            <input type="hidden" name="group_id" value="<?= $g['group_id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 3px 8px; font-size:11px;">Hapus</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>