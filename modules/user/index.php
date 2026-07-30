<?php
// modules/user/index.php
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/layout.php';

// PROTEKSI HANYA UNTUK ADMINISTRATOR
$logged_user_group = $_SESSION['group_id'] ?? 'admin';
if ($logged_user_group !== 'admin') {
    die("<h3 style='color:red;'>Akses Ditolak: Halaman ini hanya untuk Administrator!</h3>");
}

$message = '';
$edit_user = null;

// HANDLE FORM ACTIONS (CREATE / UPDATE / DELETE)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'CREATE') {
        $user_id       = strtolower(trim($_POST['user_id']));
        $user_name     = trim($_POST['user_name']);
        $user_password = $_POST['user_password'];
        $group_id      = $_POST['group_id'];

        if (!empty($user_id) && !empty($user_password)) {
            $stmt = $pdo->prepare("INSERT INTO sysuser (user_id, user_password, user_name, group_id) VALUES (:uid, :pwd, :uname, :gid)");
            try {
                $stmt->execute([
                    ':uid'   => $user_id,
                    ':pwd'   => $user_password,
                    ':uname' => $user_name,
                    ':gid'   => $group_id
                ]);
                $message = "User [{$user_id}] berhasil ditambahkan!";
            } catch (PDOException $e) {
                $message = "Gagal Simpan: User ID '{$user_id}' sudah terpakai!";
            }
        }
    } elseif ($action === 'UPDATE') {
        $user_id       = strtolower(trim($_POST['user_id']));
        $user_name     = trim($_POST['user_name']);
        $user_password = $_POST['user_password'];
        $group_id      = $_POST['group_id'];

        if (!empty($user_password)) {
            // Update password & data lainnya
            $stmt = $pdo->prepare("UPDATE sysuser SET user_name = :uname, user_password = :pwd, group_id = :gid WHERE user_id = :uid");
            $stmt->execute([':uname' => $user_name, ':pwd' => $user_password, ':gid' => $group_id, ':uid' => $user_id]);
        } else {
            // Update tanpa mengubah password jika dikosongkan
            $stmt = $pdo->prepare("UPDATE sysuser SET user_name = :uname, group_id = :gid WHERE user_id = :uid");
            $stmt->execute([':uname' => $user_name, ':gid' => $group_id, ':uid' => $user_id]);
        }
        $message = "Data user [{$user_id}] berhasil diperbarui!";
    } elseif ($action === 'DELETE') {
        $user_id = $_POST['user_id'];
        $stmt = $pdo->prepare("DELETE FROM sysuser WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
        $message = "User [{$user_id}] berhasil dihapus.";
    }
}

// EDIT MODE
if (isset($_GET['edit_id'])) {
    $stmtEdit = $pdo->prepare("SELECT * FROM sysuser WHERE user_id = :uid");
    $stmtEdit->execute([':uid' => $_GET['edit_id']]);
    $edit_user = $stmtEdit->fetch(PDO::FETCH_ASSOC);
}

// FETCH DATA
$groups = $pdo->query("SELECT group_id, group_desc FROM sysgrouphdr ORDER BY group_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$users  = $pdo->query("SELECT u.*, g.group_desc FROM sysuser u LEFT JOIN sysgrouphdr g ON u.group_id = g.group_id ORDER BY u.user_id ASC")->fetchAll(PDO::FETCH_ASSOC);

render_header("Master User");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Master User</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 20px; background: #f8f9fa; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 6px; max-width: 850px; margin: 0 auto 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .row { display: flex; gap: 15px; margin-bottom: 12px; align-items: flex-end; }
        .col { flex: 1; }
        label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; font-size: 13px; }
        input[readonly] { background-color: #e9ecef; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 13px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #0d6efd; color: #fff; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-secondary { background: #6c757d; color: #fff; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        th, td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; }
        th { background: #f1f3f5; }
        .text-center { text-align: center; }
    </style>
</head>
<body>

<div class="card">
    <h2>Master User (sysuser)</h2>
    <?php if ($message): ?>
        <div style="padding: 10px; background: #e2e3e5; border-radius: 4px; margin-bottom: 15px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="action" value="<?= $edit_user ? 'UPDATE' : 'CREATE' ?>">
        
        <div class="row">
            <div class="col">
                <label>User ID</label>
                <input type="text" name="user_id" placeholder="cth: mis" value="<?= htmlspecialchars($edit_user['user_id'] ?? '') ?>" <?= $edit_user ? 'readonly' : 'required' ?>>
            </div>
            <div class="col">
                <label>Nama User (user_name)</label>
                <input type="text" name="user_name" placeholder="cth: Super User" value="<?= htmlspecialchars($edit_user['user_name'] ?? '') ?>" required>
            </div>
        </div>

        <div class="row">
            <div class="col">
                <label>Password <?= $edit_user ? '<small style="color:red;">(Kosongkan jika tidak diganti)</small>' : '' ?></label>
                <input type="password" name="user_password" placeholder="Password" <?= $edit_user ? '' : 'required' ?>>
            </div>
            <div class="col">
                <label>Group User</label>
                <select name="group_id" required>
                    <option value="">-- Pilih Group --</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= htmlspecialchars($g['group_id']) ?>" <?= (($edit_user['group_id'] ?? '') === $g['group_id']) ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($g['group_id']) ?>] <?= htmlspecialchars($g['group_desc']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="text-align: right; margin-top: 15px;">
            <?php if ($edit_user): ?>
                <button type="submit" class="btn btn-warning">Update User</button>
                <a href="index.php" class="btn btn-secondary">Batal</a>
            <?php else: ?>
                <button type="submit" class="btn btn-primary">Simpan User Baru</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- DAFTAR USER TABLE -->
<div class="card">
    <h3>Daftar Pengguna</h3>
    <table>
        <thead>
            <tr>
                <th>User ID</th>
                <th>Nama User</th>
                <th>Password</th>
                <th>Group</th>
                <th class="text-center" width="140">Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= htmlspecialchars($u['user_id']) ?></strong></td>
                <td><?= htmlspecialchars($u['user_name']) ?></td>
                <td><code><?= htmlspecialchars($u['user_password']) ?></code></td>
                <td><span style="background: #e9ecef; padding: 2px 6px; border-radius: 4px; font-weight: bold;"><?= htmlspecialchars($u['group_id']) ?></span></td>
                <td class="text-center">
                    <a href="index.php?edit_id=<?= urlencode($u['user_id']) ?>" class="btn btn-warning" style="padding: 3px 8px; font-size:11px;">Edit</a>
                    <form method="POST" onsubmit="return confirm('Hapus user <?= $u['user_id'] ?>?');" style="display:inline;">
                        <input type="hidden" name="action" value="DELETE">
                        <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                        <button type="submit" class="btn btn-danger" style="padding: 3px 8px; font-size:11px;">Hapus</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>