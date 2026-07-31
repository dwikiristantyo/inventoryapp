<?php
// modules/lock_period/index.php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../config/database.php';

$current_user = $_SESSION['user_id'] ?? 'ADMIN';
$message = '';
$alert_type = 'info';

// 1. CARI TRANSAKSI TERLAMA DENGAN STATUS 'A' DARI 3 TABEL TRANSAKSI
$sqlOldest = "
    SELECT MIN(trans_date) as oldest_date FROM (
        SELECT othindate AS trans_date FROM othinmas WHERE status = 'A'
        UNION ALL
        SELECT othoutdate AS trans_date FROM othoutmas WHERE status = 'A'
        UNION ALL
        SELECT adjdate AS trans_date FROM adjmas WHERE status = 'A'
    ) AS all_trans
";
$stmtOldest = $pdo->query($sqlOldest);
$rowOldest  = $stmtOldest->fetch(PDO::FETCH_ASSOC);

if (!empty($rowOldest['oldest_date'])) {
    $default_start = date('Y-m-01', strtotime($rowOldest['oldest_date']));
    $default_end   = date('Y-m-t', strtotime($rowOldest['oldest_date']));
} else {
    // Default jika tidak ada transaksi berstatus A
    $default_start = date('Y-m-01');
    $default_end   = date('Y-m-t');
}

