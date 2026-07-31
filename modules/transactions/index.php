<?php
// modules/transactions/index.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

$current_user = 'admin'; 
$user_role    = 'SUPERADMIN'; 

// 1. Ambil Gudang Sesuai Hak Akses User
$warehouses = getUserWarehouses($pdo, $current_user);

// 2. Handling Filter Parameters
$filter_type    = $_GET['filter_type'] ?? 'IN'; // Mandatory default 'IN'
$filter_from    = $_GET['filter_from'] ?? date('Y-m-01');
$filter_to      = $_GET['filter_to'] ?? date('Y-m-d');
$filter_whid    = $_GET['filter_whid'] ?? ($warehouses[0]['whid'] ?? '');
$search_keyword = trim($_GET['search'] ?? '');

// Paging parameters
$limit_param = $_GET['limit'] ?? '20';
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit       = ($limit_param === 'all') ? 999999 : max(1, (int)$limit_param);
$offset      = ($page - 1) * $limit;

// Mapping tabel berdasarkan jenis transaksi
if ($filter_type === 'OUT') {
    $masTable = 'othoutmas'; $detTable = 'othoutdet'; $idCol = 'othoutid'; $dateCol = 'othoutdate';
} elseif ($filter_type === 'ADJ') {
    $masTable = 'adjmas';    $detTable = 'adjdet';    $idCol = 'adjid';    $dateCol = 'adjdate';
} else { // IN
    $masTable = 'othinmas';  $detTable = 'othindet';  $idCol = 'othinid';  $dateCol = 'othindate';
}

// Build SQL Base
$whereClauses = ["m.{$dateCol} BETWEEN :from_date AND :to_date"];
$params = [':from_date' => $filter_from, ':to_date' => $filter_to];

if (!empty($filter_whid)) {
    $whereClauses[] = "m.whid = :whid";
    $params[':whid'] = $filter_whid;
}

if (!empty($search_keyword)) {
    $whereClauses[] = "(d.icode LIKE :search OR i.desc1 LIKE :search OR m.remark LIKE :search OR m.{$idCol} LIKE :search)";
    $params[':search'] = "%{$search_keyword}%";
}

$whereSql = "WHERE " . implode(" AND ", $whereClauses);

// Count Total Rows
$countSql = "SELECT COUNT(*) FROM {$masTable} m JOIN {$detTable} d ON m.{$idCol} = d.{$idCol} LEFT JOIN itemast i ON d.icode = i.icode {$whereSql}";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRows = $stmtCount->fetchColumn();
$totalPages = ($limit_param === 'all' || $totalRows == 0) ? 1 : ceil($totalRows / $limit);

// Fetch Data
$sql = "SELECT m.{$idCol} AS doc_no, m.{$dateCol} AS trans_date, m.remark, m.whid, m.status,
               d.icode, d.qty, d.uom, d.qty2, d.uom2, i.desc1 AS item_name
        FROM {$masTable} m
        JOIN {$detTable} d ON m.{$idCol} = d.{$idCol}
        LEFT JOIN itemast i ON d.icode = i.icode
        {$whereSql}
        ORDER BY trans_date DESC, doc_no DESC 
        LIMIT {$limit} OFFSET {$offset}";

$stmtList = $pdo->prepare($sql);
$stmtList->execute($params);
$listData = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// Master Item Dropdown
// Master Item Dropdown (Menampilkan Semua Item: Kode Item - Nama Item)
$stmtItem = $pdo->query("SELECT icode, desc1, uom FROM itemast WHERE stock = 'A' ORDER BY icode ASC");
$rawItems = $stmtItem->fetchAll(PDO::FETCH_ASSOC);

