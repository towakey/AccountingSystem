<?php
session_start();
require_once 'functions.php';
require_once 'db.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

// 決済手段の取得
$stmt = $pdo->prepare("SELECT id, name FROM payment_methods WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['json_file']) && $_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
        $json_content = file_get_contents($_FILES['json_file']['tmp_name']);
        $data = json_decode($json_content, true);

        if ($data === null) {
            $error = 'JSONファイルの解析に失敗しました。';
        } else {
            try {
                $pdo->beginTransaction();

                // 取引追加用のステートメントを準備
                $stmt = $pdo->prepare("
                    INSERT INTO kakeibo_data 
                    (user_id, date, transaction_type, store_name, price, payment_method_id)
                    VALUES (?, ?, 'expense', ?, ?, ?)
                ");

                // 店舗履歴用のステートメント
                $store_stmt = $pdo->prepare("INSERT OR IGNORE INTO stores (user_id, store_name) VALUES (?, ?)");

                $imported_count = 0;
                foreach ($data as $row) {
                    // 日付のフォーマット変換（必要に応じて調整）
                    $date = date('Y-m-d', strtotime($row['date']));
                    
                    // 決済手段のIDを取得
                    $payment_method_id = null;
                    $payment_method_found = false;
                    foreach ($payment_methods as $method) {
                        if (strpos(strtolower($method['name']), strtolower($row['payment_method'])) !== false) {
                            $payment_method_id = $method['id'];
                            $payment_method_found = true;
                            break;
                        }
                    }

                    // 決済手段が見つからない場合は新規登録
                    if (!$payment_method_found) {
                        $payment_method_stmt = $pdo->prepare("
                            INSERT INTO payment_methods (user_id, name, type, is_default) 
                            VALUES (?, ?, 'cash', 0)
                        ");
                        $payment_method_stmt->execute([$_SESSION['user_id'], $row['payment_method']]);
                        $payment_method_id = $pdo->lastInsertId();
                        
                        // 新しい決済手段を配列に追加
                        $payment_methods[] = [
                            'id' => $payment_method_id,
                            'name' => $row['payment_method']
                        ];
                    }

                    // データを挿入
                    $stmt->execute([
                        $_SESSION['user_id'],
                        $date,
                        $row['store_name'],
                        $row['amount'],
                        $payment_method_id
                    ]);

                    // 店舗履歴を保存
                    $store_stmt->execute([$_SESSION['user_id'], $row['store_name']]);

                    $imported_count++;
                }

                $pdo->commit();
                $success = $imported_count . '件のデータをインポートしました。';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'インポート中にエラーが発生しました: ' . $e->getMessage();
            }
        }
    } else {
        $error = 'ファイルのアップロードに失敗しました。';
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データインポート - 家計簿システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/nerv-theme.css" rel="stylesheet">
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h4 mb-0">データインポート</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>

                        <div class="mb-4">
                            <h5>インポート手順</h5>
                            <ol>
                                <li>Excelデータを以下の形式のJSONファイルに変換してください：
                                    <pre class="bg-light p-3 mt-2">
[
    {
        "date": "2024-01-01",
        "store_name": "スーパー○○",
        "amount": 1000,
        "payment_method": "現金"
    },
    ...
]</pre>
                                </li>
                                <li>変換したJSONファイルをアップロードしてください。</li>
                            </ol>
                        </div>

                        <form method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="json_file" class="form-label">JSONファイル</label>
                                <input type="file" class="form-control" id="json_file" name="json_file" accept=".json" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">現在設定されている決済手段</label>
                                <ul class="list-unstyled">
                                    <?php foreach ($payment_methods as $method): ?>
                                        <li><?php echo htmlspecialchars($method['name']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="form-text">
                                    JSONファイルの payment_method は上記のいずれかに一致するようにしてください。
                                    一致しない決済手段は「現金払い」として新規登録されます。
                                    必要に応じて、設定画面で決済手段の種類を変更してください。
                                </div>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">インポート</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
