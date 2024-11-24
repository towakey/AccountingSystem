<?php
session_start();
require_once 'functions.php';
require_once 'db.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';

try {
    $pdo = new PDO('sqlite:kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 設定を更新
        $settings = [
            'input_mode' => $_POST['input_mode'],
            'theme' => $_POST['theme'],
            'currency_format' => $_POST['currency_format'],
            'date_format' => $_POST['date_format'],
            'week_start' => $_POST['week_start'],
            'items_per_page' => intval($_POST['items_per_page'])
        ];

        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO config (key, value, user_id) 
            VALUES (?, ?, ?)
        ");

        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value, $user_id]);
        }
        
        $message = '設定を保存しました。';
    }

    // 現在の設定を取得
    $stmt = $pdo->prepare("SELECT key, value FROM config WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $config_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 設定を連想配列に変換
    $config = [];
    foreach ($config_rows as $row) {
        $config[$row['key']] = $row['value'];
    }

    // デフォルト値の設定
    $config = array_merge([
        'input_mode' => 'modal',
        'theme' => 'light',
        'currency_format' => 'yen',
        'date_format' => 'Y/m/d',
        'week_start' => 'sunday',
        'items_per_page' => '10'
    ], $config);

} catch (PDOException $e) {
    $message = 'データベースエラー: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>設定 - 家計簿システム</title>
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
                        <h2 class="h4 mb-0">設定</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                        <?php endif; ?>

                        <form method="post">
                            <div class="mb-3">
                                <label for="input_mode" class="form-label">入力モード</label>
                                <select class="form-select" id="input_mode" name="input_mode">
                                    <option value="modal" <?php echo $config['input_mode'] === 'modal' ? 'selected' : ''; ?>>モーダル</option>
                                    <option value="inline" <?php echo $config['input_mode'] === 'inline' ? 'selected' : ''; ?>>インライン</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="theme" class="form-label">テーマ</label>
                                <select class="form-select" id="theme" name="theme">
                                    <option value="light" <?php echo $config['theme'] === 'light' ? 'selected' : ''; ?>>ライト</option>
                                    <option value="dark" <?php echo $config['theme'] === 'dark' ? 'selected' : ''; ?>>ダーク</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="currency_format" class="form-label">通貨形式</label>
                                <select class="form-select" id="currency_format" name="currency_format">
                                    <option value="yen" <?php echo $config['currency_format'] === 'yen' ? 'selected' : ''; ?>>円 (¥)</option>
                                    <option value="usd" <?php echo $config['currency_format'] === 'usd' ? 'selected' : ''; ?>>USD ($)</option>
                                    <option value="eur" <?php echo $config['currency_format'] === 'eur' ? 'selected' : ''; ?>>EUR (€)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="date_format" class="form-label">日付形式</label>
                                <select class="form-select" id="date_format" name="date_format">
                                    <option value="Y/m/d" <?php echo $config['date_format'] === 'Y/m/d' ? 'selected' : ''; ?>>YYYY/MM/DD</option>
                                    <option value="m/d/Y" <?php echo $config['date_format'] === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                    <option value="d/m/Y" <?php echo $config['date_format'] === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="week_start" class="form-label">週の開始日</label>
                                <select class="form-select" id="week_start" name="week_start">
                                    <option value="sunday" <?php echo $config['week_start'] === 'sunday' ? 'selected' : ''; ?>>日曜日</option>
                                    <option value="monday" <?php echo $config['week_start'] === 'monday' ? 'selected' : ''; ?>>月曜日</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="items_per_page" class="form-label">1ページあたりの表示件数</label>
                                <input type="number" class="form-control" id="items_per_page" name="items_per_page" 
                                    value="<?php echo htmlspecialchars($config['items_per_page']); ?>" 
                                    min="5" max="100" required>
                                <div class="form-text">5から100の間で設定してください</div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">設定を保存</button>
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