$groupedItems = [];
foreach ($rawItems as $item) {
    // Menyimpan icode sebagai key agar setiap item berdiri sendiri (tidak ter-group)
    $groupedItems[$item['icode']] = [
        'base_code' => $item['icode'],
        // Format Tampilan: KODE_ITEM - DESKRIPSI
        'desc'      => $item['icode'] . ' - ' . $item['desc1'],
        'uom'       => $item['uom']
    ];
}
render_header("Transaction");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Form & List Transaksi Inventory</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background-color: #f4f6f9; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); max-width: 1150px; margin: 0 auto 25px auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 13px; color: #495057; }
        input, select, textarea { width: 100%; padding: 8px 10px; box-sizing: border-box; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        input[readonly], select[disabled] { background-color: #e9ecef; cursor: not-allowed; }
        .row { display: flex; gap: 15px; align-items: flex-end; }
        .col { flex: 1; }
        
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        table.data-table th, table.data-table td { border: 1px solid #e9ecef; padding: 8px 10px; text-align: left; }
        table.data-table th { background-color: #f8f9fa; color: #495057; font-weight: 600; }
        
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; text-align: center; }
        .badge-a { background-color: #e3f2fd; color: #0d47a1; } /* Active */
        .badge-p { background-color: #e8f5e9; color: #1b5e20; } /* Posted */
        .badge-x { background-color: #ffebee; color: #b71c1c; } /* Cancel */
        
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 13px; }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
        .btn-primary { background-color: #0288d1; color: white; }
        .btn-success { background-color: #2e7d32; color: white; }
        .btn-danger { background-color: #d32f2f; color: white; }
        .btn-secondary { background-color: #eceff1; color: #37474f; }

        .action-link { color: #0288d1; text-decoration: none; margin-right: 6px; font-weight: 600; cursor: pointer; }
        .action-link.edit { color: #f57c00; }
        .action-link.delete { color: #d32f2f; }

        .pagination-container { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; font-size: 13px; }
        .pagination-nav { display: flex; gap: 4px; }
        .pagination-nav a, .pagination-nav span { padding: 5px 10px; border: 1px solid #ced4da; border-radius: 4px; text-decoration: none; color: #0288d1; }
        .pagination-nav .active { background-color: #0288d1; color: #fff; border-color: #0288d1; }
        .pagination-nav .disabled { color: #ccc; pointer-events: none; }

        /* Modal Popup CSS */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fff; margin: 3% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 900px; max-height: 90vh; overflow-y: auto; }
        .close-btn { float: right; font-size: 20px; font-weight: bold; cursor: pointer; color: #aaa; }
        .close-btn:hover { color: #000; }
    </style>
</head>
<body>

<!-- FORM INPUT TRANSAKSI (ATAS) -->
<div class="card">
    <h2>Input Transaksi Barang (In / Out / Adjustment)</h2>
    <form action="process.php" method="POST">
        <input type="hidden" name="action_type" value="CREATE">
        
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
                <input type="text" name="doc_no" id="doc_no" readonly required placeholder="Generating...">
            </div>
        </div>

        <div class="row">
            <div class="col form-group">
                <label>Gudang / Farm</label>
                <select name="whid" required>
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

        <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">
        <h3>Detail Barang</h3>
        <table id="itemTable" class="data-table">
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
                                <option value="<?= htmlspecialchars($item['base_code']) ?>"><?= htmlspecialchars($item['desc']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" step="0.01" name="items[0][qty_kg]" value="0.00" required></td>
                    <td><input type="number" step="1" name="items[0][qty_pcs]" value="0" required></td>
                    <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">X</button></td>
                </tr>
            </tbody>
        </table>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
            <button type="button" class="btn btn-primary btn-sm" onclick="addRow()">+ Tambah Barang</button>
            <!-- Tombol Simpan Dibuat Lebih Ringkas & Pendek -->
            <button type="submit" class="btn btn-success" style="width: auto; padding: 8px 30px;">Simpan Transaksi</button>
        </div>
    </form>
</div>

<!-- COMPONENT TRANSACTION LIST (BAWAH) -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h3 style="margin:0;">📋 Transaction List</h3>
    </div>

    <!-- FILTER FORM -->
    <form method="GET" action="index.php" id="filterForm">
        <div class="row">
            <div class="col form-group">
                <label>Jenis Transaksi</label>
                <select name="filter_type" required onchange="document.getElementById('filterForm').submit()">
                    <option value="IN" <?= $filter_type === 'IN' ? 'selected' : '' ?>>Barang Masuk (IN)</option>
                    <option value="OUT" <?= $filter_type === 'OUT' ? 'selected' : '' ?>>Barang Keluar (OUT)</option>
                    <option value="ADJ" <?= $filter_type === 'ADJ' ? 'selected' : '' ?>>Stock Opname / Adj (ADJ)</option>
                </select>
            </div>

            <div class="col form-group">
                <label>Tanggal (From Date - To Date)</label>
                <div style="display:flex; gap:5px;">
                    <input type="date" name="filter_from" value="<?= htmlspecialchars($filter_from) ?>">
                    <input type="date" name="filter_to" value="<?= htmlspecialchars($filter_to) ?>">
                </div>
            </div>

            <!-- Dropdown Gudang/Farm: Pilihan [-Semua-] DIHILANGKAN -->
            <div class="col form-group">
                <label>Gudang / Farm</label>
                <select name="filter_whid" required>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= htmlspecialchars($wh['whid']) ?>" <?= $filter_whid === $wh['whid'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($wh['whname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="display:flex; gap:5px;">
                <button type="submit" class="btn btn-primary">🔍 Retrieve</button>
                <a href="index.php" class="btn btn-secondary" style="text-decoration:none; text-align:center;">🔄</a>
            </div>
        </div>

        <div class="row" style="margin-top: 10px;">
            <div class="col form-group">
                <input type="text" name="search" value="<?= htmlspecialchars($search_keyword) ?>" placeholder="🔍 Cari No Dokumen / Barang / Keterangan...">
            </div>
            <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                <label style="margin:0; whitespace:nowrap;">Per Page:</label>
                <select name="limit" onchange="document.getElementById('filterForm').submit()" style="width: auto;">
                    <option value="20" <?= $limit_param == '20' ? 'selected' : '' ?>>20</option>
                    <option value="30" <?= $limit_param == '30' ? 'selected' : '' ?>>30</option>
                    <option value="50" <?= $limit_param == '50' ? 'selected' : '' ?>>50</option>
                    <option value="all" <?= $limit_param == 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </div>
        </div>
    </form>

    <!-- DATA TABLE LIST -->
    <table class="data-table">
        <thead>
            <tr>
                <th>JENIS TRANSAKSI</th>
                <th>NO DOKUMEN</th>
                <th>TANGGAL</th>
                <th>KETERANGAN</th>
                <th>NAMA BARANG</th>
                <th style="text-align:right;">JUMLAH</th>
                <th>UOM</th>
                <th style="text-align:right;">JUMLAH2</th>
                <th>UOM2</th>
                <th style="text-align:center;">STATUS</th>
                <th style="text-align:center;">ACTION</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($listData)): ?>
                <?php foreach ($listData as $row): ?>
                    <tr>
                        <td>
                            <?php 
                            if ($filter_type === 'IN') echo 'Barang Masuk';
                            elseif ($filter_type === 'OUT') echo 'Barang Keluar';
                            else echo 'Stock Opname';
                            ?>
                        </td>
                        <td><strong><?= htmlspecialchars($row['doc_no']) ?></strong></td>
                        <td><?= date('d/m/Y', strtotime($row['trans_date'])) ?></td>
                        <td><?= htmlspecialchars($row['remark'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($row['item_name'] ?: $row['icode']) ?></td>
                        <td style="text-align:right;"><?= number_format($row['qty'], 2) ?></td>
                        <td><?= htmlspecialchars($row['uom']) ?></td>
                        <td style="text-align:right;"><?= number_format($row['qty2'], 0) ?></td>
                        <td><?= htmlspecialchars($row['uom2']) ?></td>
                        
                        <!-- Kolom Status -->
                        <td style="text-align:center;">
                            <?php if ($row['status'] === 'A'): ?>
                                <span class="badge badge-a">A - Active</span>
                            <?php elseif ($row['status'] === 'P'): ?>
                                <span class="badge badge-p">P - Posted</span>
                            <?php else: ?>
                                <span class="badge badge-x">X - Cancel</span>
                            <?php endif; ?>
                        </td>

                        <!-- Kolom Action -->
                        <td style="text-align:center; white-space: nowrap;">
                            <?php if ($row['status'] === 'X'): ?>
                                <!-- Jika Cancel, HANYA VIEW -->
                                <span class="action-link" onclick="openViewModal('<?= $filter_type ?>', '<?= $row['doc_no'] ?>')">View</span>
                            <?php else: ?>
                                <span class="action-link" onclick="openViewModal('<?= $filter_type ?>', '<?= $row['doc_no'] ?>')">View</span>
                                <span class="action-link edit" onclick="handleEdit('<?= $filter_type ?>', '<?= $row['doc_no'] ?>', '<?= $row['status'] ?>')">Edit</span>
                                <span class="action-link delete" onclick="handleDelete('<?= $filter_type ?>', '<?= $row['doc_no'] ?>')">Delete</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11" style="text-align:center; color:#888; padding:20px;">Data tidak ditemukan.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- PAGING COMPONENT -->
    <?php if ($limit_param !== 'all' && $totalPages > 1): 
        $queryParams = $_GET;
        $buildUrl = function($p) use ($queryParams) {
            $queryParams['page'] = $p;
            return 'index.php?' . http_build_query($queryParams);
        };
    ?>
    <div class="pagination-container">
        <div>Showing entries <?= $totalRows > 0 ? $offset + 1 : 0 ?> to <?= min($offset + $limit, $totalRows) ?> of <?= $totalRows ?></div>
        <div class="pagination-nav">
            <a href="<?= $buildUrl(1) ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">First</a>
            <a href="<?= $buildUrl(max(1, $page - 1)) ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
            
            <span class="active"><?= $page ?> / <?= $totalPages ?></span>

            <a href="<?= $buildUrl(min($totalPages, $page + 1)) ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>">Next</a>
            <a href="<?= $buildUrl($totalPages) ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>">Last</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL POPUP VIEW / EDIT -->
<div id="txnModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">&times;</span>
        <h3 id="modalTitle">Detail Transaksi</h3>
        <div id="modalBody">
            <p>Loading data...</p>
        </div>
    </div>
</div>

<script>
let rowIdx = 1;

function fetchDocNo() {
    const type = document.getElementById('trans_type').value;
    const docInput = document.getElementById('doc_no');
    docInput.value = 'Loading...';

    fetch(`get_doc_no.php?type=${type}`)
        .then(response => response.json())
        .then(data => {
            docInput.value = data.success ? data.doc_no : '';
        })
        .catch(() => docInput.value = '');
}

document.addEventListener('DOMContentLoaded', fetchDocNo);

function addRow() {
    const table = document.getElementById('itemTable').getElementsByTagName('tbody')[0];
    const newRow = table.insertRow();
newRow.innerHTML = `
        <td>
            <select name="items[${rowIdx}][item_base]" required>
                <option value="">-- Pilih Barang --</option>
                <?php foreach ($groupedItems as $item): ?>
                    <option value="<?= htmlspecialchars($item['base_code']) ?>">
                        <?= htmlspecialchars($item['desc']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="number" step="0.01" name="items[${rowIdx}][qty_kg]" value="0.00" required></td>
        <td><input type="number" step="1" name="items[${rowIdx}][qty_pcs]" value="0" required></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">X</button></td>
    `;
    rowIdx++;
}

function removeRow(btn) {
    if (document.querySelectorAll('#itemTable tbody tr').length > 1) {
        btn.parentNode.parentNode.remove();
    } else {
        alert("Minimal 1 barang!");
    }
}

// Modal View Handler
function openViewModal(type, docNo) {
    document.getElementById('modalTitle').innerText = `View Transaksi (${docNo})`;
    document.getElementById('txnModal').style.display = 'block';
    
    fetch(`get_transaction_detail.php?type=${type}&doc_no=${docNo}&mode=view`)
        .then(res => res.text())
        .then(html => document.getElementById('modalBody').innerHTML = html);
}

// Edit Handler with Validation Check
function handleEdit(type, docNo, status) {
    if (status === 'P') {
        alert("Hanya data aktif yang dapat diedit");
        return;
    }
    
    document.getElementById('modalTitle').innerText = `Edit Transaksi (${docNo})`;
    document.getElementById('txnModal').style.display = 'block';

    fetch(`get_transaction_detail.php?type=${type}&doc_no=${docNo}&mode=edit`)
        .then(res => res.text())
        .then(html => document.getElementById('modalBody').innerHTML = html);
}

// Soft Delete (Beli Status X)
function handleDelete(type, docNo) {
    if (confirm(`Apakah Anda yakin ingin membatalkan (Delete) transaksi ${docNo}?`)) {
        fetch('process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action_type=DELETE&type=${type}&doc_no=${docNo}`
        })
        .then(res => res.json())
        .then(data => {
            alert(data.message);
            if (data.success) location.reload();
        });
    }
}

function closeModal() {
    document.getElementById('txnModal').style.display = 'none';
}
</script>

</body>
</html>