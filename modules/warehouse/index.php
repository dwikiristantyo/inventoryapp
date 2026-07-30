<?php
// modules/warehouse/index.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/layout.php';

$current_user = 'ADMIN';
$message = '';
$edit_data = null;

// HANDLE POST REQUEST (CREATE / UPDATE / SOFT DELETE / ACTIVATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'CREATE') {
        $whid    = strtoupper(trim($_POST['whid']));
        $whname  = strtoupper(trim($_POST['whname']));
        $whalias = strtoupper(trim($_POST['whalias']));
        $whparam = !empty($_POST['whparam']) ? strtoupper(trim($_POST['whparam'])) : 'NNN';

        if (!empty($whid) && !empty($whname)) {
            $stmt = $pdo->prepare("
                INSERT INTO whmast (whid, status, whalias, whname, whparam, create_by, create_date)
                VALUES (:whid, 'A', :whalias, :whname, :whparam, :user, CURDATE())
            ");
            try {
                $stmt->execute([
                    ':whid'    => substr($whid, 0, 10),
                    ':whalias' => !empty($whalias) ? substr($whalias, 0, 10) : null,
                    ':whname'  => substr($whname, 0, 30),
                    ':whparam' => substr($whparam, 0, 3),
                    ':user'    => substr($current_user, 0, 10)
                ]);
                $message = "Gudang [{$whid}] berhasil ditambahkan!";
            } catch (PDOException $e) {
                $message = "Gagal: ID Gudang '{$whid}' sudah ada!";
            }
        }
    } elseif ($action === 'UPDATE') {
        $whid    = strtoupper(trim($_POST['whid']));
        $whname  = strtoupper(trim($_POST['whname']));
        $whalias = strtoupper(trim($_POST['whalias']));
        $whparam = !empty($_POST['whparam']) ? strtoupper(trim($_POST['whparam'])) : 'NNN';

        if (!empty($whid) && !empty($whname)) {
            $stmt = $pdo->prepare("
                UPDATE whmast 
                SET whname = :whname, 
                    whalias = :whalias, 
                    whparam = :whparam, 
                    update_by = :user, 
                    update_date = CURDATE() 
                WHERE whid = :whid
            ");
            $stmt->execute([
                ':whname'  => substr($whname, 0, 30),
                ':whalias' => !empty($whalias) ? substr($whalias, 0, 10) : null,
                ':whparam' => substr($whparam, 0, 3),
                ':user'    => substr($current_user, 0, 10),
                ':whid'    => $whid
            ]);
            $message = "Gudang [{$whid}] berhasil diperbarui!";
        }
    } elseif ($action === 'SOFT_DELETE') {
        $whid = $_POST['whid'];
        $stmt = $pdo->prepare("UPDATE whmast SET status = 'N', update_by = :user, update_date = CURDATE() WHERE whid = :whid");
        $stmt->execute([':user' => substr($current_user, 0, 10), ':whid' => $whid]);
        $message = "Status gudang [{$whid}] diubah menjadi 'N' (Non-Aktif).";
    } elseif ($action === 'ACTIVATE') {
        $whid = $_POST['whid'];
        $stmt = $pdo->prepare("UPDATE whmast SET status = 'A', update_by = :user, update_date = CURDATE() WHERE whid = :whid");
        $stmt->execute([':user' => substr($current_user, 0, 10), ':whid' => $whid]);
        $message = "Gudang [{$whid}] berhasil diaktifkan kembali (A).";
    }
}

// HANDLE EDIT MODE VIA GET PARAMETER
if (isset($_GET['edit_id'])) {
    $stmtEdit = $pdo->prepare("SELECT * FROM whmast WHERE whid = :whid");
    $stmtEdit->execute([':whid' => $_GET['edit_id']]);
    $edit_data = $stmtEdit->fetch(PDO::FETCH_ASSOC);
}

