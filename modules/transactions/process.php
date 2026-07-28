<?php
// modules/transactions/process.php

require_once '../../config/database.php';
require_once '../../config/helpers.php';

$current_user = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type        = $_POST['type'] ?? '';        // 'IN', 'OUT', atau 'ADJ'
    $doc_no      = trim($_POST['doc_no'] ?? '');
    $trans_date  = $_POST['trans_date'] ?? '';
    $whid        = $_POST['whid'] ?? '';
    $remark      = trim($_POST['remark'] ?? '');
    $items       = $_POST['items'] ?? [];       // Array item dari form

    // 1. Validasi Input Dasar
    if (empty($doc_no) || empty($trans_date) || empty($whid) || empty($items)) {
        die("Error: Semua field wajib diisi dan minimal ada 1 barang.");
    }

    // 2. Validasi Lock Period
    if (isDateLocked($pdo, $whid, $trans_date)) {
        die("Error Transaksi Ditolak: Tanggal $trans_date pada gudang $whid sedang DALAM PERIOD LOCK. Hubungi Admin.");
    }

    try {
        $pdo->beginTransaction();

        // -------------------------------------------------------------
        // A. PENANGANAN TRANSAKSI BERDASARKAN JENIS (IN / OUT / ADJ)
        // -------------------------------------------------------------
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
                    // Menyimpan kode barang (menggunakan prefix base_code + '1' sebagai default)
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

        // -------------------------------------------------------------
        // B. UPDATE RUNNING NUMBER DI TABEL SYSSET
        // -------------------------------------------------------------
        $settype = '';
        if ($type === 'IN') {
            $settype = 'NUMOTHIN';
        } elseif ($type === 'OUT') {
            $settype = 'NUMOTHOUT';
        } elseif ($type === 'ADJ') {
            $settype = 'NUMADJUST';
        }

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
}