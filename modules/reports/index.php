<?php
// modules/reports/index.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

$current_user = 'admin';

// 1. Ambil Hak Akses Gudang
$warehouses = getUserWarehouses($pdo, $current_user);

// 2. Filter Parameters
$filter_from   = $_GET['filter_from'] ?? date('Y-m-01');
$filter_to     = $_GET['filter_to'] ?? date('Y-m-d');
$filter_whid   = $_GET['filter_whid'] ?? ($warehouses[0]['whid'] ?? '');
$filter_icodes = $_GET['filter_icodes'] ?? ['ALL'];

if (!is_array($filter_icodes)) {
    $filter_icodes = [$filter_icodes];
}

// Ambil Daftar Semua Barang untuk Dropdown
$stmtAllItems = $pdo->prepare("SELECT icode, desc1 FROM itemast WHERE stock = 'A' ORDER BY icode ASC");
$stmtAllItems->execute();
$all_master_items = $stmtAllItems->fetchAll(PDO::FETCH_ASSOC);

$items_report = [];

if (!empty($filter_whid)) {
    if (in_array('ALL', $filter_icodes) || empty($filter_icodes)) {
        $master_items = $all_master_items;
    } else {
        $inQuery = implode(',', array_fill(0, count($filter_icodes), '?'));
        $stmtItems = $pdo->prepare("SELECT icode, desc1 FROM itemast WHERE stock = 'A' AND icode IN ($inQuery) ORDER BY icode ASC");
        $stmtItems->execute($filter_icodes);
        $master_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($master_items as $item) {
        $icode = $item['icode'];

        // A. Hitung Saldo Awal (Sebelum tanggal filter_from)
        $sqlInit = "
            SELECT 
                COALESCE(SUM(CASE WHEN t.type = 'IN' THEN t.qty ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN t.type = 'OUT' THEN t.qty ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN t.type = 'ADJ' THEN t.qty ELSE 0 END), 0) AS init_kg,
                
                COALESCE(SUM(CASE WHEN t.type = 'IN' THEN t.qty2 ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN t.type = 'OUT' THEN t.qty2 ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN t.type = 'ADJ' THEN t.qty2 ELSE 0 END), 0) AS init_pcs
            FROM (
                SELECT m.othindate AS trans_date, m.whid, d.icode, d.qty, d.qty2, 'IN' AS type 
                FROM othinmas m JOIN othindet d ON m.othinid = d.othinid WHERE m.status != 'X'
                UNION ALL
                SELECT m.othoutdate AS trans_date, m.whid, d.icode, d.qty, d.qty2, 'OUT' AS type 
                FROM othoutmas m JOIN othoutdet d ON m.othoutid = d.othoutid WHERE m.status != 'X'
                UNION ALL
                SELECT m.adjdate AS trans_date, m.whid, d.icode, d.qty, d.qty2, 'ADJ' AS type 
                FROM adjmas m JOIN adjdet d ON m.adjid = d.adjid WHERE m.status != 'X'
            ) t
            WHERE t.whid = :whid AND t.icode = :icode AND t.trans_date < :from_date
        ";
        $stmtInit = $pdo->prepare($sqlInit);
        $stmtInit->execute([
            ':whid'      => $filter_whid,
            ':icode'     => $icode,
            ':from_date' => $filter_from
        ]);
        $init_bal = $stmtInit->fetch(PDO::FETCH_ASSOC);

        $running_kg  = (float)($init_bal['init_kg'] ?? 0);
        $running_pcs = (float)($init_bal['init_pcs'] ?? 0);

        // B. Query Mutasi Transaksi
        // Urutan: Tanggal ASC -> Type (ADJ, IN, OUT) -> ID Transaksi ASC
        $sqlTrans = "
            SELECT 
                t.trans_date,
                t.trans_id,
                t.remark,
                t.type,
                t.qty AS kg,
                t.qty2 AS pcs
            FROM (
                SELECT m.othinid AS trans_id, m.othindate AS trans_date, m.whid, d.icode, m.remark, d.qty, d.qty2, 'IN' AS type 
                FROM othinmas m JOIN othindet d ON m.othinid = d.othinid WHERE m.status != 'X'
                UNION ALL
                SELECT m.othoutid AS trans_id, m.othoutdate AS trans_date, m.whid, d.icode, m.remark, d.qty, d.qty2, 'OUT' AS type 
                FROM othoutmas m JOIN othoutdet d ON m.othoutid = d.othoutid WHERE m.status != 'X'
                UNION ALL
                SELECT m.adjid AS trans_id, m.adjdate AS trans_date, m.whid, d.icode, m.remark, d.qty, d.qty2, 'ADJ' AS type 
                FROM adjmas m JOIN adjdet d ON m.adjid = d.adjid WHERE m.status != 'X'
            ) t
            WHERE t.whid = :whid AND t.icode = :icode AND t.trans_date BETWEEN :from_date AND :to_date
            ORDER BY t.trans_date ASC, FIELD(t.type, 'ADJ', 'IN', 'OUT'), t.trans_id ASC
        ";
        $stmtTrans = $pdo->prepare($sqlTrans);
        $stmtTrans->execute([
            ':whid'      => $filter_whid,
            ':icode'     => $icode,
            ':from_date' => $filter_from,
            ':to_date'   => $filter_to
        ]);
        $mutations = $stmtTrans->fetchAll(PDO::FETCH_ASSOC);

        // Subtotal Grouping berdasarkan Tanggal
        $grouped_mutations = [];
        
        // Baris Pertama: Closing Balance
        $first_date = date('Y-m-d', strtotime($filter_from . ' -1 day'));
        $grouped_mutations[$first_date][] = [
            'type'    => 'INIT',
            'remark'  => 'Closing Balance',
            'in_kg'   => 0, 'in_pcs'  => 0,
            'out_kg'  => 0, 'out_pcs' => 0,
            'adj_kg'  => 0, 'adj_pcs' => 0,
            'bal_kg'  => $running_kg,
            'bal_pcs' => $running_pcs
        ];

        foreach ($mutations as $m) {
            $date = $m['trans_date'];
            $in_kg   = ($m['type'] === 'IN')  ? (float)$m['kg']  : 0;
            $in_pcs  = ($m['type'] === 'IN')  ? (float)$m['pcs'] : 0;
            $out_kg  = ($m['type'] === 'OUT') ? (float)$m['kg']  : 0;
            $out_pcs = ($m['type'] === 'OUT') ? (float)$m['pcs'] : 0;
            $adj_kg  = ($m['type'] === 'ADJ') ? (float)$m['kg']  : 0;
            $adj_pcs = ($m['type'] === 'ADJ') ? (float)$m['pcs'] : 0;

            $running_kg  += ($in_kg - $out_kg + $adj_kg);
            $running_pcs += ($in_pcs - $out_pcs + $adj_pcs);

            $grouped_mutations[$date][] = [
                'type'    => $m['type'],
                'remark'  => $m['remark'] ?: ($m['type'] === 'IN' ? 'Barang Masuk' : ($m['type'] === 'OUT' ? 'Barang Keluar' : 'Adjustment')),
                'in_kg'   => $in_kg,   'in_pcs'  => $in_pcs,
                'out_kg'  => $out_kg,  'out_pcs' => $out_pcs,
                'adj_kg'  => $adj_kg,  'adj_pcs' => $adj_pcs,
                'bal_kg'  => $running_kg,
                'bal_pcs' => $running_pcs
            ];
        }

        $items_report[] = [
            'icode'     => $item['icode'],
            'desc1'     => $item['desc1'],
            'grouped'   => $grouped_mutations,
            'final_kg'  => $running_kg,
            'final_pcs' => $running_pcs
        ];
    }
}

$excelParams = http_build_query([
    'filter_whid'   => $filter_whid,
    'filter_from'   => $filter_from,
    'filter_to'     => $filter_to,
    'filter_icodes' => $filter_icodes
]);

render_header("Report");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Mutasi Stok Detail</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 20px; background-color: #f8f9fa; color: #212529; }
        .card { background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); max-width: 1250px; margin: 0 auto 20px auto; }
        .row { display: flex; gap: 15px; align-items: flex-end; }
        .col { flex: 1; }
        label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 13px; }
        input, select { width: 100%; padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        select[multiple] { height: 80px; }

        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-primary { background-color: #0288d1; color: white; }
        .btn-success { background-color: #2e7d32; color: white; }
        .btn-secondary { background-color: #eceff1; color: #37474f; }

        .item-title { font-size: 16px; font-weight: bold; margin-top: 25px; margin-bottom: 8px; color: #000; }
        .item-title span.uom { font-weight: normal; color: #6c757d; font-size: 13px; }

        table.ledger-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 12px; }
        table.ledger-table th, table.ledger-table td { border: 1px solid #000; padding: 5px 8px; }
        
        /* 1. Header Warna Biru */
        table.ledger-table th { background-color: #8EA9DB; font-weight: bold; text-align: center; color: #000; }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-left { text-align: left; }
        
        /* 2. Style Subtotal Warna Kuning */
        .subtotal-row { background-color: #FFFF00; font-weight: bold; }
        .grand-total-row { font-weight: bold; background-color: #f1f1f1; }

        @media print {
            .no-print { display: none; }
            .card { box-shadow: none; padding: 0; max-width: 100%; }
            body { background-color: #fff; margin: 0; }
        }
    </style>
</head>
<body>

<div class="card no-print">
    <h2 style="margin-top:0;"> Filter Laporan Mutasi Stok</h2>
    <form method="GET" action="index.php">
        <div class="row">
            <div class="col">
                <label>Gudang / Farm</label>
                <select name="filter_whid" required>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= htmlspecialchars($wh['whid']) ?>" <?= $filter_whid === $wh['whid'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($wh['whname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col">
                <label>Pilih Barang <small style="color:#6c757d; font-weight:normal;">(Ctrl + Klik untuk >1)</small></label>
                <select name="filter_icodes[]" multiple>
                    <option value="ALL" <?= in_array('ALL', $filter_icodes) ? 'selected' : '' ?>>-- SEMUA BARANG --</option>
                    <?php foreach ($all_master_items as $mi): ?>
                        <option value="<?= htmlspecialchars($mi['icode']) ?>" <?= in_array($mi['icode'], $filter_icodes) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mi['icode']) ?> - <?= htmlspecialchars($mi['desc1']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col">
                <label>Periode Tanggal</label>
                <div style="display:flex; gap:5px;">
                    <input type="date" name="filter_from" value="<?= htmlspecialchars($filter_from) ?>">
                    <input type="date" name="filter_to" value="<?= htmlspecialchars($filter_to) ?>">
                </div>
            </div>
            <div>
                <button type="submit" class="btn btn-primary"> Tampilkan</button>
            </div>
        </div>
    </form>
</div>

<!-- CONTAINER LAPORAN -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center;" class="no-print">
        <div>
            <h3 style="margin:0;">Laporan Mutasi Stok</h3>
            <small>Gudang: <strong><?= htmlspecialchars($filter_whid) ?></strong> | Periode: <?= date('d-m-Y', strtotime($filter_from)) ?> s/d <?= date('d-m-Y', strtotime($filter_to)) ?></small>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-secondary"> Cetak</button>
            <a href="export_excel.php?<?= $excelParams ?>" class="btn btn-success"> Export Excel</a>
        </div>
    </div>

    <?php foreach ($items_report as $item): ?>
        <div class="item-title">
            <?= htmlspecialchars($item['icode']) ?> - <?= htmlspecialchars($item['desc1']) ?> 
            <span class="uom">(UOM: KG & Butir)</span>
        </div>

        <table class="ledger-table">
            <thead>
                <tr>
                    <th rowspan="2" width="90">Tanggal</th>
                    <th rowspan="2" width="200">Keterangan</th>
                    <th colspan="2">Saldo Awal</th>
                    <th colspan="2">Masuk (IN)</th>
                    <th colspan="2">Keluar (OUT)</th>
                    <th colspan="2">Adjustm</th>
                    <th colspan="2">Saldo Akhir</th>
                </tr>
                <tr>
                    <th width="70">KG</th>
                    <th width="70">Butir</th>
                    <th width="70">KG</th>
                    <th width="70">Butir</th>
                    <th width="70">KG</th>
                    <th width="70">Butir</th>
                    <th width="70">KG</th>
                    <th width="70">Butir</th>
                    <th width="70">KG</th>
                    <th width="70">Butir</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($item['grouped'] as $date => $rows): 
                    $last_row_bal_kg = 0;
                    $last_row_bal_pcs = 0;
                ?>
                    <?php foreach ($rows as $r): 
                        $last_row_bal_kg = $r['bal_kg'];
                        $last_row_bal_pcs = $r['bal_pcs'];
                        
                        $init_kg = $r['bal_kg'] - ($r['in_kg'] - $r['out_kg'] + $r['adj_kg']);
                        $init_pcs = $r['bal_pcs'] - ($r['in_pcs'] - $r['out_pcs'] + $r['adj_pcs']);
                    ?>
                        <tr>
                            <td class="text-center"><?= date('d-m-Y', strtotime($date)) ?></td>
                            <td class="text-left"><?= htmlspecialchars($r['remark']) ?></td>
                            
                            <!-- Saldo Awal -->
                            <td class="text-right"><?= number_format($init_kg, 2) ?></td>
                            <td class="text-right"><?= number_format($init_pcs) ?></td>
                            
                            <!-- Masuk -->
                            <td class="text-right"><?= number_format($r['in_kg'], 2) ?></td>
                            <td class="text-right"><?= number_format($r['in_pcs']) ?></td>
                            
                            <!-- Keluar -->
                            <td class="text-right"><?= number_format($r['out_kg'], 2) ?></td>
                            <td class="text-right"><?= number_format($r['out_pcs']) ?></td>
                            
                            <!-- Adjustm -->
                            <td class="text-right"><?= number_format($r['adj_kg'], 2) ?></td>
                            <td class="text-right"><?= number_format($r['adj_pcs']) ?></td>
                            
                            <!-- Saldo Akhir -->
                            <td class="text-right"><?= number_format($r['bal_kg'], 2) ?></td>
                            <td class="text-right"><?= number_format($r['bal_pcs']) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Baris Subtotal per Tanggal (Kecuali Closing Balance / INIT) -->
                    <?php if (count($rows) > 0 && $rows[0]['type'] !== 'INIT'): ?>
                        <tr class="subtotal-row">
                            <td class="text-center"><?= date('d-m-Y', strtotime($date)) ?></td>
                            <td colspan="9" class="text-center">TOTAL SALDO</td>
                            <td class="text-right"><?= number_format($last_row_bal_kg, 2) ?></td>
                            <td class="text-right"><?= number_format($last_row_bal_pcs) ?></td>
                        </tr>
                    <?php endif; ?>

                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="grand-total-row">
                    <td colspan="10" class="text-center">TOTAL SALDO AKHIR</td>
                    <td class="text-right"><?= number_format($item['final_kg'], 2) ?></td>
                    <td class="text-right"><?= number_format($item['final_pcs']) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endforeach; ?>
</div>

</body>
</html>