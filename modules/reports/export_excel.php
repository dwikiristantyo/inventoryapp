<?php
// modules/reports/export_excel.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

$filter_whid  = $_GET['filter_whid'] ?? '';
$filter_from  = $_GET['filter_from'] ?? date('Y-m-01');
$filter_to    = $_GET['filter_to'] ?? date('Y-m-d');
$filter_icodes = $_GET['filter_icodes'] ?? ['ALL'];

if (empty($filter_whid)) die("Gudang harus dipilih.");

if (!is_array($filter_icodes)) {
    $filter_icodes = [$filter_icodes];
}

function filename_header($filename) {
    header("Content-Disposition: attachment; filename=\"$filename.xls\"");
}

// Set Header Download Excel
filename_header("Laporan_Mutasi_Stok_{$filter_whid}_" . date('Ymd'));
header("Content-Type: application/vnd.ms-excel");

// Fetch Data Barang
if (in_array('ALL', $filter_icodes) || empty($filter_icodes)) {
    $stmtItems = $pdo->prepare("SELECT icode, desc1 FROM itemast WHERE stock = 'A' ORDER BY icode ASC");
    $stmtItems->execute();
    $master_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} else {
    $inQuery = implode(',', array_fill(0, count($filter_icodes), '?'));
    $stmtItems = $pdo->prepare("SELECT icode, desc1 FROM itemast WHERE stock = 'A' AND icode IN ($inQuery) ORDER BY icode ASC");
    $stmtItems->execute($filter_icodes);
    $master_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
}

$items_report = [];

foreach ($master_items as $item) {
    $icode = $item['icode'];

    // Saldo Awal
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

    // Mutasi
    $sqlTrans = "
        SELECT 
            t.trans_date, t.remark, t.type, t.qty AS kg, t.qty2 AS pcs
        FROM (
            SELECT m.othindate AS trans_date, m.whid, d.icode, m.remark, d.qty, d.qty2, 'IN' AS type 
            FROM othinmas m JOIN othindet d ON m.othinid = d.othinid WHERE m.status != 'X'
            UNION ALL
            SELECT m.othoutdate AS trans_date, m.whid, d.icode, m.remark, d.qty, d.qty2, 'OUT' AS type 
            FROM othoutmas m JOIN othoutdet d ON m.othoutid = d.othoutid WHERE m.status != 'X'
            UNION ALL
            SELECT m.adjdate AS trans_date, m.whid, d.icode, m.remark, d.qty, d.qty2, 'ADJ' AS type 
            FROM adjmas m JOIN adjdet d ON m.adjid = d.adjid WHERE m.status != 'X'
        ) t
        WHERE t.whid = :whid AND t.icode = :icode AND t.trans_date BETWEEN :from_date AND :to_date
        ORDER BY t.trans_date ASC
    ";
    $stmtTrans = $pdo->prepare($sqlTrans);
    $stmtTrans->execute([
        ':whid'      => $filter_whid,
        ':icode'     => $icode,
        ':from_date' => $filter_from,
        ':to_date'   => $filter_to
    ]);
    $mutations = $stmtTrans->fetchAll(PDO::FETCH_ASSOC);

    $ledger_rows = [];
    $first_date = date('Y-m-d', strtotime($filter_from . ' -1 day'));
    $ledger_rows[] = [
        'date'      => $first_date,
        'remark'    => 'Closing Balance',
        'in_kg'     => 0, 'in_pcs'  => 0,
        'out_kg'    => 0, 'out_pcs' => 0,
        'adj_kg'    => 0, 'adj_pcs' => 0,
        'bal_kg'    => $running_kg,
        'bal_pcs'   => $running_pcs
    ];

    foreach ($mutations as $m) {
        $in_kg   = ($m['type'] === 'IN')  ? (float)$m['kg']  : 0;
        $in_pcs  = ($m['type'] === 'IN')  ? (float)$m['pcs'] : 0;
        $out_kg  = ($m['type'] === 'OUT') ? (float)$m['kg']  : 0;
        $out_pcs = ($m['type'] === 'OUT') ? (float)$m['pcs'] : 0;
        $adj_kg  = ($m['type'] === 'ADJ') ? (float)$m['kg']  : 0;
        $adj_pcs = ($m['type'] === 'ADJ') ? (float)$m['pcs'] : 0;

        $running_kg  += ($in_kg - $out_kg + $adj_kg);
        $running_pcs += ($in_pcs - $out_pcs + $adj_pcs);

        $ledger_rows[] = [
            'date'    => $m['trans_date'],
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
        'rows'      => $ledger_rows,
        'final_kg'  => $running_kg,
        'final_pcs' => $running_pcs
    ];
}
?>

<?php foreach ($items_report as $item): ?>
    <h3><?= htmlspecialchars($item['icode']) ?> - <?= htmlspecialchars($item['desc1']) ?> (UOM: KG & Butir)</h3>
    <table border="1">
        <thead>
            <tr>
                <th rowspan="2">Tanggal</th>
                <th rowspan="2">Keterangan</th>
                <th colspan="2">Saldo Awal</th>
                <th colspan="2">Masuk (IN)</th>
                <th colspan="2">Keluar (OUT)</th>
                <th colspan="2">Adjustment</th>
                <th colspan="2">Saldo Akhir</th>
            </tr>
            <tr>
                <th>KG</th>
                <th>Butir</th>
                <th>KG</th>
                <th>Butir</th>
                <th>KG</th>
                <th>Butir</th>
                <th>KG</th>
                <th>Butir</th>
                <th>KG</th>
                <th>Butir</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($item['rows'] as $r): ?>
                <tr>
                    <td align="center"><?= date('d-m-Y', strtotime($r['date'])) ?></td>
                    <td><?= htmlspecialchars($r['remark']) ?></td>
                    <td align="right"><?= number_format($r['bal_kg'] - ($r['in_kg'] - $r['out_kg'] + $r['adj_kg']), 2) ?></td>
                    <td align="right"><?= number_format($r['bal_pcs'] - ($r['in_pcs'] - $r['out_pcs'] + $r['adj_pcs'])) ?></td>
                    <td align="right"><?= number_format($r['in_kg'], 2) ?></td>
                    <td align="right"><?= number_format($r['in_pcs']) ?></td>
                    <td align="right"><?= number_format($r['out_kg'], 2) ?></td>
                    <td align="right"><?= number_format($r['out_pcs']) ?></td>
                    <td align="right"><?= number_format($r['adj_kg'], 2) ?></td>
                    <td align="right"><?= number_format($r['adj_pcs']) ?></td>
                    <td align="right"><?= number_format($r['bal_kg'], 2) ?></td>
                    <td align="right"><?= number_format($r['bal_pcs']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="10" align="center">TOTAL SALDO AKHIR</th>
                <th align="right"><b><?= number_format($item['final_kg'], 2) ?></b></th>
                <th align="right"><b><?= number_format($item['final_pcs']) ?></b></th>
            </tr>
        </tfoot>
    </table>
    <br><br>
<?php endforeach; ?>