<?php
// modules/transactions/process.php

require_once '../../config/database.php';
require_once '../../config/helpers.php';

$current_user = 'admin'; // Sesuaikan dengan session user aktif

// -------------------------------------------------------------
// 1. HANDLER SOFT-DELETE / CANCEL (AKSI DELETE)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'DELETE') {
    header('Content-Type: application/json');
    $type  = $_POST['type'] ?? '';
    $docNo = trim($_POST['doc_no'] ?? '');

    if (empty($docNo) || empty($type)) {
        echo json_encode(['success' => false, 'message' => 'Parameter tidak valid.']);
        exit;
    }

    // Mapping nama tabel & kolom ID
    if ($type === 'OUT') {
        $masTable = 'othoutmas';
        $idCol    = 'othoutid';
    } elseif ($type === 'ADJ') {
        $masTable = 'adjmas';
        $idCol    = 'adjid';
    } else {
        $masTable = 'othinmas';
        $idCol    = 'othinid';
    }

    try {
        // Cek status transaksi terlebih dahulu
        $stmtCheck = $pdo->prepare("SELECT status FROM {$masTable} WHERE {$idCol} = :docNo");
        $stmtCheck->execute([':docNo' => $docNo]);
        $currentStatus = $stmtCheck->fetchColumn();

        if ($currentStatus === 'P') {
            echo json_encode(['success' => false, 'message' => 'Gagal: Transaksi yang sudah Posted tidak dapat dibatalkan/dihapus.']);
            exit;
        }

        // Update status menjadi X (Cancel)
        $stmt = $pdo->prepare("
            UPDATE {$masTable} 
            SET status = 'X', 
                update_by = :user, 
                update_date = NOW() 
            WHERE {$idCol} = :docNo
        ");
        $stmt->execute([
            ':user'  => $current_user,
            ':docNo' => $docNo
        ]);

        echo json_encode(['success' => true, 'message' => "Transaksi {$docNo} berhasil dibatalkan (Status diubah ke X)."]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => "Gagal memproses pembatalan: " . $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------
// 2. HANDLER FORM POST (CREATE & UPDATE)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_type = $_POST['action_type'] ?? 'CREATE'; // Default CREATE jika tidak dikirim
    $type        = $_POST['type'] ?? '';              // 'IN', 'OUT', atau 'ADJ'
    $doc_no      = trim($_POST['doc_no'] ?? '');
    $trans_date  = $_POST['trans_date'] ?? '';
    $whid        = $_POST['whid'] ?? '';
    $remark      = trim($_POST['remark'] ?? '');
    $items       = $_POST['items'] ?? [];             // Array detail items

    // Validasi Input Dasar
    if (empty($doc_no) || empty($trans_date) || empty($items)) {
        echo "<script>
            alert('Error: Semua field wajib diisi dan minimal ada 1 barang.');
            window.history.back();
        </script>";
        exit;
    }

    // ---------------------------------------------------------
    // A. PROSES CREATE (TRANSAKSI BARU)
    // ---------------------------------------------------------
    if ($action_type === 'CREATE') {
        if (empty($whid)) {
            echo "<script>alert('Error: Gudang wajib dipilih.'); window.history.back();</script>";
            exit;
        }

        // Validasi Lock Period
        if (isDateLocked($pdo, $whid, $trans_date)) {
            echo "<script>
                alert('Error Transaksi Ditolak: Tanggal $trans_date pada gudang $whid sedang DALAM PERIOD LOCK.');
                window.history.back();
            </script>";
            exit;
        }

        try {
            $pdo->beginTransaction();

            if ($type === 'IN') {
                // Header Barang Masuk
                $stmtHeader = $pdo->prepare("
                    INSERT INTO othinmas (othinid, othindate, whid, remark, create_by, create_date, status) 
                    VALUES (:doc_no, :trans_date, :whid, :remark, :user, NOW(), 'A')
                ");
                $stmtHeader->execute([
                    ':doc_no'     => $doc_no,
                    ':trans_date' => $trans_date,
                    ':whid'       => $whid,
                    ':remark'     => $remark,
                    ':user'       => $current_user
                ]);

                // Detail Barang Masuk
                $stmtDetail = $pdo->prepare("
                    INSERT INTO othindet (othinid, icode, qty, uom, qty2, uom2) 
                    VALUES (:doc_no, :icode, :qty, 'KG', :qty2, 'PCS')
                ");
                foreach ($items as $item) {
                    $base_code = $item['item_base'] ?? '';
                    $qty_kg    = (float)($item['qty_kg'] ?? 0);
                    $qty_pcs   = (float)($item['qty_pcs'] ?? 0);

                    if (!empty($base_code) && ($qty_kg > 0 || $qty_pcs > 0)) {
                        $icode = $base_code . '1';
                        $stmtDetail->execute([
                            ':doc_no' => $doc_no,
                            ':icode'  => $icode,
                            ':qty'    => $qty_kg,
                            ':qty2'   => $qty_pcs
                        ]);
                    }
                }

            } elseif ($type === 'OUT') {
                // Header Barang Keluar
                $stmtHeader = $pdo->prepare("
                    INSERT INTO othoutmas (othoutid, othoutdate, whid, remark, create_by, create_date, status) 
                    VALUES (:doc_no, :trans_date, :whid, :remark, :user, NOW(), 'A')
                ");
                $stmtHeader->execute([
                    ':doc_no'     => $doc_no,
                    ':trans_date' => $trans_date,
                    ':whid'       => $whid,
                    ':remark'     => $remark,
                    ':user'       => $current_user
                ]);

                // Detail Barang Keluar
                $stmtDetail = $pdo->prepare("
                    INSERT INTO othoutdet (othoutid, icode, qty, uom, qty2, uom2) 
                    VALUES (:doc_no, :icode, :qty, 'KG', :qty2, 'PCS')
                ");
                foreach ($items as $item) {
                    $base_code = $item['item_base'] ?? '';
                    $qty_kg    = (float)($item['qty_kg'] ?? 0);
                    $qty_pcs   = (float)($item['qty_pcs'] ?? 0);

                    if (!empty($base_code) && ($qty_kg > 0 || $qty_pcs > 0)) {
                        $icode = $base_code . '1';
                        $stmtDetail->execute([
                            ':doc_no' => $doc_no,
                            ':icode'  => $icode,
                            ':qty'    => $qty_kg,
                            ':qty2'   => $qty_pcs
                        ]);
                    }
                }

            } elseif ($type === 'ADJ') {
                // Header Adjustment Stock
                $stmtHeader = $pdo->prepare("
                    INSERT INTO adjmas (adjid, adjdate, whid, remark, create_by, create_date, status) 
                    VALUES (:doc_no, :trans_date, :whid, :remark, :user, NOW(), 'A')
                ");
                $stmtHeader->execute([
                    ':doc_no'     => $doc_no,
                    ':trans_date' => $trans_date,
                    ':whid'       => $whid,
                    ':remark'     => $remark,
                    ':user'       => $current_user
                ]);

                // Detail Adjustment Stock
                $stmtDetail = $pdo->prepare("
                    INSERT INTO adjdet (adjid, icode, qty, uom, qty2, uom2) 
                    VALUES (:doc_no, :icode, :qty, 'KG', :qty2, 'PCS')
                ");
                foreach ($items as $item) {
                    $base_code = $item['item_base'] ?? '';
                    $qty_kg    = (float)($item['qty_kg'] ?? 0);
                    $qty_pcs   = (float)($item['qty_pcs'] ?? 0);

                    if (!empty($base_code) && ($qty_kg != 0 || $qty_pcs != 0)) {
                        $icode = $base_code . '1';
                        $stmtDetail->execute([
                            ':doc_no' => $doc_no,
                            ':icode'  => $icode,
                            ':qty'    => $qty_kg,
                            ':qty2'   => $qty_pcs
                        ]);
                    }
                }
            }

            // Update running number di sysset
            $settype = ($type === 'IN') ? 'NUMOTHIN' : (($type === 'OUT') ? 'NUMOTHOUT' : 'NUMADJUST');
            if (!empty($settype)) {
                $stmtSysset = $pdo->prepare("
                    UPDATE sysset 
                    SET numevl = numevl + 1,
                        update_by = :user,
                        update_date = NOW()
                    WHERE settype = :settype
                ");
                $stmtSysset->execute([
                    ':user'    => $current_user,
                    ':settype' => $settype
                ]);
            }

            $pdo->commit();

            $message = "Sukses: Transaksi {$type} ({$doc_no}) berhasil disimpan!";
            echo "<script>
                alert(" . json_encode($message) . ");
                window.location.href = 'index.php';
            </script>";
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Gagal menyimpan transaksi: " . $e->getMessage();
            echo "<script>
                alert(" . json_encode($error_message) . ");
                window.history.back();
            </script>";
            exit;
        }

    // ---------------------------------------------------------
    // B. PROSES UPDATE (EDIT TRANSAKSI HANYA UNTUK STATUS A)
    // ---------------------------------------------------------
    } elseif ($action_type === 'UPDATE') {
        if ($type === 'OUT') {
            $masTable = 'othoutmas';
            $detTable = 'othoutdet';
            $idCol    = 'othoutid';
            $dateCol  = 'othoutdate';
        } elseif ($type === 'ADJ') {
            $masTable = 'adjmas';
            $detTable = 'adjdet';
            $idCol    = 'adjid';
            $dateCol  = 'adjdate';
        } else {
            $masTable = 'othinmas';
            $detTable = 'othindet';
            $idCol    = 'othinid';
            $dateCol  = 'othindate';
        }

        try {
            $pdo->beginTransaction();

            // Verifikasi bahwa status transaksi memang masih 'A'
            $stmtCheck = $pdo->prepare("SELECT status, whid FROM {$masTable} WHERE {$idCol} = :doc_no");
            $stmtCheck->execute([':doc_no' => $doc_no]);
            $currentData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$currentData || $currentData['status'] !== 'A') {
                throw new Exception("Hanya data aktif (status A) yang dapat diedit.");
            }

            // Validasi Lock Period untuk tanggal baru jika diubah
            if (isDateLocked($pdo, $currentData['whid'], $trans_date)) {
                throw new Exception("Tanggal $trans_date pada gudang {$currentData['whid']} sedang DALAM PERIOD LOCK.");
            }

            // Update Header
            $stmtH = $pdo->prepare("
                UPDATE {$masTable} 
                SET {$dateCol} = :trans_date, 
                    remark = :remark, 
                    update_by = :user, 
                    update_date = NOW() 
                WHERE {$idCol} = :doc_no AND status = 'A'
            ");
            $stmtH->execute([
                ':trans_date' => $trans_date,
                ':remark'     => $remark,
                ':user'       => $current_user,
                ':doc_no'     => $doc_no
            ]);

            // Update Detail Item Quantities
            $stmtD = $pdo->prepare("
                UPDATE {$detTable} 
                SET qty = :qty, 
                    qty2 = :qty2 
                WHERE {$idCol} = :doc_no AND icode = :icode
            ");

            foreach ($items as $item) {
                $icode  = $item['icode'] ?? '';
                $qty_kg = (float)($item['qty_kg'] ?? 0);
                $qty_pcs = (float)($item['qty_pcs'] ?? 0);

                if (!empty($icode)) {
                    $stmtD->execute([
                        ':qty'    => $qty_kg,
                        ':qty2'   => $qty_pcs,
                        ':doc_no' => $doc_no,
                        ':icode'  => $icode
                    ]);
                }
            }

            $pdo->commit();

            $message = "Sukses: Transaksi {$doc_no} berhasil diperbarui!";
            echo "<script>
                alert(" . json_encode($message) . ");
                window.location.href = 'index.php';
            </script>";
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Gagal memperbarui transaksi: " . $e->getMessage();
            echo "<script>
                alert(" . json_encode($error_message) . ");
                window.history.back();
            </script>";
            exit;
        }
    }
}