<?php
session_start();
require_once 'functions.php';
require_once 'db.php';

// システムが初期化済みかチェック
function isSystemInitialized() {
    try {
        $db = new PDO('sqlite:kakeibo.db');
        $stmt = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='config'");
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// 必要なテーブルを作成する関数
function createTables($db) {
    // configテーブル
    $db->exec("CREATE TABLE IF NOT EXISTS config (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        key TEXT NOT NULL,
        value TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE(user_id, key)
    )");

    // ユーザー設定テーブルの作成
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_settings (
            user_id INTEGER PRIMARY KEY,
            input_mode TEXT DEFAULT 'modal' CHECK(input_mode IN ('modal', 'inline')),
            theme TEXT DEFAULT 'light' CHECK(theme IN ('light', 'dark', 'nerv')),
            currency TEXT DEFAULT 'JPY',
            date_format TEXT DEFAULT 'Y-m-d',
            start_of_week INTEGER DEFAULT 0 CHECK(start_of_week BETWEEN 0 AND 6),
            items_per_page INTEGER DEFAULT 20,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");

    // トリガーの作成（updated_atの自動更新用）
    $db->exec("
        CREATE TRIGGER IF NOT EXISTS update_user_settings_timestamp 
        AFTER UPDATE ON user_settings
        BEGIN
            UPDATE user_settings SET updated_at = CURRENT_TIMESTAMP WHERE user_id = NEW.user_id;
        END
    ");

    // usersテーブル
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        system_unique_id TEXT UNIQUE NOT NULL,
        bs_theme TEXT DEFAULT 'default',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // payment_methodsテーブル（決済手段）
    $db->exec("CREATE TABLE IF NOT EXISTS payment_methods (
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

    // kakeibo_dataテーブル
    $db->exec("CREATE TABLE IF NOT EXISTS kakeibo_data (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date DATE NOT NULL,
        transaction_type TEXT NOT NULL CHECK(transaction_type IN ('income', 'expense')),
        store_name TEXT NOT NULL,
        price INTEGER NOT NULL,
        payment_method_id INTEGER,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id)
    )");

    // transaction_items（取引項目）テーブル
    $db->exec("CREATE TABLE IF NOT EXISTS transaction_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        transaction_id INTEGER NOT NULL,
        product_name TEXT NOT NULL,
        price INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (transaction_id) REFERENCES kakeibo_data(id) ON DELETE CASCADE
    )");

    // storesテーブル（店舗の履歴）
    $db->exec("CREATE TABLE IF NOT EXISTS stores (
        user_id INTEGER NOT NULL,
        store_name TEXT NOT NULL,
        PRIMARY KEY (user_id, store_name),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // products（商品/項目名の履歴）テーブル
    $db->exec("CREATE TABLE IF NOT EXISTS products (
        user_id INTEGER NOT NULL,
        product_name TEXT NOT NULL,
        last_used DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, product_name),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // 既存のuser_settingsテーブルを更新
    $db->exec("
        -- 一時テーブルの作成
        CREATE TABLE IF NOT EXISTS user_settings_temp (
            user_id INTEGER PRIMARY KEY,
            input_mode TEXT DEFAULT 'modal' CHECK(input_mode IN ('modal', 'inline')),
            theme TEXT DEFAULT 'light' CHECK(theme IN ('light', 'dark', 'nerv')),
            currency TEXT DEFAULT 'JPY',
            date_format TEXT DEFAULT 'Y-m-d',
            start_of_week INTEGER DEFAULT 0 CHECK(start_of_week BETWEEN 0 AND 6),
            items_per_page INTEGER DEFAULT 20,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        -- 既存のデータを一時テーブルにコピー
        INSERT INTO user_settings_temp (user_id, input_mode, theme, currency, date_format, start_of_week, items_per_page, created_at, updated_at)
        SELECT user_id, input_mode, theme, 
               COALESCE(currency, 'JPY') as currency,
               COALESCE(date_format, 'Y-m-d') as date_format,
               COALESCE(start_of_week, 0) as start_of_week,
               COALESCE(items_per_page, 20) as items_per_page,
               created_at, updated_at
        FROM user_settings;

        -- 古いテーブルを削除
        DROP TABLE user_settings;

        -- 一時テーブルを正式名称にリネーム
        ALTER TABLE user_settings_temp RENAME TO user_settings;

        -- トリガーの再作成
        DROP TRIGGER IF EXISTS update_user_settings_timestamp;
        CREATE TRIGGER update_user_settings_timestamp 
        AFTER UPDATE ON user_settings
        BEGIN
            UPDATE user_settings SET updated_at = CURRENT_TIMESTAMP WHERE user_id = NEW.user_id;
        END;
    ");
}

// 初期設定を保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = new PDO('sqlite:kakeibo.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // トランザクション開始
        $db->beginTransaction();
        
        // すべての必要なテーブルを作成
        createTables($db);

        // 管理者ユーザーの作成
        $admin_password = password_hash('password', PASSWORD_DEFAULT);
        $admin_system_unique_id = generateUniqueId();
        
        $stmt = $db->prepare("INSERT OR IGNORE INTO users (username, password, system_unique_id) VALUES (?, ?, ?)");
        $stmt->execute(['admin', $admin_password, $admin_system_unique_id]);
        
        // 管理者ユーザーのIDを取得
        $admin_id = $db->lastInsertId();
        if (!$admin_id) {
            // すでに存在する場合はIDを取得
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute(['admin']);
            $admin_id = $stmt->fetchColumn();
        }

        // 管理者ユーザーの設定を追加
        $db->exec("INSERT OR IGNORE INTO user_settings (user_id, input_mode, theme) 
                  VALUES ($admin_id, 'modal', 'nerv')");

        // 管理者ユーザーの決済手段を追加
        $db->exec("INSERT OR IGNORE INTO payment_methods (user_id, name, type, is_default) 
                  VALUES 
                  ($admin_id, '現金', 'cash', 1),
                  ($admin_id, '電子マネー', 'prepaid', 0)");

        // 既存のユーザーにデフォルトの決済手段がない場合は追加
        $db->exec("INSERT OR IGNORE INTO payment_methods (user_id, name, type, is_default) 
                  SELECT 
                    id, 
                    '現金', 
                    'cash', 
                    1
                  FROM users 
                  WHERE NOT EXISTS (
                      SELECT 1 
                      FROM payment_methods 
                      WHERE payment_methods.user_id = users.id
                  )");

        // 既存のユーザーに電子マネーを追加
        $db->exec("INSERT OR IGNORE INTO payment_methods (user_id, name, type, is_default) 
                  SELECT 
                    id, 
                    '電子マネー', 
                    'prepaid', 
                    0
                  FROM users 
                  WHERE NOT EXISTS (
                      SELECT 1 
                      FROM payment_methods 
                      WHERE payment_methods.user_id = users.id 
                      AND payment_methods.name = '電子マネー'
                  )");

        // 既存のユーザーに設定がない場合は追加
        $db->exec("INSERT OR IGNORE INTO user_settings (user_id, input_mode, theme) 
                  SELECT id, 'modal', 'light' 
                  FROM users 
                  WHERE NOT EXISTS (
                      SELECT 1 
                      FROM user_settings 
                      WHERE user_settings.user_id = users.id
                  )");

        // 設定の保存
        $stmt = $db->prepare("INSERT OR REPLACE INTO config (user_id, key, value) VALUES (?, ?, ?)");
        $stmt->execute([$admin_id, 'company_name', $_POST['company_name']]);
        $stmt->execute([$admin_id, 'fiscal_year_start', $_POST['fiscal_year_start']]);
        $stmt->execute([$admin_id, 'currency', $_POST['currency']]);
        $stmt->execute([$admin_id, 'items_per_page', $_POST['items_per_page']]);
        
        // 初期化完了フラグを設定
        $stmt->execute([$admin_id, 'initialized', 'true']);
        
        // トランザクションをコミット
        $db->commit();
        
        header('Location: index.php');
        exit;
    } catch (PDOException $e) {
        // エラーが発生した場合はロールバック
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = '設定の保存に失敗しました: ' . $e->getMessage();
    }
}

// 既に初期化済みの場合はindexページにリダイレクト
if (isSystemInitialized()) {
    header('Location: index.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>システム初期設定</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3 class="text-center">会計システム初期設定</h3>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label for="company_name" class="form-label">組織名</label>
                                <input type="text" class="form-control" id="company_name" name="company_name" value="AccountingSystem" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="fiscal_year_start" class="form-label">会計年度開始月</label>
                                <select class="form-select" id="fiscal_year_start" name="fiscal_year_start" required>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($i == date('n')) ? 'selected' : ''; ?>><?php echo $i; ?>月</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="currency" class="form-label">通貨</label>
                                <select class="form-select" id="currency" name="currency" required>
                                    <option value="JPY">日本円 (JPY)</option>
                                    <option value="USD">米ドル (USD)</option>
                                    <option value="EUR">ユーロ (EUR)</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="items_per_page" class="form-label">1ページあたりのアイテム数</label>
                                <input type="number" class="form-control" id="items_per_page" name="items_per_page" value="20" required>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary">設定を保存</button>
                                <a href="reset.php" class="btn btn-outline-danger ms-2">システムリセット</a>
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