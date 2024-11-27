<?php
session_start();
require_once '../functions.php';
require_once '../db.php';

// ユーザー認証チェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '認証が必要です']);
    exit;
}

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

try {
    $pdo = new PDO('sqlite:' . dirname(__DIR__) . '/kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // 削除処理
        $data = json_decode(file_get_contents('php://input'), true);
        error_log('削除リクエスト: ' . print_r($data, true));

        $transaction_id = $_GET['id'] ?? null;
        if (!$transaction_id) {
            throw new Exception('取引IDが指定されていません');
        }

        // トランザクション開始
        $pdo->beginTransaction();
        try {
            // 取引が存在し、ユーザーのものであることを確認
            $stmt = $pdo->prepare("SELECT id FROM kakeibo_data WHERE id = ? AND user_id = ?");
            $stmt->execute([$transaction_id, $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('指定された取引が見つかりません');
            }

            // 関連する取引項目を削除
            $stmt = $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id = ?");
            $stmt->execute([$transaction_id]);

            // 取引を削除
            $stmt = $pdo->prepare("DELETE FROM kakeibo_data WHERE id = ? AND user_id = ?");
            $stmt->execute([$transaction_id, $user_id]);

            $pdo->commit();
            $response = ['success' => true, 'message' => '取引を削除しました'];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // 取引の詳細を取得
        $transaction_id = $_GET['id'] ?? null;
        if (!$transaction_id) {
            throw new Exception('取引IDが指定されていません');
        }

        // 取引データを取得
        $stmt = $pdo->prepare("
            SELECT k.*, p.name as payment_method_name
            FROM kakeibo_data k
            LEFT JOIN payment_methods p ON k.payment_method_id = p.id
            WHERE k.id = ? AND k.user_id = ?
        ");
        $stmt->execute([$transaction_id, $user_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            throw new Exception('指定された取引が見つかりません');
        }

        // 取引項目を取得
        $stmt = $pdo->prepare("
            SELECT id, product_name, price
            FROM transaction_items
            WHERE transaction_id = ?
        ");
        $stmt->execute([$transaction_id]);
        $transaction['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = ['success' => true, 'data' => $transaction];
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
        // 取引の更新
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON data: ' . json_last_error_msg());
        }
        error_log('受信データ: ' . print_r($data, true));

        $transaction_id = $data['id'] ?? null;
        if (!$transaction_id) {
            throw new Exception('取引IDが指定されていません');
        }

        // トランザクション開始
        $pdo->beginTransaction();
        try {
            // 取引が存在し、ユーザーのものであることを確認
            $stmt = $pdo->prepare("SELECT id FROM kakeibo_data WHERE id = ? AND user_id = ?");
            $stmt->execute([$transaction_id, $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('指定された取引が見つかりません');
            }

            // 必須項目のチェック
            if (empty($data['date'])) throw new Exception('日付が指定されていません');
            if (empty($data['transaction_type'])) throw new Exception('取引種別が指定されていません');
            if (empty($data['store_name'])) throw new Exception('取引先が指定されていません');
            if (!isset($data['price'])) throw new Exception('金額が指定されていません');
            if (empty($data['payment_method_id'])) throw new Exception('決済方法が指定されていません');
            if (empty($data['category'])) throw new Exception('カテゴリーが指定されていません');

            // 決済方法の存在確認
            $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE id = ? AND user_id = ?");
            $stmt->execute([$data['payment_method_id'], $user_id]);
            if (!$stmt->fetch()) {
                throw new Exception('指定された決済方法が見つかりません');
            }

            // 取引を更新
            $stmt = $pdo->prepare("
                UPDATE kakeibo_data 
                SET date = ?, 
                    transaction_type = ?, 
                    store_name = ?, 
                    price = ?,
                    payment_method_id = ?, 
                    category = ?,
                    note = ?
                WHERE id = ? AND user_id = ?
            ");

            $stmt->execute([
                $data['date'],
                $data['transaction_type'],
                $data['store_name'],
                $data['price'],
                $data['payment_method_id'],
                $data['category'],
                $data['note'] ?? null,
                $transaction_id,
                $user_id
            ]);

            // 既存の取引項目を削除
            $stmt = $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id = ?");
            $stmt->execute([$transaction_id]);

            // 新しい取引項目を追加
            if (!empty($data['items'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO transaction_items (transaction_id, product_name, price)
                    VALUES (?, ?, ?)
                ");
                foreach ($data['items'] as $item) {
                    $stmt->execute([
                        $transaction_id,
                        $item['product_name'],
                        $item['price']
                    ]);
                }
            }

            $pdo->commit();
            $response = ['success' => true, 'message' => '取引を更新しました'];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
} catch (Exception $e) {
    error_log('エラー: ' . $e->getMessage());
    $response = ['success' => false, 'message' => $e->getMessage()];
}

// レスポンスヘッダーの設定
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
