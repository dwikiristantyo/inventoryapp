<?php
// modules/itemast/index.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/layout.php';

$current_user = 'ADMIN';
$message = '';

// HANDLE FORM ACTIONS (CREATE / UPDATE / SOFT DELETE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'SAVE') {
        $ccode     = strtoupper(trim($_POST['ccode']));
        $desc1     = strtoupper(trim($_POST['desc1']));
        $uom       = strtoupper(trim($_POST['uom']));
        $uom2      = strtoupper(trim($_POST['uom2']));
        $conv_fact = (float)($_POST['conv_fact'] ?? 0);
        $uprice    = (float)($_POST['uprice'] ?? 0);

        if (!empty($ccode) && !empty($desc1)) {
            // 1. GENERATE AUTONUMBER ICODE
            $stmtNum = $pdo->prepare("
                SELECT MAX(CAST(SUBSTRING(icode, CHAR_LENGTH(:ccode1) + 1) AS UNSIGNED)) as max_num 
                FROM itemast 
                WHERE ccode = :ccode2
            ");
            
            $stmtNum->execute([
                ':ccode1' => $ccode,
                ':ccode2' => $ccode
            ]);
            
            $rowNum = $stmtNum->fetch(PDO::FETCH_ASSOC);

            $lastNum = ($rowNum && isset($rowNum['max_num'])) ? (int)$rowNum['max_num'] : 0;
            $nextNum = $lastNum + 1;
            
            // Format: CCODE + 4 DIGIT ANGKA (misal: BRL0001)
            $newIcode = $ccode . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            // 2. INSERT KE DATABASE (STATUS DEFAULT 'A')
            $stmtInsert = $pdo->prepare("
                INSERT INTO itemast (icode, desc1, uom, uom2, conv_fact, uprice, stock, ccode, create_by, create_date)
                VALUES (:icode, :desc1, :uom, :uom2, :conv_fact, :uprice, 'A', :ccode, :user, CURDATE())
            ");
            
            try {
                $stmtInsert->execute([
                    ':icode'     => substr($newIcode, 0, 12),
                    ':desc1'     => substr($desc1, 0, 50),
                    ':uom'       => substr($uom, 0, 6),
                    ':uom2'      => substr($uom2, 0, 10),
                    ':conv_fact' => $conv_fact,
                    ':uprice'    => $uprice,
                    ':ccode'     => substr($ccode, 0, 3),
                    ':user'      => $current_user
                ]);
                $message = "Barang berhasil disimpan dengan Kode Auto: <strong>{$newIcode}</strong>";
            } catch (PDOException $e) {
                $message = "Gagal Simpan Data: " . $e->getMessage();
            }
        }
    } elseif ($action === 'SOFT_DELETE') {
        $icode = $_POST['icode'];
        $stmtDel = $pdo->prepare("UPDATE itemast SET stock = 'N', update_by = :user, update_date = CURDATE() WHERE icode = :icode");
        $stmtDel->execute([':user' => $current_user, ':icode' => $icode]);
        $message = "Status barang {$icode} berhasil diubah menjadi N (Non-Aktif/Deleted).";
    } elseif ($action === 'ACTIVATE') {
        $icode = $_POST['icode'];
        $stmtAct = $pdo->prepare("UPDATE itemast SET stock = 'A', update_by = :user, update_date = CURDATE() WHERE icode = :icode");
        $stmtAct->execute([':user' => $current_user, ':icode' => $icode]);
        $message = "Status barang {$icode} diaktifkan kembali (A).";
    }
}

