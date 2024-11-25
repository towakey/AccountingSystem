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
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('ファイルのアップロードに失敗しました。');
        }

        $import_type = $_POST['type'] ?? '';
        if (!in_array($import_type, ['transactions', 'payment_methods', 'settings'])) {
            throw new Exception('不正なインポートタイプです。');
        }

        // CSVファイルを読み込む
        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if ($file === false) {
            throw new Exception('ファイルを開けませんでした。');
        }

        // BOMを除去
        $bom = fread($file, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($file);
        }

        // ヘッダー行を読み込む
        $headers = fgetcsv($file);
        if ($headers === false) {
            throw new Exception('CSVヘッダーの読み込みに失敗しました。');
        }

        $pdo->beginTransaction();

        switch ($import_type) {
            case 'transactions':
                // 取引データのインポート
                while (($row = fgetcsv($file)) !== false) {
                    $data = array_combine($headers, $row);
                    
                    // 決済方法IDの取得または作成
                    $payment_method_id = null;
                    if (!empty($data['payment_method'])) {
                        $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE user_id = ? AND name = ?");
                        $stmt->execute([$user_id, $data['payment_method']]);
                        $payment_method_id = $stmt->fetchColumn();
                        
                        if (!$payment_method_id) {
                            $stmt = $pdo->prepare("INSERT INTO payment_methods (user_id, name, type) VALUES (?, ?, 'cash')");
                            $stmt->execute([$user_id, $data['payment_method']]);
                            $payment_method_id = $pdo->lastInsertId();
                        }
                    }
                    
                    // 取引データの挿入（IDを指定）
                    $original_id = $data['id'] ?? null;
                    if ($original_id) {
                        // 既存のデータを削除
                        $stmt = $pdo->prepare("DELETE FROM kakeibo_data WHERE id = ? AND user_id = ?");
                        $stmt->execute([$original_id, $user_id]);
                        
                        // SQLiteの自動インクリメントをリセット
                        $stmt = $pdo->prepare("UPDATE SQLITE_SEQUENCE SET seq = ? WHERE name = 'kakeibo_data'");
                        $stmt->execute([$original_id - 1]);
                        
                        // 新しいデータを挿入（IDを指定）
                        $stmt = $pdo->prepare("
                            INSERT INTO kakeibo_data (
                                id, user_id, date, transaction_type, store_name, price,
                                payment_method_id, note, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $original_id,
                            $user_id,
                            $data['date'],
                            $data['transaction_type'],
                            $data['store_name'] ?? '未設定',
                            $data['price'],
                            $payment_method_id,
                            $data['note'] ?? null,
                            $data['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                        $transaction_id = $original_id;
                    } else {
                        // 新規データとして挿入
                        $stmt = $pdo->prepare("
                            INSERT INTO kakeibo_data (
                                user_id, date, transaction_type, store_name, price,
                                payment_method_id, note, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $user_id,
                            $data['date'],
                            $data['transaction_type'],
                            $data['store_name'] ?? '未設定',
                            $data['price'],
                            $payment_method_id,
                            $data['note'] ?? null,
                            $data['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                        $transaction_id = $pdo->lastInsertId();
                    }
                    
                    // 取引項目の処理
                    if (!empty($data['items'])) {
                        $items = explode(',', $data['items']);
                        foreach ($items as $item) {
                            list($product_name, $price) = explode(':', $item);
                            $stmt = $pdo->prepare("
                                INSERT INTO transaction_items (
                                    transaction_id, product_name, price
                                ) VALUES (?, ?, ?)
                            ");
                            $stmt->execute([
                                $transaction_id,
                                trim($product_name),
                                trim($price)
                            ]);
                        }
                    } elseif (!empty($data['store_name'])) {
                        // 店舗名を商品名として使用
                        $stmt = $pdo->prepare("
                            INSERT INTO transaction_items (
                                transaction_id, product_name, price
                            ) VALUES (?, ?, ?)
                        ");
                        $stmt->execute([
                            $transaction_id,
                            $data['store_name'],
                            $data['price']
                        ]);
                    }
                }
                break;

            case 'payment_methods':
                // 決済方法のインポート
                while (($row = fgetcsv($file)) !== false) {
                    $data = array_combine($headers, $row);
                    
                    // 既存の決済方法をチェック
                    $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE user_id = ? AND name = ?");
                    $stmt->execute([$user_id, $data['name']]);
                    if (!$stmt->fetch()) {
                        $stmt = $pdo->prepare("
                            INSERT INTO payment_methods (
                                user_id, name, type, withdrawal_day,
                                is_default, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $user_id,
                            $data['name'],
                            $data['type'],
                            $data['withdrawal_day'] !== '' ? $data['withdrawal_day'] : null,
                            $data['is_default'] ?? 0,
                            $data['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                    }
                }
                break;

            case 'settings':
                // 設定のインポート
                while (($row = fgetcsv($file)) !== false) {
                    $data = array_combine($headers, $row);
                    
                    // 既存の設定を更新または新規作成
                    $stmt = $pdo->prepare("
                        INSERT OR REPLACE INTO config (
                            user_id, key, value, created_at
                        ) VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $user_id,
                        $data['key'],
                        $data['value'],
                        $data['created_at'] ?? date('Y-m-d H:i:s')
                    ]);
                }
                break;
        }

        $pdo->commit();
        fclose($file);
        
        $response = [
            'success' => true,
            'message' => 'データのインポートが完了しました。'
        ];

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isset($file)) {
            fclose($file);
        }
        $response = [
            'success' => false,
            'message' => 'エラー: ' . $e->getMessage()
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データインポート</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h2>データインポート</h2>
        
        <?php if (isset($response['message'])): ?>
            <div class="alert alert-<?php echo $response['success'] ? 'success' : 'danger'; ?>" role="alert">
                <?php echo htmlspecialchars($response['message']); ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="mb-4">
            <div class="mb-3">
                <label for="type" class="form-label">インポートするデータの種類</label>
                <select name="type" id="type" class="form-select" required>
                    <option value="">選択してください</option>
                    <option value="transactions">取引データ</option>
                    <option value="payment_methods">決済方法</option>
                    <option value="settings">設定</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label for="csv_file" class="form-label">CSVファイル</label>
                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                <div class="form-text">エクスポートしたCSVファイルを選択してください。</div>
            </div>

            <button type="submit" class="btn btn-primary">インポート</button>
            <a href="/AccountingSystem/" class="btn btn-secondary">戻る</a>
        </form>

        <div class="alert alert-info">
            <h4>注意事項</h4>
            <ul>
                <li>インポートするCSVファイルは、このシステムからエクスポートしたものを使用してください。</li>
                <li>取引データのインポート時、存在しない決済方法は自動的に作成されます。</li>
                <li>設定のインポート時、既存の設定は上書きされます。</li>
                <li>データの重複を避けるため、同じデータを複数回インポートしないようご注意ください。</li>
            </ul>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
