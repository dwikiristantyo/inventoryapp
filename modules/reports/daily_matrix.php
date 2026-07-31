<?php
// modules/reports/daily_matrix.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../includes/layout.php';

$current_user = 'admin';
$warehouses   = getUserWarehouses($pdo, $current_user);

$filter_from = $_GET['filter_from'] ?? date('Y-m-01');
$filter_to   = $_GET['filter_to'] ?? date('Y-m-d');
$filter_whid = $_GET['filter_whid'] ?? ($warehouses[0]['whid'] ?? '');

$salable_icodes = ['EG0001', 'EGG-SALABLE', 'EGG-GRADE-A', 'EGG-GRADE-B']; 
$cull_icodes    = ['EG0002', 'EGG-CULL', 'EGG-AFKIR', 'EGG-CRACK'];

$excelParams = http_build_query([
    'filter_whid' => $filter_whid,
    'filter_from' => $filter_from,
    'filter_to'   => $filter_to,
]);

$daily_data = [];

if (!empty($filter_whid)) {
    // 1. Hitung Saldo Awal Kumulatif (sebelum filter_from)
    $inSalableQuery = implode(',', array_fill(0, count($salable_icodes), '?'));
    $inCullQuery    = implode(',', array_fill(0, count($cull_icodes), '?'));

    $sqlInit = "
        SELECT 
            COALESCE(SUM(CASE WHEN icode IN ($inSalableQuery) AND type = 'IN' THEN qty ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN icode IN ($inSalableQuery) AND type = 'OUT' THEN qty ELSE 0 END), 0) +
            COALESCE(SUM(CASE WHEN icode IN ($inSalableQuery) AND type = 'ADJ' THEN qty ELSE 0 END), 0) AS init_salable_kg,

            COALESCE(SUM(CASE WHEN icode IN ($inSalableQuery) AND type = 'IN' THEN qty2 ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN icode IN ($inSalableQuery) AND type = 'OUT' THEN qty2 ELSE 0 END), 0) +
            COALESCE(SUM(CASE WHEN icode IN ($inSalableQuery) AND type = 'ADJ' THEN qty2 ELSE 0 END), 0) AS init_salable_pcs,

            COALESCE(SUM(CASE WHEN icode IN ($inCullQuery) AND type = 'IN' THEN qty ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN icode IN ($inCullQuery) AND type = 'OUT' THEN qty ELSE 0 END), 0) +
            COALESCE(SUM(CASE WHEN icode IN ($inCullQuery) AND type = 'ADJ' THEN qty ELSE 0 END), 0) AS init_cull_kg,

            COALESCE(SUM(CASE WHEN icode IN ($inCullQuery) AND type = 'IN' THEN qty2 ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN icode IN ($inCullQuery) AND type = 'OUT' THEN qty2 ELSE 0 END), 0) +
            COALESCE(SUM(CASE WHEN icode IN ($inCullQuery) AND type = 'ADJ' THEN qty2 ELSE 0 END), 0) AS init_cull_pcs
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
        WHERE t.whid = ? AND t.trans_date < ?
    ";

    $paramsInit = array_merge(
        $salable_icodes, $salable_icodes, $salable_icodes,
        $salable_icodes, $salable_icodes, $salable_icodes,
        $cull_icodes, $cull_icodes, $cull_icodes,
        $cull_icodes, $cull_icodes, $cull_icodes,
        [$filter_whid, $filter_from]
    );

    $stmtInit = $pdo->prepare($sqlInit);
    $stmtInit->execute($paramsInit);
    $initBal = $stmtInit->fetch(PDO::FETCH_ASSOC);

    $running_salable_kg  = (float)($initBal['init_salable_kg'] ?? 0);
    $running_salable_pcs = (float)($initBal['init_salable_pcs'] ?? 0);
    $running_cull_kg     = (float)($initBal['init_cull_kg'] ?? 0);
    $running_cull_pcs    = (float)($initBal['init_cull_pcs'] ?? 0);

    $period = new DatePeriod(
        new DateTime($filter_from),
        new DateInterval('P1D'),
        (new DateTime($filter_to))->modify('+1 day')
    );

    $daysIndo = ['Sun' => 'Minggu', 'Mon' => 'Senin', 'Tue' => 'Selasa', 'Wed' => 'Rabu', 'Thu' => 'Kamis', 'Fri' => 'Jumat', 'Sat' => 'Sabtu'];

    foreach ($period as $dt) {
        $dateStr = $dt->format('Y-m-d');
        $dayName = $daysIndo[$dt->format('D')];
        $formattedDate = $dt->format('d-M');

        $sqlDay = "
            SELECT 
                d.icode, t.remark, d.qty, d.qty2, t.type
            FROM (
                SELECT m.othinid AS id, m.othindate AS trans_date, m.whid, COALESCE(m.remark, '') AS remark, 'IN' AS type FROM othinmas m WHERE m.status != 'X'
                UNION ALL
                SELECT m.othoutid AS id, m.othoutdate AS trans_date, m.whid, COALESCE(m.remark, '') AS remark, 'OUT' AS type FROM othoutmas m WHERE m.status != 'X'
                UNION ALL
                SELECT m.adjid AS id, m.adjdate AS trans_date, m.whid, COALESCE(m.remark, '') AS remark, 'ADJ' AS type FROM adjmas m WHERE m.status != 'X'
            ) t
            JOIN (
                SELECT othinid AS id, icode, qty, qty2, 'IN' AS type FROM othindet
                UNION ALL
                SELECT othoutid AS id, icode, qty, qty2, 'OUT' AS type FROM othoutdet
                UNION ALL
                SELECT adjid AS id, icode, qty, qty2, 'ADJ' AS type FROM adjdet
            ) d ON t.id = d.id AND t.type = d.type
            WHERE t.whid = :whid AND t.trans_date = :trans_date
        ";
        $stmtDay = $pdo->prepare($sqlDay);
        $stmtDay->execute([':whid' => $filter_whid, ':trans_date' => $dateStr]);
        $dayTrans = $stmtDay->fetchAll(PDO::FETCH_ASSOC);

        // Reset Nilai Harian
        $prod_salable_kg = 0; $prod_salable_pcs = 0;
        $prod_cull_kg_1  = 0; $prod_cull_pcs_1  = 0;
        $prod_cull_kg_2  = 0; $prod_cull_pcs_2  = 0;

        $susut_kg = 0; $susut_pcs = 0;
        $buang_kg = 0; $buang_pcs = 0;

        $jual_salable_kg = 0; $jual_salable_pcs = 0;
        $jual_cull_kg    = 0; $jual_cull_pcs    = 0;

        $adj_salable_kg  = 0; $adj_salable_pcs  = 0;
        $adj_cull_kg     = 0; $adj_cull_pcs     = 0;

        foreach ($dayTrans as $tr) {
            $is_salable  = in_array($tr['icode'], $salable_icodes);
            $is_cull     = in_array($tr['icode'], $cull_icodes);
            $remarkUpper = strtoupper($tr['remark'] ?? '');

            if ($tr['type'] === 'IN') {
                if ($is_salable) {
                    $prod_salable_kg += $tr['qty'];
                    $prod_salable_pcs += $tr['qty2'];
                } elseif ($is_cull) {
                    if (strpos($remarkUpper, 'HANCUR') !== false) {
                        $prod_cull_kg_2 += $tr['qty'];
                        $prod_cull_pcs_2 += $tr['qty2'];
                    } else {
                        $prod_cull_kg_1 += $tr['qty'];
                        $prod_cull_pcs_1 += $tr['qty2'];
                    }
                }
            } elseif ($tr['type'] === 'OUT') {
                if (strpos($remarkUpper, 'SUSUT') !== false) {
                    $susut_kg += $tr['qty'];
                    $susut_pcs += $tr['qty2'];
                } elseif (strpos($remarkUpper, 'BUANG') !== false) {
                    $buang_kg += $tr['qty'];
                    $buang_pcs += $tr['qty2'];
                } else {
                    if ($is_salable) {
                        $jual_salable_kg += $tr['qty'];
                        $jual_salable_pcs += $tr['qty2'];
                    } else {
                        $jual_cull_kg += $tr['qty'];
                        $jual_cull_pcs += $tr['qty2'];
                    }
                }
            } elseif ($tr['type'] === 'ADJ') {
                if ($is_salable) {
                    $adj_salable_kg += $tr['qty'];
                    $adj_salable_pcs += $tr['qty2'];
                } elseif ($is_cull) {
                    $adj_cull_kg += $tr['qty'];
                    $adj_cull_pcs += $tr['qty2'];
                }
            }
        }

        // Saldo Awal Hari Ini (1..4)
        $c1 = $running_salable_kg;
        $c2 = $running_salable_pcs;
        $c3 = $running_cull_kg;
        $c4 = $running_cull_pcs;

        // Produksi Kandang (5..10)
        $c5 = $prod_salable_kg;  $c6  = $prod_salable_pcs;
        $c7 = $prod_cull_kg_1;   $c8  = $prod_cull_pcs_1;
        $c9 = $prod_cull_kg_2;   $c10 = $prod_cull_pcs_2;

        // Total Stok (11..14)
        $c11 = $c1 + $c5;
        $c12 = $c2 + $c6;
        $c13 = $c3 + $c7 + $c9;
        $c14 = $c4 + $c8 + $c10;

        // Susut (15..16) & Buang (17..18)
        $c15 = $susut_kg;  $c16 = $susut_pcs;
        $c17 = $buang_kg;  $c18 = $buang_pcs;

        // Penjualan (19..22)
        $c19 = $jual_salable_kg; $c20 = $jual_salable_pcs;
        $c21 = $jual_cull_kg;    $c22 = $jual_cull_pcs;

        // Adjustment (27..30)
        $c27 = $adj_salable_kg;  $c28 = $adj_salable_pcs;
        $c29 = $adj_cull_kg;     $c30 = $adj_cull_pcs;

        // Saldo Akhir (23..26)
        $c23 = $c11 - $c15 - $c19 + $c27;
        $c24 = $c12 - $c16 - $c20 + $c28;
        $c25 = $c13 - $c17 - $c21 + $c29;
        $c26 = $c14 - $c18 - $c22 + $c30;

        // Carry forward saldo akhir ke saldo awal besok
        $running_salable_kg  = $c23;
        $running_salable_pcs = $c24;
        $running_cull_kg     = $c25;
        $running_cull_pcs    = $c26;

        $daily_data[] = compact(
            'dayName', 'formattedDate',
            'c1','c2','c3','c4','c5','c6','c7','c8','c9','c10',
            'c11','c12','c13','c14','c15','c16','c17','c18','c19','c20',
            'c21','c22','c23','c24','c25','c26','c27','c28','c29','c30'
        );
    }
}

