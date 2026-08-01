<?php
// index.php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';

/**
 * HELPER: Mencari tanggal Sabtu paling akhir yang memiliki transaksi di database.
 * Digunakan sebagai acuan awal pekan terbaru (Sabtu - Jumat).
 */
function getLatestSaturdayAnchor($pdo) {
    try {
        // Gabungkan tanggal transaksi IN dan OUT terbaru
        $sql = "
            SELECT MAX(trans_date) as max_date FROM (
                SELECT othindate AS trans_date FROM othinmas WHERE status != 'X'
                UNION ALL
                SELECT othoutdate AS trans_date FROM othoutmas WHERE status != 'X'
            ) t
        ";
        $stmt = $pdo->query($sql);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['max_date']) {
            $latest_date = new DateTime($row['max_date']);
        } else {
            $latest_date = new DateTime();
        }
    } catch (Exception $e) {
        $latest_date = new DateTime();
    }

    // Jika tanggal terbaru bukan Sabtu, mundurkan ke hari Sabtu terdekat
    if ($latest_date->format('w') != 6) { // 6 = Sabtu
        $latest_date->modify('last saturday');
    }

    return $latest_date;
}

/**
 * HELPER: Mengambil total kuantitas transaksi harian (Sabtu - Jumat)
 * @param PDO $pdo
 * @param string $trtype 'IN' atau 'OUT'
 * @param DateTime $startDate Tanggal awal pekan (Sabtu)
 * @param string $qtyField 'qty' untuk Kg, 'qty2' untuk Butir
 */
function getCustomWeeklyData($pdo, $trtype, DateTime $startDate, $qtyField = 'qty') {
    // Array penampung 7 hari: [0=>Sabtu, 1=>Minggu, ..., 6=>Jumat]
    $daily_totals = array_fill(0, 7, 0);

    $startStr = $startDate->format('Y-m-d');
    $endDate  = (clone $startDate)->modify('+6 days');
    $endStr    = $endDate->format('Y-m-d');

    // Tentukan query berdasarkan tipe transaksi (IN dari othin, OUT dari othout)
    if ($trtype === 'IN') {
        $sql = "
            SELECT 
                m.othindate AS trans_date, 
                SUM(d.{$qtyField}) AS total_qty 
            FROM othinmas m
            JOIN othindet d ON m.othinid = d.othinid
            WHERE m.status != 'X' 
              AND m.othindate BETWEEN :start_date AND :end_date
            GROUP BY m.othindate
        ";
    } else {
        $sql = "
            SELECT 
                m.othoutdate AS trans_date, 
                SUM(d.{$qtyField}) AS total_qty 
            FROM othoutmas m
            JOIN othoutdet d ON m.othoutid = d.othoutid
            WHERE m.status != 'X' 
              AND m.othoutdate BETWEEN :start_date AND :end_date
            GROUP BY m.othoutdate
        ";
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':start_date' => $startStr,
            ':end_date'   => $endStr
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $tDate = new DateTime($row['trans_date']);
            // Hitung selisih hari dari $startDate (Sabtu = index 0)
            $diffDays = (int)$startDate->diff($tDate)->format('%r%a');
            if ($diffDays >= 0 && $diffDays <= 6) {
                $daily_totals[$diffDays] = (float)$row['total_qty'];
            }
        }
    } catch (PDOException $e) {
        // Fallback jika query bermasalah
    }

    return $daily_totals;
}

// 1. Tentukan Tanggal Acuan Sabtu
$latestSaturday = getLatestSaturdayAnchor($pdo);

// Pekan Terbaru (Pekan 1): Sabtu s/d Jumat
$p1_start = clone $latestSaturday;
$p1_end   = (clone $p1_start)->modify('+6 days');

// Pekan Sebelumnya (Pekan 2): Sabtu s/d Jumat (minus 1 minggu)
$p2_start = (clone $p1_start)->modify('-7 days');
$p2_end   = (clone $p2_start)->modify('+6 days');

// Label Legenda Berupa Tanggal Periode
$label_p1 = $p1_start->format('d M') . ' - ' . $p1_end->format('d M');
$label_p2 = $p2_start->format('d M') . ' - ' . $p2_end->format('d M');

// 2. Fetch Data Transaksi Masuk (IN) & Keluar (OUT)
// Catatan: Gunakan 'qty' untuk Kg. Jika ingin menampilkan satuan Butir, ganti parameter terakhir menjadi 'qty2'
$in_p1  = getCustomWeeklyData($pdo, 'IN', $p1_start, 'qty');
$in_p2  = getCustomWeeklyData($pdo, 'IN', $p2_start, 'qty');

$out_p1 = getCustomWeeklyData($pdo, 'OUT', $p1_start, 'qty');
$out_p2 = getCustomWeeklyData($pdo, 'OUT', $p2_start, 'qty');

render_header("Dashboard - Inventory System");
?>

<!-- Load Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<h2 style="margin-top: 0;">Dashboard Transaksi</h2>
<p style="color: #6c757d;">Perbandingan tren transaksi 2 pekan terakhir (Periode Sabtu - Jumat dalam Kg).</p>

<div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
    
    <!-- CHART 1: TRANSAKSI MASUK -->
    <div style="flex: 1; min-width: 450px; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1);">
        <h3 style="margin-top: 0; color: #0d6efd; font-size: 16px;">📥 Transaksi Masuk (IN)</h3>
        <canvas id="chartIn" height="220"></canvas>
    </div>

    <!-- CHART 2: TRANSAKSI KELUAR -->
    <div style="flex: 1; min-width: 450px; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1);">
        <h3 style="margin-top: 0; color: #dc3545; font-size: 16px;">📤 Transaksi Keluar (OUT)</h3>
        <canvas id="chartOut" height="220"></canvas>
    </div>

</div>

<script>
// Sumbu X diurutkan dari Sabtu sampai Jumat
const daysLabel = ['Sabtu', 'Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

// 1. CONFIG CHART TRANSAKSI MASUK (IN)
const ctxIn = document.getElementById('chartIn').getContext('2d');
new Chart(ctxIn, {
    type: 'line',
    data: {
        labels: daysLabel,
        datasets: [
            {
                label: <?= json_encode($label_p1) ?>, // Tanggal Pekan Terbaru
                data: <?= json_encode($in_p1) ?>,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2
            },
            {
                label: <?= json_encode($label_p2) ?>, // Tanggal Pekan Sebelumnya
                data: <?= json_encode($in_p2) ?>,
                borderColor: '#adb5bd',
                borderDash: [5, 5],
                fill: false,
                tension: 0.3,
                borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// 2. CONFIG CHART TRANSAKSI KELUAR (OUT)
const ctxOut = document.getElementById('chartOut').getContext('2d');
new Chart(ctxOut, {
    type: 'line',
    data: {
        labels: daysLabel,
        datasets: [
            {
                label: <?= json_encode($label_p1) ?>, // Tanggal Pekan Terbaru
                data: <?= json_encode($out_p1) ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2
            },
            {
                label: <?= json_encode($label_p2) ?>, // Tanggal Pekan Sebelumnya
                data: <?= json_encode($out_p2) ?>,
                borderColor: '#adb5bd',
                borderDash: [5, 5],
                fill: false,
                tension: 0.3,
                borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>

<?php
render_footer();