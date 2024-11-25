<?php
session_start();
$root_path = str_replace('\\', '/', dirname(__DIR__));
require_once $root_path . '/db.php';
require_once $root_path . '/functions.php';

// ユーザー認証チェック
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $root_path . '/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$export_type = $_GET['type'] ?? 'transactions';
$export_range = $_GET['range'] ?? 'month'; // 'month' または 'all'
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

try {
    // $pdoはdb.phpで既に定義されています
    
    switch ($export_type) {
        case 'transactions':
            // 取引データのエクスポート
            $query = "
                SELECT 
                    k.id,
                    k.date,
                    k.transaction_type,
                    k.store_name,
                    k.price,
                    p.name as payment_method,
                    k.note,
                    k.created_at,
                    (
                        SELECT GROUP_CONCAT(product_name || ':' || price)
                        FROM transaction_items
                        WHERE transaction_id = k.id
                    ) as items
                FROM kakeibo_data k
                LEFT JOIN payment_methods p ON k.payment_method_id = p.id
                WHERE k.user_id = ?";
            
            if ($export_range === 'month') {
                $query .= " AND k.date BETWEEN ? AND ?";
                $params = [$user_id, $start_date, $end_date];
                $filename = "transactions_{$start_date}_{$end_date}.csv";
            } else {
                $params = [$user_id];
                $filename = "transactions_all.csv";
            }
            
            $query .= " ORDER BY k.date DESC, k.id DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'payment_methods':
            // 決済方法データのエクスポート
            $stmt = $pdo->prepare("
                SELECT 
                    name,
                    type,
                    withdrawal_day,
                    is_default,
                    created_at
                FROM payment_methods
                WHERE user_id = ?
                ORDER BY id
            ");
            $stmt->execute([$user_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filename = "payment_methods.csv";
            break;
            
        case 'settings':
            // 設定データのエクスポート
            $stmt = $pdo->prepare("
                SELECT 
                    key,
                    value,
                    created_at
                FROM config
                WHERE user_id = ?
                ORDER BY key
            ");
            $stmt->execute([$user_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $filename = "settings.csv";
            break;
            
        default:
            throw new Exception('Invalid export type');
    }
    
    // CSVヘッダーの設定
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // 出力バッファを開始
    ob_start();
    $output = fopen('php://output', 'w');
    
    // BOMを出力（Excel対応）
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // ヘッダー行を出力
    fputcsv($output, array_keys($data[0]));
    
    // データを出力
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    // バッファをフラッシュしてクローズ
    fclose($output);
    ob_end_flush();
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
