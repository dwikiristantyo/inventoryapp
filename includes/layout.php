<?php
// includes/layout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek autentikasi
if (!isset($_SESSION['user_id'])) {
    // Tentukan path login berdasarkan lokasi script
    $depth = substr_count($_SERVER['SCRIPT_NAME'], '/') - 1;
    $prefix = str_repeat('../', max(0, $depth - 1));
    header("Location: " . $prefix . "modules/auth/login.php");
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$group_id  = $_SESSION['group_id'];

// Ambil data hak akses menu user dari sysgroupdet
require_once __DIR__ . '/../config/database.php';
$stmtPerm = $pdo->prepare("SELECT menu_id FROM sysgroupdet WHERE group_id = :gid");
$stmtPerm->execute([':gid' => $group_id]);
$allowed_menus = $stmtPerm->fetchAll(PDO::FETCH_COLUMN);

// Definisi Struktur Menu Aplikasi (sesuai folder modules kamu)
$menu_structure = [
    'Dashboard' => [
        'icon' => '🏠',
        'link' => 'index.php',
        'key'  => 'dashboard' // Selalu bisa diakses
    ],
    'Master Data' => [
        'icon' => '📦',
        'items' => [
            'm_invwhmast' => ['label' => 'Master Warehouse', 'link' => 'modules/warehouse/index.php'],
            'm_item'      => ['label' => 'Master Item',      'link' => 'modules/itemast/index.php'],
            'm_invmaster' => ['label' => 'Master Category',  'link' => 'modules/category/index.php'],
        ]
    ],
    'Transaksi' => [
        'icon' => '🔄',
        'items' => [
            'm_invtransaction' => ['label' => 'Transactions',  'link' => 'modules/transactions/index.php'],
            'm_lockperiod'     => ['label' => 'Lock Period',   'link' => 'modules/lock_period/index.php'],
        ]
    ],
    'Laporan' => [
        'icon' => '📊',
        'items' => [
            'm_report'              => ['label' => 'Reports',              'link' => 'modules/reports/index.php'],
            'm_report_daily_matrix' => ['label' => 'Reports Daily Matrix', 'link' => 'modules/reports/daily_matrix.php'],
        ]
    ],
    'System Admin' => [
        'icon' => '⚙️',
        'items' => [
            'm_admuser'  => ['label' => 'Master User',       'link' => 'modules/user/index.php'],
            'm_admgroup' => ['label' => 'Master User Group', 'link' => 'modules/user_group/index.php'],
        ]
    ]
];

// Helper Function Hitung Root Relative Path
function get_base_url() {
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    if (strpos($script_dir, '/modules') !== false) {
        return '../../';
    }
    return './';
}
$base_url = get_base_url();

function render_header($title = "Inventory System") {
    global $menu_structure, $allowed_menus, $user_id, $user_name, $group_id, $base_url;
    
    // Mendapatkan nama file script saat ini untuk penanda menu aktif (.active)
    $current_script = basename($_SERVER['SCRIPT_NAME']);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 0; background: #f4f6f9; color: #333; display: flex; min-height: 100vh; }
            
            /* SIDEBAR STYLES */
            .sidebar { width: 240px; background: #212529; color: #c2c7d0; flex-shrink: 0; display: flex; flex-direction: column; }
            .sidebar-brand { padding: 18px 15px; font-size: 16px; font-weight: bold; color: #fff; background: #1a1e21; border-bottom: 1px solid #2c3237; }
            .sidebar-user { padding: 12px 15px; border-bottom: 1px solid #2c3237; font-size: 12px; }
            .sidebar-user .name { color: #fff; font-weight: bold; font-size: 13px; }
            .sidebar-menu { list-style: none; padding: 10px 0; margin: 0; flex-grow: 1; overflow-y: auto; }
            .menu-header { font-size: 11px; text-transform: uppercase; color: #6c757d; padding: 10px 15px 4px; font-weight: bold; }
            .sidebar-menu a { display: block; padding: 8px 15px; color: #c2c7d0; text-decoration: none; font-size: 13px; transition: background 0.2s, color 0.2s; }
            .sidebar-menu a:hover { background: #343a40; color: #fff; }
            .sidebar-menu a.active { background: #0d6efd; color: #fff; font-weight: bold; }
            
            /* MAIN CONTENT STYLES */
            .wrapper { flex-grow: 1; display: flex; flex-direction: column; min-width: 0; }
            .top-nav { height: 50px; background: #fff; border-bottom: 1px solid #dee2e6; display: flex; align-items: center; justify-content: flex-end; padding: 0 20px; }
            .btn-logout { font-size: 12px; color: #dc3545; text-decoration: none; font-weight: bold; border: 1px solid #dc3545; padding: 4px 10px; border-radius: 4px; transition: all 0.2s; }
            .btn-logout:hover { background: #dc3545; color: #fff; }
            .content-container { padding: 20px; flex-grow: 1; }
        </style>
    </head>
    <body>

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="sidebar-brand">EGG INVENTORY</div>
        <div class="sidebar-user">
            Logged in as: <br>
            <span class="name"><?= htmlspecialchars($user_name) ?></span> 
            <small>(<?= htmlspecialchars($group_id) ?>)</small>
        </div>
        
        <ul class="sidebar-menu">
            <li>
                <a href="<?= $base_url ?>index.php" class="<?= ($current_script === 'index.php' && strpos($_SERVER['SCRIPT_NAME'], '/modules/') === false) ? 'active' : '' ?>">
                    🏠 Dashboard
                </a>
            </li>

            <?php foreach ($menu_structure as $section_title => $section): ?>
                <?php if (isset($section['items'])): ?>
                    <?php 
                    // Filter item berdasarkan permission group user
                    $visible_items = array_filter($section['items'], function($key) use ($allowed_menus, $group_id) {
                        return $group_id === 'admin' || in_array($key, $allowed_menus);
                    }, ARRAY_FILTER_USE_KEY);
                    ?>

                    <?php if (!empty($visible_items)): ?>
                        <li class="menu-header"><?= $section['icon'] ?> <?= $section_title ?></li>
                        <?php foreach ($visible_items as $key => $item): ?>
                            <?php 
                            $item_file = basename($item['link']);
                            $is_active = ($current_script === $item_file && strpos($_SERVER['SCRIPT_NAME'], dirname($item['link'])) !== false);
                            ?>
                            <li>
                                <a href="<?= $base_url . $item['link'] ?>" class="<?= $is_active ? 'active' : '' ?>">
                                    <?= htmlspecialchars($item['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- MAIN WRAPPER -->
    <div class="wrapper">
        <div class="top-nav">
            <a href="<?= $base_url ?>modules/auth/logout.php" class="btn-logout">Logout 🚪</a>
        </div>
        <div class="content-container">
    <?php
}

function render_footer() {
    ?>
        </div>
    </div>
    </body>
    </html>
    <?php
}