<?php
// config/helpers.php

/**
 * Cek apakah tanggal transaksi terkunci untuk gudang tertentu
 */
function isDateLocked(PDO $pdo, string $whid, string $transDate): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM lock_periods 
        WHERE whid = :whid 
          AND :trans_date BETWEEN start_date AND end_date
    ");
    $stmt->execute([
        ':whid'       => $whid,
        ':trans_date' => $transDate
    ]);
    
    return $stmt->fetchColumn() > 0;
}

/**
 * Mengambil daftar gudang yang diizinkan untuk user tertentu
 */
/**
 * Mengambil daftar gudang yang diizinkan untuk user tertentu
 */
function getUserWarehouses(PDO $pdo, string $userId): array {
    $stmt = $pdo->prepare("
        SELECT w.whid, w.whname 
        FROM whmast w
        INNER JOIN user_warehouse_access uwa ON w.whid = uwa.whid
        WHERE uwa.user_id = :user_id
        ORDER BY w.whname ASC
    ");
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll();
}