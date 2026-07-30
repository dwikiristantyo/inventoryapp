<?php
// modules/category/index.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/layout.php';

$current_user = 'ADMIN';
$message = '';
$edit_data = null;

// HANDLE POST REQUEST (CREATE / UPDATE / DELETE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'CREATE') {
        $ccode = strtoupper(trim($_POST['ccode']));
        $cname = strtoupper(trim($_POST['cname']));
        
        if (!empty($ccode) && !empty($cname)) {
            $stmt = $pdo->prepare("INSERT INTO category (ccode, cname, create_by, create_date) VALUES (:ccode, :cname, :user, CURDATE())");
            try {
                $stmt->execute([':ccode' => substr($ccode, 0, 3), ':cname' => substr($cname, 0, 50), ':user' => $current_user]);
                $message = "Kategori [{$ccode}] berhasil ditambahkan!";
            } catch (PDOException $e) {
                $message = "Gagal: Kode kategori '{$ccode}' sudah ada!";
            }
        }
    } elseif ($action === 'UPDATE') {
        $ccode = strtoupper(trim($_POST['ccode']));
        $cname = strtoupper(trim($_POST['cname']));
        
        if (!empty($ccode) && !empty($cname)) {
            $stmt = $pdo->prepare("UPDATE category SET cname = :cname, update_by = :user, update_date = CURDATE() WHERE ccode = :ccode");
            $stmt->execute([':cname' => substr($cname, 0, 50), ':user' => $current_user, ':ccode' => $ccode]);
            $message = "Kategori [{$ccode}] berhasil diperbarui!";
        }
    } elseif ($action === 'DELETE') {
        $ccode = $_POST['ccode'];
        
        // Cek apakah ccode masih digunakan di itemast
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM itemast WHERE ccode = :ccode");
        $stmtCheck->execute([':ccode' => $ccode]);
        if ($stmtCheck->fetchColumn() > 0) {
            $message = "Gagal Hapus: Kategori [{$ccode}] masih digunakan oleh barang di Master Item!";
        } else {
            $stmt = $pdo->prepare("DELETE FROM category WHERE ccode = :ccode");
            $stmt->execute([':ccode' => $ccode]);
            $message = "Kategori [{$ccode}] berhasil dihapus!";
        }
    }
}

// HANDLE EDIT MODE VIA GET PARAMETER
if (isset($_GET['edit_code'])) {
    $stmtEdit = $pdo->prepare("SELECT * FROM category WHERE ccode = :ccode");
    $stmtEdit->execute([':ccode' => $_GET['edit_code']]);
    $edit_data = $stmtEdit->fetch(PDO::FETCH_ASSOC);
}

// FETCH READ DATA KATEGORI
$categories = $pdo->query("SELECT * FROM category ORDER BY ccode ASC")->fetchAll(PDO::FETCH_ASSOC);
render_header("Master Category");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Master Category</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 20px; background: #f8f9fa; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 6px; max-width: 850px; margin: 0 auto 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .row { display: flex; gap: 10px; margin-bottom: 10px; align-items: flex-end; }
        label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; }
        
        input { padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; text-transform: uppercase; box-sizing: border-box; }
        input[readonly] { background-color: #e9ecef; cursor: not-allowed; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #0d6efd; color: #fff; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-secondary { background: #6c757d; color: #fff; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        th, td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; }
        th { background: #f1f3f5; }
        .actions { white-space: nowrap; width: 140px; text-align: center; }
    </style>
</head>
<body>

<div class="card">
    <h2>Master Category</h2>
    
    <?php if ($message): ?>
        <div style="padding: 10px; background: #e2e3e5; border-radius: 4px; margin-bottom: 15px; font-weight: 500;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <!-- FORM INPUT / EDIT (CREATE & UPDATE) -->
    <form method="POST" action="index.php">
        <input type="hidden" name="action" value="<?= $edit_data ? 'UPDATE' : 'CREATE' ?>">
        
        <div class="row">
            <div>
                <label>Kode (CCODE)</label>
                <input type="text" name="ccode" placeholder="CTH: EG" maxlength="3" class="uppercase-input" 
                       value="<?= htmlspecialchars($edit_data['ccode'] ?? '') ?>" 
                       <?= $edit_data ? 'readonly' : 'required' ?> style="width: 120px;">
            </div>
            
            <div style="flex:1;">
                <label>Nama Kategori</label>
                <input type="text" name="cname" placeholder="NAMA KATEGORI" maxlength="50" class="uppercase-input" 
                       value="<?= htmlspecialchars($edit_data['cname'] ?? '') ?>" required style="width: 100%;">
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
                <th width="120">Kode (CCODE)</th>
                <th>Nama Kategori</th>
                <th class="actions">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($categories)): ?>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($cat['ccode']) ?></strong></td>
                    <td><?= htmlspecialchars($cat['cname']) ?></td>
                    <td class="actions">
                        <!-- EDIT BUTTON -->
                        <a href="index.php?edit_code=<?= urlencode($cat['ccode']) ?>" class="btn btn-warning" style="padding: 4px 8px; font-size: 11px;">Edit</a>
                        
                        <!-- DELETE BUTTON -->
                        <form method="POST" action="index.php" onsubmit="return confirm('Apakah Anda yakin ingin menghapus kategori [<?= $cat['ccode'] ?>]?');" style="display:inline;">
                            <input type="hidden" name="action" value="DELETE">
                            <input type="hidden" name="ccode" value="<?= $cat['ccode'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 4px 8px; font-size: 11px;">Hapus</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3" style="text-align: center; color: #888;">Belum ada data kategori.</td>
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