// 2. HANDLE ACTIONS (LOCK & UNLOCK)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $whid       = trim($_POST['whid'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date   = $_POST['end_date'] ?? '';
    $reason     = trim($_POST['reason'] ?? '');

    if ($action === 'LOCK') {
        if (!empty($whid) && !empty($start_date) && !empty($end_date)) {
            try {
                $pdo->beginTransaction();

                // Cek apakah record lock periode ini sudah pernah ada
                $stmtCheck = $pdo->prepare("SELECT id FROM lock_periods WHERE whid = :whid AND start_date = :sdate AND end_date = :edate");
                $stmtCheck->execute([':whid' => $whid, ':sdate' => $start_date, ':edate' => $end_date]);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $stmtUpd = $pdo->prepare("
                        UPDATE lock_periods 
                        SET reason = :reason, created_by = :user, created_at = NOW() 
                        WHERE id = :id
                    ");
                    $stmtUpd->execute([':reason' => $reason, ':user' => $current_user, ':id' => $existing['id']]);
                } else {
                    $stmtIns = $pdo->prepare("
                        INSERT INTO lock_periods (whid, start_date, end_date, reason, created_by, created_at)
                        VALUES (:whid, :sdate, :edate, :reason, :user, NOW())
                    ");
                    $stmtIns->execute([
                        ':whid'       => $whid,
                        ':sdate'      => $start_date,
                        ':edate'      => $end_date,
                        ':reason'     => $reason,
                        ':user'       => $current_user
                    ]);
                }

                // UPDATE STATUS 'L' KE 3 TABEL TRANSAKSI
                $params = [':whid' => $whid, ':sdate' => $start_date, ':edate' => $end_date];

                // 1. othinmas
                $pdo->prepare("UPDATE othinmas SET status = 'L' WHERE whid = :whid AND othindate BETWEEN :sdate AND :edate AND status = 'A'")->execute($params);
                // 2. othoutmas
                $pdo->prepare("UPDATE othoutmas SET status = 'L' WHERE whid = :whid AND othoutdate BETWEEN :sdate AND :edate AND status = 'A'")->execute($params);
                // 3. adjmas
                $pdo->prepare("UPDATE adjmas SET status = 'L' WHERE whid = :whid AND adjdate BETWEEN :sdate AND :edate AND status = 'A'")->execute($params);

                $pdo->commit();
                $message = "Periode {$start_date} s/d {$end_date} berhasil di-LOCK!";
                $alert_type = 'success';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Gagal Lock Periode: " . $e->getMessage();
                $alert_type = 'danger';
            }
        }
    } elseif ($action === 'UNLOCK') {
        $id = $_POST['id'] ?? 0;

        $stmtTarget = $pdo->prepare("SELECT * FROM lock_periods WHERE id = :id");
        $stmtTarget->execute([':id' => $id]);
        $targetLock = $stmtTarget->fetch(PDO::FETCH_ASSOC);

        if ($targetLock) {
            // Cek apakah ada periode yang LEBIH BARU yang masih ter-Lock untuk Warehouse ini
            $stmtNewer = $pdo->prepare("
                SELECT COUNT(*) FROM lock_periods 
                WHERE whid = :whid AND start_date > :sdate
            ");
            $stmtNewer->execute([':whid' => $targetLock['whid'], ':sdate' => $targetLock['start_date']]);
            $newerCount = $stmtNewer->fetchColumn();

            if ($newerCount > 0) {
                $message = "Gagal Unlock! Anda harus membuka (Unlock) periode yang lebih baru terlebih dahulu secara berurutan.";
                $alert_type = 'danger';
            } else {
                try {
                    $pdo->beginTransaction();

                    $paramsBack = [
                        ':whid'  => $targetLock['whid'],
                        ':sdate' => $targetLock['start_date'],
                        ':edate' => $targetLock['end_date']
                    ];

                    // KEMBALIKAN STATUS 'L' MENJADI 'A' DI 3 TABEL
                    $pdo->prepare("UPDATE othinmas SET status = 'A' WHERE whid = :whid AND othindate BETWEEN :sdate AND :edate AND status = 'L'")->execute($paramsBack);
                    $pdo->prepare("UPDATE othoutmas SET status = 'A' WHERE whid = :whid AND othoutdate BETWEEN :sdate AND :edate AND status = 'L'")->execute($paramsBack);
                    $pdo->prepare("UPDATE adjmas SET status = 'A' WHERE whid = :whid AND adjdate BETWEEN :sdate AND :edate AND status = 'L'")->execute($paramsBack);

                    // Hapus Record Lock
                    $stmtDelLock = $pdo->prepare("DELETE FROM lock_periods WHERE id = :id");
                    $stmtDelLock->execute([':id' => $id]);

                    $pdo->commit();
                    $message = "Periode {$targetLock['start_date']} s/d {$targetLock['end_date']} berhasil di-UNLOCK!";
                    $alert_type = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Gagal Unlock Periode: " . $e->getMessage();
                    $alert_type = 'danger';
                }
            }
        }
    }
}

// FETCH DATA UNTUK UI
$warehouses  = $pdo->query("SELECT whid, whname FROM whmast WHERE status = 'A' ORDER BY whid ASC")->fetchAll(PDO::FETCH_ASSOC);
$lockedList  = $pdo->query("SELECT l.*, w.whname FROM lock_periods l LEFT JOIN whmast w ON l.whid = w.whid ORDER BY l.start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

render_header("Lock Period - Inventory System");
?>

<div class="card" style="background:#fff; padding:20px; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,0.1); margin-bottom:20px;">
    <h2>Form Lock Period</h2>
    
    <?php if ($message): ?>
        <div style="padding:10px; border-radius:4px; margin-bottom:15px; color:#fff; background-color: <?= $alert_type === 'success' ? '#2e7d32' : '#d32f2f' ?>;">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="LOCK">

        <div style="display:flex; gap:15px; margin-bottom:15px;">
            <div style="flex:1;">
                <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">Gudang / Warehouse</label>
                <select name="whid" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                    <option value="">-- Pilih Warehouse --</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= htmlspecialchars($wh['whid']) ?>">
                            [<?= htmlspecialchars($wh['whid']) ?>] <?= htmlspecialchars($wh['whname']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;">
                <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">Start Date</label>
                <input type="date" name="start_date" value="<?= $default_start ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            </div>
            <div style="flex:1;">
                <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">End Date</label>
                <input type="date" name="end_date" value="<?= $default_end ?>" required style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
            </div>
        </div>

        <div style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; font-size:12px; margin-bottom:5px;">Catatan / Alasan Lock (Reason)</label>
            <input type="text" name="reason" placeholder="Contoh: Closing bulanan Juli 2026" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
        </div>

        <div style="text-align:right;">
            <button type="submit" style="background:#0d6efd; color:#fff; border:none; padding:8px 16px; border-radius:4px; font-weight:bold; cursor:pointer;">
                🔒 Lock Periode
            </button>
        </div>
    </form>
</div>

<!-- DAFTAR PERIODE TER-LOCK -->
<div class="card" style="background:#fff; padding:20px; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,0.1);">
    <h3>Daftar Periode Terkunci (Locked Periods)</h3>
    <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:10px;">
        <thead>
            <tr style="background:#f1f3f5; text-align:left;">
                <th style="padding:8px; border:1px solid #dee2e6;">Warehouse</th>
                <th style="padding:8px; border:1px solid #dee2e6;">Start Date</th>
                <th style="padding:8px; border:1px solid #dee2e6;">End Date</th>
                <th style="padding:8px; border:1px solid #dee2e6;">Reason</th>
                <th style="padding:8px; border:1px solid #dee2e6;">Locked By</th>
                <th style="padding:8px; border:1px solid #dee2e6; text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($lockedList)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding:15px; color:#6c757d;">Belum ada periode yang di-lock.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($lockedList as $row): ?>
                <tr>
                    <td style="padding:8px; border:1px solid #dee2e6;">[<?= htmlspecialchars($row['whid']) ?>] <?= htmlspecialchars($row['whname']) ?></td>
                    <td style="padding:8px; border:1px solid #dee2e6;"><?= $row['start_date'] ?></td>
                    <td style="padding:8px; border:1px solid #dee2e6;"><?= $row['end_date'] ?></td>
                    <td style="padding:8px; border:1px solid #dee2e6;"><?= htmlspecialchars($row['reason']) ?></td>
                    <td style="padding:8px; border:1px solid #dee2e6;"><?= htmlspecialchars($row['created_by']) ?></td>
                    <td style="padding:8px; border:1px solid #dee2e6; text-align:center;">
                        <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin membuka (UNLOCK) periode ini?');" style="display:inline;">
                            <input type="hidden" name="action" value="UNLOCK">
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" style="background:#dc3545; color:#fff; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:11px;">
                                🔓 Unlock
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
render_footer();