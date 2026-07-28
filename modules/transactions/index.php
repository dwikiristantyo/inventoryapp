<?php
// modules/transactions/index.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

$current_user = 'admin';

// Ambil gudang sesuai hak akses user
$warehouses = getUserWarehouses($pdo, $current_user);

// Ambil master item aktif dari itemast
$stmtItem = $pdo->query("SELECT icode, desc1, uom FROM itemast WHERE stock = 'A' ORDER BY icode ASC");
$rawItems = $stmtItem->fetchAll();

/*
  Pengelompokan item berdasarkan dasar kode (misal: EG100, EG200, EG300)
  sehingga 1 item memiliki referensi kode KG dan PCS sekaligus.
*/
$groupedItems = [];
foreach ($rawItems as $item) {
    // Ambil prefix kode barang (misal 'EG100' dari 'EG1001')
    $baseCode = substr($item['icode'], 0, 5); 
    
    // Bersihkan nama item dari frasa (KG) / (PCS) untuk tampilan bersih
    $cleanDesc = trim(preg_replace('/\s*\((KG|PCS)\)\s*/i', '', $item['desc1']));
    
    if (!isset($groupedItems[$baseCode])) {
        $groupedItems[$baseCode] = [
            'base_code' => $baseCode,
            'desc'      => $cleanDesc,
            'code_kg'   => '',
            'code_pcs'  => ''
        ];
    }
    
    if (strtoupper($item['uom']) === 'KG') {
        $groupedItems[$baseCode]['code_kg'] = $item['icode'];
    } elseif (strtoupper($item['uom']) === 'PCS') {
        $groupedItems[$baseCode]['code_pcs'] = $item['icode'];
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Form Transaksi Inventory Egg</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f8f9fa; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 900px; margin: auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input, select, textarea { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        input[readonly] { background-color: #e9ecef; cursor: not-allowed; }
        .row { display: flex; gap: 15px; }
        .col { flex: 1; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        table, th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: middle; }
        th { background-color: #f1f1f1; }
        .qty-input-group { display: flex; align-items: center; gap: 5px; }
        .qty-input-group input { width: 80px; text-align: right; }
        .qty-input-group span { font-size: 12px; font-weight: bold; color: #555; }
        .btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-primary { background-color: #0d6efd; color: white; }
        .btn-success { background-color: #198754; color: white; }
        .btn-danger { background-color: #dc3545; color: white; }
    </style>
</head>
<body>

<div class="card">
    <h2>Input Transaksi Barang (In / Out / Adjustment)</h2>
    <form action="process.php" method="POST">
        
        <div class="row">
            <div class="col form-group">
                <label>Jenis Transaksi</label>
                <select name="type" id="trans_type" onchange="fetchDocNo()" required>
                    <option value="IN">Barang Masuk (IN)</option>
                    <option value="OUT">Barang Keluar (OUT)</option>
                    <option value="ADJ">Adjustment Stock (ADJ)</option>
                </select>
            </div>
            <div class="col form-group">
                <label>No. Dokumen (Auto)</label>
                <!-- Field No Dokumen dibuat READONLY / Disable diketik manual -->
                <input type="text" name="doc_no" id="doc_no" readonly required placeholder="Generating...">
            </div>
        </div>

        <div class="row">
            <div class="col form-group">
                <label>Gudang / Farm (Akses User)</label>
                <select name="whid" required>
                    <option value="">-- Pilih Gudang --</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= htmlspecialchars($wh['whid']) ?>">
                            <?= htmlspecialchars($wh['whname']) ?> (<?= htmlspecialchars($wh['whid']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col form-group">
                <label>Tanggal Transaksi</label>
                <input type="date" name="trans_date" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Keterangan / Remark</label>
            <textarea name="remark" rows="2" placeholder="Catatan transaksi..."></textarea>
        </div>

        <hr>
        <h3>Detail Barang</h3>
        <table id="itemTable">
            <thead>
                <tr>
                    <th>Nama Barang</th>
                    <th width="140">Jumlah (KG)</th>
                    <th width="140">Jumlah (Butir/PCS)</th>
                    <th width="50">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <select name="items[0][item_base]" required>
                            <option value="">-- Pilih Barang --</option>
                            <?php foreach ($groupedItems as $item): ?>
                                <option value="<?= htmlspecialchars($item['base_code']) ?>"
                                        data-kg="<?= htmlspecialchars($item['code_kg']) ?>"
                                        data-pcs="<?= htmlspecialchars($item['code_pcs']) ?>">
                                    <?= htmlspecialchars($item['desc']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <div class="qty-input-group">
                            <input type="number" step="0.01" name="items[0][qty_kg]" placeholder="0.00" value="0.00" required>
                            <span>KG</span>
                        </div>
                    </td>
                    <td>
                        <div class="qty-input-group">
                            <input type="number" step="1" name="items[0][qty_pcs]" placeholder="0" value="0" required>
                            <span>Butir</span>
                        </div>
                    </td>
                    <td><button type="button" class="btn btn-danger" onclick="removeRow(this)">X</button></td>
                </tr>
            </tbody>
        </table>

        <br>
        <button type="button" class="btn btn-primary" onclick="addRow()">+ Tambah Barang</button>
        <br><br>

        <button type="submit" class="btn btn-success" style="width: 100%;">Simpan Transaksi</button>
    </form>
</div>

<script>
let rowIdx = 1;

// Function AJAX untuk mengambil No. Dokumen Otomatis dari sysset
function fetchDocNo() {
    const type = document.getElementById('trans_type').value;
    const docInput = document.getElementById('doc_no');
    docInput.value = 'Loading...';

    fetch(`get_doc_no.php?type=${type}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                docInput.value = data.doc_no;
            } else {
                docInput.value = '';
                alert('Gagal mengambil nomor dokumen dari sysset');
            }
        })
        .catch(err => {
            console.error(err);
            docInput.value = '';
        });
}

// Generate nomor dokumen pertama kali saat halaman dimuat
document.addEventListener('DOMContentLoaded', fetchDocNo);

function addRow() {
    const table = document.getElementById('itemTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
    
    newRow.innerHTML = `
        <td>
            <select name="items[${rowIdx}][item_base]" required>
                <option value="">-- Pilih Barang --</option>
                <?php foreach ($groupedItems as $item): ?>
                    <option value="<?= htmlspecialchars($item['base_code']) ?>"
                            data-kg="<?= htmlspecialchars($item['code_kg']) ?>"
                            data-pcs="<?= htmlspecialchars($item['code_pcs']) ?>">
                        <?= htmlspecialchars($item['desc']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <div class="qty-input-group">
                <input type="number" step="0.01" name="items[${rowIdx}][qty_kg]" placeholder="0.00" value="0.00" required>
                <span>KG</span>
            </div>
        </td>
        <td>
            <div class="qty-input-group">
                <input type="number" step="1" name="items[${rowIdx}][qty_pcs]" placeholder="0" value="0" required>
                <span>Butir</span>
            </div>
        </td>
        <td><button type="button" class="btn btn-danger" onclick="removeRow(this)">X</button></td>
    `;
    rowIdx++;
}

function removeRow(btn) {
    const row = btn.parentNode.parentNode;
    if (document.querySelectorAll('#itemTable tbody tr').length > 1) {
        row.parentNode.removeChild(row);
    } else {
        alert("Minimal harus menyertakan 1 barang!");
    }
}
</script>

</body>
</html>