render_header("Laporan Mutasi Telur Harian");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Mutasi Telur Matrix</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 15px; background: #f4f6f9; color: #000; }
        .card { background: #fff; padding: 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.2); margin-bottom: 15px; }
        .row { display: flex; gap: 10px; align-items: flex-end; }
        .col { flex: 1; }
        label { font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px; }
        input, select { width: 100%; padding: 6px; font-size: 13px; border: 1px solid #ccc; border-radius: 3px; }
        
        .btn { padding: 6px 12px; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; font-size: 12px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #0288d1; color: #fff; }
        .btn-success { background: #2e7d32; color: #fff; }

        .matrix-container { overflow-x: auto; }
        table.matrix-table { width: 100%; border-collapse: collapse; font-size: 11px; white-space: nowrap; }
        table.matrix-table th, table.matrix-table td { border: 1px solid #000; padding: 4px 5px; text-align: right; }
        table.matrix-table th { text-align: center; font-weight: bold; }

        .bg-header-main { background-color: #B4C6E7; } 
        .bg-salable { background-color: #A9D08E; }     
        .bg-cull { background-color: #FFF2CC; }        
        .bg-yellow-num { background-color: #FFFF00; font-weight: bold; } 

        .text-center { text-align: center !important; }
        .text-bold { font-weight: bold; }
    </style>
</head>
<body>

<div class="card no-print">
    <form method="GET">
        <div class="row">
            <div class="col">
                <label>Gudang/Farm</label>
                <select name="filter_whid" required>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= htmlspecialchars($wh['whid']) ?>" <?= $filter_whid === $wh['whid'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($wh['whname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col">
                <label>Periode Tanggal</label>
                <div style="display:flex; gap:5px;">
                    <input type="date" name="filter_from" value="<?= $filter_from ?>">
                    <input type="date" name="filter_to" value="<?= $filter_to ?>">
                </div>
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Tampilkan</button>
                <a href="export_daily_matrix_excel.php?<?= $excelParams ?>" class="btn btn-success">Export Excel</a>
            </div>
        </div>
    </form>
</div>

<div class="card matrix-container">
    <table class="matrix-table">
        <thead>
            <tr class="bg-header-main">
                <th rowspan="3" width="70">Hari / Tgl</th>
                <th colspan="4">Saldo Awal</th>
                <th colspan="6">Produksi Kandang</th>
                <th colspan="4">Total Stok</th>
                <th colspan="2">Susut</th>
                <th colspan="2">Buang</th>
                <th colspan="4">Penjualan</th>
                <th colspan="4">Saldo Akhir</th>
                <th colspan="4">Adjustment</th>
            </tr>
            <tr>
                <th colspan="2" class="bg-salable">SALABLE EGGS</th>
                <th colspan="2" class="bg-cull">CULL EGGS</th>
                <th colspan="2" class="bg-salable">SALABLE EGGS</th>
                <th colspan="4" class="bg-cull">CULL EGGS</th>
                <th colspan="2" class="bg-salable">SALABLE EGGS</th>
                <th colspan="2" class="bg-cull">CULL EGGS</th>
                <th colspan="2" class="bg-salable">SALABLE EGGS</th>
                <th colspan="2" class="bg-cull">CULL EGGS</th>
                <th colspan="2" class="bg-salable">SALABLE EGGS</th>
                <th colspan="2" class="bg-cull">CULL EGGS</th>
                <th colspan="2" class="bg-salable">SALABLE EGGS</th>
                <th colspan="2" class="bg-cull">CULL EGGS</th>
                <th colspan="2" class="bg-salable">SALABLE EGGS</th>
                <th colspan="2" class="bg-cull">CULL EGGS</th>
            </tr>
            <tr class="bg-header-main">
                <th>Kg</th><th>Butir</th><th>Kg</th><th>Butir</th>
                <th>Kg</th><th>Butir</th><th>Kg</th><th>Butir</th><th>Kg</th><th>Butir</th>
                <th>Kg</th><th>Butir</th><th>Kg</th><th>Butir</th>
                <th>Kg</th><th>Butir</th>
                <th>Kg</th><th>Butir</th>
                <th>Kg</th><th>Butir</th><th>Kg</th><th>Butir</th>
                <th>Kg</th><th>Butir</th><th>Kg</th><th>Butir</th>
                <th>Kg</th><th>Butir</th><th>Kg</th><th>Butir</th>
            </tr>
            <tr class="bg-yellow-num text-center">
                <td>-</td>
                <td>1</td><td>2</td><td>3</td><td>4</td>
                <td>5</td><td>6</td><td>7</td><td>8</td><td>9</td><td>10</td>
                <td>11<br><small>1+5</small></td>
                <td>12<br><small>2+6</small></td>
                <td>13<br><small>3+7+9</small></td>
                <td>14<br><small>4+8+10</small></td>
                <td>15</td><td>16</td>
                <td>17</td><td>18</td>
                <td>19</td><td>20</td><td>21</td><td>22</td>
                <td>23<br><small>11-15-19</small></td>
                <td>24<br><small>12-16-20</small></td>
                <td>25<br><small>13-17-21</small></td>
                <td>26<br><small>14-18-22</small></td>
                <td>27</td><td>28</td><td>29</td><td>30</td>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($daily_data as $row): ?>
                <tr>
                    <td class="text-center text-bold"><?= $row['dayName'] ?><br><?= $row['formattedDate'] ?></td>
                    <td><?= number_format($row['c1'], 2) ?></td><td><?= number_format($row['c2']) ?></td>
                    <td><?= number_format($row['c3'], 2) ?></td><td><?= number_format($row['c4']) ?></td>
                    <td><?= number_format($row['c5'], 2) ?></td><td><?= number_format($row['c6']) ?></td>
                    <td><?= number_format($row['c7'], 2) ?></td><td><?= number_format($row['c8']) ?></td>
                    <td><?= number_format($row['c9'], 2) ?></td><td><?= number_format($row['c10']) ?></td>
                    <td class="text-bold"><?= number_format($row['c11'], 2) ?></td><td class="text-bold"><?= number_format($row['c12']) ?></td>
                    <td class="text-bold"><?= number_format($row['c13'], 2) ?></td><td class="text-bold"><?= number_format($row['c14']) ?></td>
                    <td><?= number_format($row['c15'], 2) ?></td><td><?= number_format($row['c16']) ?></td>
                    <td><?= number_format($row['c17'], 2) ?></td><td><?= number_format($row['c18']) ?></td>
                    <td><?= number_format($row['c19'], 2) ?></td><td><?= number_format($row['c20']) ?></td>
                    <td><?= number_format($row['c21'], 2) ?></td><td><?= number_format($row['c22']) ?></td>
                    <td class="text-bold"><?= number_format($row['c23'], 2) ?></td><td class="text-bold"><?= number_format($row['c24']) ?></td>
                    <td class="text-bold"><?= number_format($row['c25'], 2) ?></td><td class="text-bold"><?= number_format($row['c26']) ?></td>
                    <td><?= number_format($row['c27'], 2) ?></td><td><?= number_format($row['c28']) ?></td>
                    <td><?= number_format($row['c29'], 2) ?></td><td><?= number_format($row['c30']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
<?php render_footer(); ?>