// FETCH DATA UNTUK UI
$categories = $pdo->query("SELECT ccode, cname FROM category ORDER BY ccode ASC")->fetchAll(PDO::FETCH_ASSOC);
$items      = $pdo->query("SELECT i.*, c.cname FROM itemast i LEFT JOIN category c ON i.ccode = c.ccode ORDER BY i.icode ASC")->fetchAll(PDO::FETCH_ASSOC);
render_header("Master Item");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Master Item Barang</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 20px; background: #f8f9fa; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 6px; max-width: 1100px; margin: 0 auto 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .row { display: flex; gap: 15px; margin-bottom: 12px; }
        .col { flex: 1; }
        label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; }
        
        input[type="text"], select { width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; text-transform: uppercase; font-size: 13px; }
        input[disabled], select[disabled] { background-color: #e9ecef; cursor: not-allowed; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 13px; }
        .btn-primary { background: #0288d1; color: #fff; }
        .btn-danger { background: #d32f2f; color: #fff; }
        .btn-success { background: #2e7d32; color: #fff; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 12px; }
        th, td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
        th { background: #f1f3f5; }
        .badge { padding: 3px 8px; border-radius: 10px; font-weight: bold; font-size: 10px; text-align: center; display: inline-block; }
        .badge-a { background: #d1e7dd; color: #0f5132; }
        .badge-n { background: #f8d7da; color: #842029; }
    </style>
</head>
<body>

<div class="card">
    <h2>Form Input Master Item</h2>
    <?php if ($message): ?>
        <div style="padding: 10px; background: #e2e3e5; border-radius: 4px; margin-bottom: 15px;"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="SAVE">
        
        <div class="row">
            <div class="col">
                <label>Kategori (CCODE)</label>
                <select name="ccode" required>
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['ccode']) ?>">
                            [<?= htmlspecialchars($cat['ccode']) ?>] <?= htmlspecialchars($cat['cname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col">
                <label>Kode Item (ICODE)</label>
                <input type="text" placeholder="AUTO GENERATE ON SAVE" disabled readonly style="font-weight: bold; color: #888;">
            </div>
            <div class="col">
                <label>Status Stock</label>
                <input type="text" value="A (Active)" disabled style="font-weight: bold; color: #2e7d32;">
            </div>
        </div>

        <div class="row">
            <div class="col" style="flex: 2;">
                <label>Nama / Deskripsi Barang (DESC1)</label>
                <input type="text" name="desc1" maxlength="50" class="uppercase-input" placeholder="Contoh: JUMBO EGG" required>
            </div>
            <div class="col">
                <label>Harga Satuan (UPRICE)</label>
                <input type="number" name="uprice" value="1" min="0" step="1" required>
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label>Satuan Utama (UOM)</label>
                <input type="text" name="uom" maxlength="6" class="uppercase-input" placeholder="KG / PCS" required>
            </div>
            <div class="col">
                <label>Satuan Kedua (UOM2)</label>
                <input type="text" name="uom2" maxlength="10" class="uppercase-input" placeholder="PCS / BOX">
            </div>
            <div class="col">
                <label>Faktor Konversi (CONV_FACT)</label>
                <input type="number" step="0.01" name="conv_fact" value="0.00" required>
            </div>
        </div>

        <div style="text-align: right; margin-top: 10px;">
            <button type="submit" class="btn btn-primary">💾 Simpan Item</button>
        </div>
    </form>
</div>

<!-- DAFTAR ITEM TABLE -->
<div class="card">
    <h3>Daftar Master Item (itemast)</h3>
    <table>
        <thead>
            <tr>
                <th>ICODE</th>
                <th>DESC1</th>
                <th>UOM</th>
                <th>UOM2</th>
                <th>CONV_FACT</th>
                <th>UPRICE</th>
                <th>CCODE</th>
                <th>STOCK</th>
                <th>ACTION</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it): ?>
            <tr>
                <td><strong><?= htmlspecialchars($it['icode']) ?></strong></td>
                <td><?= htmlspecialchars($it['desc1']) ?></td>
                <td><?= htmlspecialchars($it['uom']) ?></td>
                <td><?= htmlspecialchars($it['uom2']) ?></td>
                <td><?= number_format($it['conv_fact'], 2) ?></td>
                <td><?= number_format($it['uprice'], 0) ?></td>
                <td><?= htmlspecialchars($it['ccode']) ?></td>
                <td style="text-align: center;">
                    <?php if ($it['stock'] === 'A'): ?>
                        <span class="badge badge-a">A (Active)</span>
                    <?php else: ?>
                        <span class="badge badge-n">N (Non-Active)</span>
                    <?php endif; ?>
                </td>
                <td style="text-align: center;">
                    <?php if ($it['stock'] === 'A'): ?>
                        <form method="POST" onsubmit="return confirm('Non-aktifkan barang ini (set stock = N)?');" style="display:inline;">
                            <input type="hidden" name="action" value="SOFT_DELETE">
                            <input type="hidden" name="icode" value="<?= $it['icode'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding: 3px 8px; font-size:11px;">Set 'N'</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="ACTIVATE">
                            <input type="hidden" name="icode" value="<?= $it['icode'] ?>">
                            <button type="submit" class="btn btn-success" style="padding: 3px 8px; font-size:11px;">Aktifkan (A)</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.querySelectorAll('.uppercase-input').forEach(function(element) {
    element.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
});
</script>

</body>
</html>