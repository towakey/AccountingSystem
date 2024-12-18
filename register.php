<?php
require_once 'db.php';
require_once 'functions.php';

// テーブルが存在しない場合は作成
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        system_unique_id TEXT UNIQUE NOT NULL,
        bs_theme TEXT DEFAULT 'default',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 決済手段テーブルの作成
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        type TEXT NOT NULL CHECK(type IN ('cash', 'credit', 'debit', 'prepaid')),
        withdrawal_day INTEGER CHECK(withdrawal_day BETWEEN 1 AND 31),
        is_default INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE(user_id, name)
    )");

    // ユーザー設定テーブルの作成
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        input_mode TEXT DEFAULT 'modal',
        theme TEXT DEFAULT 'light',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // カスタムフィールドテーブルの作成
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_fields (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        field_name TEXT NOT NULL,
        field_value TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
} catch (PDOException $e) {
    die("テーブル作成エラー: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $system_unique_id = generateUniqueId();

    try {
        $pdo->beginTransaction();

        // ユーザーの登録
        $stmt = $pdo->prepare("INSERT INTO users (username, password, system_unique_id) VALUES (?, ?, ?)");
        $stmt->execute([$username, $password, $system_unique_id]);
        $user_id = $pdo->lastInsertId();

        // デフォルトの決済手段を追加
        $stmt = $pdo->prepare("INSERT INTO payment_methods (user_id, name, type, is_default) VALUES (?, '現金', 'cash', 1)");
        $stmt->execute([$user_id]);

        // ユーザー設定の初期化
        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, input_mode, theme) VALUES (?, 'modal', 'light')");
        $stmt->execute([$user_id]);

        // カスタムフィールドの保存
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'custom_field_') === 0 && !empty($value)) {
                $stmt = $pdo->prepare("INSERT INTO custom_fields (user_id, field_name, field_value) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $key, $value]);
            }
        }

        $pdo->commit();
        header('Location: login.php');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == '23000') {
            $error = "ユーザー名が既に存在します";
        } else {
            $error = "登録エラー: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ユーザー登録</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h1 class="h3 mb-0 text-center">ユーザー登録</h1>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        
                        <form method="post">
                            <div id="form-fields">
                                <div class="mb-3">
                                    <label for="username" class="form-label">ユーザー名</label>
                                    <input type="text" name="username" id="username" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label for="password" class="form-label">パスワード</label>
                                    <input type="password" name="password" id="password" class="form-control" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <button type="button" class="btn btn-secondary" onclick="addField()">項目を追加</button>
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">登録</button>
                            </div>
                        </form>
                        <div class="text-center mt-3">
                            <a href="login.php" class="btn btn-link">ログインはこちら</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function addField() {
            const container = document.getElementById('form-fields');
            const fieldCount = container.getElementsByClassName('custom-field').length;
            
            const newField = document.createElement('div');
            newField.className = 'mb-3 custom-field';
            newField.innerHTML = `
                <div class="input-group">
                    <input type="text" name="custom_field_${fieldCount + 1}" class="form-control" placeholder="新しい項目">
                    <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.parentElement.remove()">削除</button>
                </div>
            `;
            
            container.appendChild(newField);
        }
    </script>
</body>
</html>