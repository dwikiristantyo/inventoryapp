<?php
// modules/transactions/get_transaction_detail.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

$type   = $_GET['type'] ?? 'IN';
$docNo  = $_GET['doc_no'] ?? '';
$mode   = $_GET['mode'] ?? 'view'; // 'view' atau 'edit'

if (empty($docNo)) die("No Dokumen tidak valid.");

// Table Map
if ($type === 'OUT') {
    $masTable = 'othoutmas'; $detTable = 'othoutdet'; $idCol = 'othoutid'; $dateCol = 'othoutdate';
} elseif ($type === 'ADJ') {
    $masTable = 'adjmas';    $detTable = 'adjdet';    $idCol = 'adjid';    $dateCol = 'adjdate';
} else {
    $masTable = 'othinmas';  $detTable = 'othindet';  $idCol = 'othinid';  $dateCol = 'othindate';
}

// Get Header
$stmtH = $pdo->prepare("SELECT * FROM {$masTable} WHERE {$idCol} = :docNo");
$stmtH->execute([':docNo' => $docNo]);
$header = $stmtH->fetch(PDO::FETCH_ASSOC);

if (!$header) die("Data transaksi tidak ditemukan.");

// Get Detail
$stmtD = $pdo->prepare("SELECT d.*, i.desc1 FROM {$detTable} d LEFT JOIN itemast i ON d.icode = i.icode WHERE d.{$idCol} = :docNo");
$stmtD->execute([':docNo' => $docNo]);
$details = $stmtD->fetchAll(PDO::FETCH_ASSOC);

$isReadOnly = ($mode === 'view');
?>

<form action="process.php" method="POST">
    <input type="hidden" name="action_type" value="UPDATE">
    <input type="hidden" name="type" value="<?= $type ?>">
    <input type="hidden" name="doc_no" value="<?= htmlspecialchars($docNo) ?>">

    <div class="row">
        <div class="col form-group">
            <label>Jenis Transaksi</label>
            <input type="text" value="<?= $type ?>" disabled>
        </div>
        <div class="col form-group">
            <label>No. Dokumen</label>
            <input type="text" value="<?= htmlspecialchars($docNo) ?>" disabled>
        </div>
    </div>

    <div class="row">
        <div class="col form-group">
            <label>Gudang / Farm</label>
            <input type="text" value="<?= htmlspecialchars($header['whid']) ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
        </div>
        <div class="col form-group">
            <label>Tanggal Transaksi</label>
            <input type="date" name="trans_date" value="<?= $header[$dateCol] ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
        </div>
    </div>

    <div class="form-group">
        <label>Keterangan / Remark</label>
        <textarea name="remark" rows="2" <?= $isReadOnly ? 'disabled' : '' ?>><?= htmlspecialchars($header['remark']) ?></textarea>
    </div>

    <h4>Detail Barang</h4>
    <table class="data-table">
        <thead>
            <tr>
                <th>Kode Barang</th>
                <th>Nama Barang</th>
                <th width="120">Jumlah (KG)</th>
                <th width="120">Jumlah (PCS)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($details as $idx => $det): ?>
            <tr>
                <td>
                    <input type="hidden" name="items[<?= $idx ?>][icode]" value="<?= $det['icode'] ?>">
                    <?= htmlspecialchars($det['icode']) ?>
                </td>
                <td><?= htmlspecialchars($det['desc1'] ?: '-') ?></td>
                <td>
                    <input type="number" step="0.01" name="items[<?= $idx ?>][qty_kg]" value="<?= $det['qty'] ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                </td>
                <td>
                    <input type="number" step="1" name="items[<?= $idx ?>][qty_pcs]" value="<?= $det['qty2'] ?>" <?= $isReadOnly ? 'disabled' : '' ?>>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 20px; text-align: right; display: flex; gap: 10px; justify-content: flex-end;">
        <?php if (!$isReadOnly): ?>
            <button type="submit" class="btn btn-success">Save Changes</button>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
    </div>
</form>