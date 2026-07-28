<?php
// modules/transactions/get_doc_no.php

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$type = $_GET['type'] ?? 'IN';

$moducdMap = [
    'IN'  => 'INVOTHIN',
    'OUT' => 'INVOTHOUT',
    'ADJ' => 'ADJUST'
];

$moducd = $moducdMap[$type] ?? 'INVOTHIN';

try {
    $stmt = $pdo->prepare("SELECT textvl, numevl FROM sysset WHERE moducd = :moducd LIMIT 1");
    $stmt->execute([':moducd' => $moducd]);
    $sysset = $stmt->fetch();

    if ($sysset) {
        $prefix  = $sysset['textvl'];
        $counter = str_pad((int)$sysset['numevl'], 5, '0', STR_PAD_LEFT);
        $period  = date('ym'); // Format YYMM (misal: 2607)
        
        // Contoh Output: 7ISJ260700009
        $docNo = $prefix . $period . $counter;
        
        echo json_encode(['success' => true, 'doc_no' => $docNo]);
    } else {
        echo json_encode(['success' => false, 'doc_no' => '']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}