// FETCH DATA WAREHOUSE
$warehouses = $pdo->query("SELECT * FROM whmast ORDER BY whid ASC")->fetchAll(PDO::FETCH_ASSOC);
render_header("Master Warehouse");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Master Warehouse</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 20px; background: #f8f9fa; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 6px; max-width: 950px; margin: 0 auto 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .row { display: flex; gap: 10px; margin-bottom: 12px; align-items: flex-end; }
        label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; }
        
        input { padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; text-transform: uppercase; box-sizing: border-box; }
        input[readonly], input[disabled] { background-color: #e9ecef; cursor: not-allowed; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #0d6efd; color: #fff; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-success { background: #198754; color: #fff; }
        .btn-secondary { background: #6c757d; color: #fff; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        th, td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; }
        th { background: #f1f3f5; }
        .badge { padding: 3px 8px; border-radius: 10px; font-weight: bold; font-size: 10px; text-align: center; display: inline-block; }
        .badge-a { background: #d1e7dd; color: #0f5132; }
        .badge-n { background: #f8d7da; color: #842029; }
        .actions { white-space: nowrap; width: 150px; text-align: center; }
    </style>
</head>
<body>

<div class="card">
    <h2>Master Warehouse (whmast)</h2>
    
    <?php if ($message): ?>
        <div style="padding: 10px; background: #e2e3e5; border-radius: 4px; margin-bottom: 15px; font-weight: 500;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <!-- FORM INPUT / EDIT -->
    <form method="POST" action="index.php">
        <input type="hidden" name="action" value="<?= $edit_data ? 'UPDATE' : 'CREATE' ?>">
        
        <div class="row">
            <div style="width: 140px;">
                <label>ID Gudang (WHID)</label>
                <!-- CHAR(10) -->
                <input type="text" name="whid" placeholder="CTH: SRGF" maxlength="10" class="uppercase-input" 
                       value="<?= htmlspecialchars($edit_data['whid'] ?? '') ?>" 
                       <?= $edit_data ? 'readonly' : 'required' ?> style="width: 100%;">
            </div>

            <div style="flex: 2;">
                <label>Nama Gudang (WHNAME)</label>
                <!-- CHAR(30) -->
                <input type="text" name="whname" placeholder="CTH: SERANG FARM" maxlength="30" class="uppercase-input" 
                       value="<?= htmlspecialchars($edit_data['whname'] ?? '') ?>" required style="width: 100%;">
            </div>
            
            <div style="flex: 1;">
                <label>Alias (WHALIAS)</label>
                <!-- CHAR(10) -->
                <input type="text" name="whalias" placeholder="CTH: SRG" maxlength="10" class="uppercase-input" 
                       value="<?= htmlspecialchars($edit_data['whalias'] ?? '') ?>" style="width: 100%;">
            </div>

            <div style="width: 100px;">
                <label>Param (WHPARAM)</label>
                <!-- CHAR(3) -->
                <input type="text" name="whparam" placeholder="NNN" maxlength="3" class="uppercase-input" 
                       value="<?= htmlspecialchars($edit_data['whparam'] ?? 'NNN') ?>" style="width: 100%;">
            </div>
            
            <div>
                <?php if ($edit_data): ?>
                    <button type="submit" class="btn btn-warning">Update</button>
                    <a href="index.php" class="btn btn-secondary">Batal</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <!-- TABEL READ DATA -->
    <table>
        <thead>
            <tr>
                <th width="110">WHID</th>
                <th>Nama Gudang (WHNAME)</th>
                <th width="110">Alias</th>
                <th width="80">Param</th>
                <th width="80">Status</th>
                <th class="actions">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($warehouses)): ?>
                <?php foreach ($warehouses as $wh): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($wh['whid']) ?></strong></td>
                    <td><?= htmlspecialchars($wh['whname']) ?></td>
                    <td><?= htmlspecialchars($wh['whalias'] ?? '-') ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($wh['whparam'] ?? 'NNN') ?></td>
                    <td style="text-align: center;">
                        <?php if ($wh['status'] === 'A'): ?>
                            <span class="badge badge-a">A (Active)</span>
                        <?php else: ?>
                            <span class="badge badge-n">N (Non-Active)</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <!-- EDIT BUTTON -->
                        <a href="index.php?edit_id=<?= urlencode($wh['whid']) ?>" class="btn btn-warning" style="padding: 4px 8px; font-size: 11px;">Edit</a>
                        
                        <!-- TOGGLE ACTIVE / NON-ACTIVE STATUS -->
                        <?php if ($wh['status'] === 'A'): ?>
                            <form method="POST" action="index.php" onsubmit="return confirm('Non-aktifkan gudang [<?= $wh['whid'] ?>]?');" style="display:inline;">
                                <input type="hidden" name="action" value="SOFT_DELETE">
                                <input type="hidden" name="whid" value="<?= $wh['whid'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 11px;">Set 'N'</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="index.php" style="display:inline;">
                                <input type="hidden" name="action" value="ACTIVATE">
                                <input type="hidden" name="whid" value="<?= $wh['whid'] ?>">
                                <button type="submit" class="btn btn-success" style="padding: 4px 8px; font-size: 11px;">Aktifkan</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: #888;">Belum ada data gudang.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// Auto Uppercase saat mengetik
document.querySelectorAll('.uppercase-input').forEach(function(element) {
    element.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
});
</script>

</body>
</html>