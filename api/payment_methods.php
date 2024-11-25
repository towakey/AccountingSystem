<?php
// エラー出力を有効化（デバッグ用）
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '../debug.log');

// 出力バッファリングを開始
ob_start();

// デバッグ情報をログに記録
error_log('Request Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('Raw input: ' . file_get_contents('php://input'));

header('Content-Type: application/json; charset=utf-8');

require_once '../db.php';
require_once '../functions.php';

// セッションの開始（まだ開始されていない場合のみ）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// デバッグ情報をログに記録
error_log('Session status: ' . session_status());
error_log('Session ID: ' . session_id());
error_log('User ID: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set'));

// APIエンドポイントではHTMLリダイレクトの代わりにJSONレスポンスを返す
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'ログインが必要です'
    ]);
    exit;
}

try {
    $pdo = new PDO('sqlite:../kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // payment_methodsテーブルが存在しない場合は作成
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'payment',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // リクエストメソッドに応じて処理を分岐
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            // 決済方法の一覧を取得
            $stmt = $pdo->prepare("SELECT id, name, type FROM payment_methods WHERE user_id = ? ORDER BY name ASC");
            $stmt->execute([$_SESSION['user_id']]);
            $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $methods
            ]);
            break;

        case 'POST':
            // 新しい決済方法を追加
            $input = json_decode(file_get_contents('php://input'), true);
            error_log('Received input: ' . json_encode($input));
            
            if (!isset($input['name']) || empty(trim($input['name']))) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => '決済方法名を入力してください'
                ]);
                exit;
            }

            if (!isset($input['type']) || !in_array($input['type'], ['cash', 'credit', 'debit', 'prepaid'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => '有効な決済方法の種類を選択してください'
                ]);
                exit;
            }

            // 同じ名前の決済方法が既に存在するかチェック
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE user_id = ? AND name = ?");
            $stmt->execute([$_SESSION['user_id'], trim($input['name'])]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'この決済方法は既に登録されています'
                ]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO payment_methods (user_id, name, type) VALUES (?, ?, ?)");
            $result = $stmt->execute([$_SESSION['user_id'], trim($input['name']), $input['type']]);

            if ($result) {
                http_response_code(201); // Created
                echo json_encode([
                    'success' => true,
                    'message' => '決済方法を追加しました'
                ]);
            } else {
                throw new Exception('決済方法の追加に失敗しました');
            }
            break;

        case 'DELETE':
            // 決済方法を削除
            if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => '無効な決済方法IDです'
                ]);
                exit;
            }

            $id = $_GET['id'];

            // 取引で使用されているかチェック
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM kakeibo_data WHERE payment_method_id = ?");
            $checkStmt->execute([$id]);
            $count = $checkStmt->fetchColumn();

            if ($count > 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'この決済方法は既に取引で使用されているため、削除できません'
                ]);
                exit;
            }

            // 決済方法を削除
            $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND user_id = ?");
            $result = $stmt->execute([$id, $_SESSION['user_id']]);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => '決済方法を削除しました'
                ]);
            } else {
                throw new Exception('決済方法の削除に失敗しました');
            }
            break;

        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => '許可されていないメソッドです'
            ]);
            break;
    }
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'データベースエラーが発生しました: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
