<?php
// index.php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';

// HELPER FUNCTION: Mengambil total transaksi harian berdasarkan tipe (IN/OUT) dan periode minggu
function getWeeklyTransactionData($pdo, $trtype, $week_offset = 0) {
    // $week_offset = 0 (minggu ini), -1 (minggu lalu)
    $daily_totals = [0, 0, 0, 0, 0, 0, 0]; // Index 0=Senin, 6=Minggu

    // Query agregasi berdasarkan DAYOFWEEK (MySQL: 1=Minggu, 2=Senin, dst)
    $sql = "
        SELECT 
            WEEKDAY(trans_date) as day_index, 
            SUM(qty) as total_qty 
        FROM transactions 
        WHERE trtype = :trtype 
          AND YEARWEEK(trans_date, 1) = YEARWEEK(DATE_ADD(CURDATE(), INTERVAL :offset WEEK), 1)
        GROUP BY WEEKDAY(trans_date)
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':trtype' => $trtype,
            ':offset' => $week_offset
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $idx = (int)$row['day_index'];
            if ($idx >= 0 && $idx <= 6) {
                $daily_totals[$idx] = (float)$row['total_qty'];
            }
        }
    } catch (PDOException $e) {
        // Jika tabel/kolom disesuaikan, query bisa disesuaikan
    }

    return $daily_totals;
}

// Fetch Data Transaksi Masuk (IN / RECEIVING)
$in_this_week = getWeeklyTransactionData($pdo, 'IN', 0);
$in_last_week = getWeeklyTransactionData($pdo, 'IN', -1);

// Fetch Data Transaksi Keluar (OUT / ISSUING)
$out_this_week = getWeeklyTransactionData($pdo, 'OUT', 0);
$out_last_week = getWeeklyTransactionData($pdo, 'OUT', -1);

render_header("Dashboard - Inventory System");
?>

<!-- Load Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<h2 style="margin-top: 0;">Dashboard Transaksi</h2>
<p style="color: #6c757d;">Perbandingan tren transaksi minggu ini dengan minggu lalu.</p>

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
const daysLabel = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

// 1. CONFIG CHART TRANSAKSI MASUK (IN)
const ctxIn = document.getElementById('chartIn').getContext('2d');
new Chart(ctxIn, {
    type: 'line',
    data: {
        labels: daysLabel,
        datasets: [
            {
                label: 'Minggu Ini',
                data: <?= json_encode($in_this_week) ?>,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2
            },
            {
                label: 'Minggu Lalu',
                data: <?= json_encode($in_last_week) ?>,
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
                label: 'Minggu Ini',
                data: <?= json_encode($out_this_week) ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2
            },
            {
                label: 'Minggu Lalu',
                data: <?= json_encode($out_last_week) ?>,
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