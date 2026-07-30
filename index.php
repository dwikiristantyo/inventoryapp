<?php
// index.php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';

// Fetch ringkasan statistik sederhana
$total_items    = $pdo->query("SELECT COUNT(*) FROM itemast WHERE stock = 'A'")->fetchColumn();
$total_warehouses = $pdo->query("SELECT COUNT(*) FROM whmast WHERE status = 'A'")->fetchColumn();
$total_users    = $pdo->query("SELECT COUNT(*) FROM sysuser")->fetchColumn();

render_header("Dashboard - Inventory System");
?>

<h2 style="margin-top: 0;">Dashboard Utama</h2>
<p>Selamat datang kembali, <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>!</p>

<div style="display: flex; gap: 20px; margin-top: 20px;">
    <div style="flex: 1; background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid #0d6efd;">
        <span style="font-size: 12px; color: #6c757d; font-weight: bold; text-transform: uppercase;">Total Active Items</span>
        <h2 style="margin: 5px 0 0; font-size: 28px; color: #0d6efd;"><?= number_format($total_items) ?></h2>
    </div>
    
    <div style="flex: 1; background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid #198754;">
        <span style="font-size: 12px; color: #6c757d; font-weight: bold; text-transform: uppercase;">Active Warehouses</span>
        <h2 style="margin: 5px 0 0; font-size: 28px; color: #198754;"><?= number_format($total_warehouses) ?></h2>
    </div>

    <div style="flex: 1; background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-left: 4px solid #ffc107;">
        <span style="font-size: 12px; color: #6c757d; font-weight: bold; text-transform: uppercase;">System Users</span>
        <h2 style="margin: 5px 0 0; font-size: 28px; color: #d39e00;"><?= number_format($total_users) ?></h2>
    </div>
</div>

<?php
render_footer();