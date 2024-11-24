<?php
require_once 'db.php';
require_once 'functions.php';

check_login();

$user_id = $_SESSION['user_id'];

// ユーザー名を取得
$username = get_username($pdo, $user_id);

// ユーザー設定を取得
$stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_settings) {
    // 設定が存在しない場合は初期設定を作成
    $stmt = $pdo->prepare("INSERT INTO user_settings (user_id) VALUES (?)");
    $stmt->execute([$user_id]);
    $user_settings = [
        'input_mode' => 'modal',
        'theme' => 'light',
        'currency' => 'JPY',
        'date_format' => 'Y-m-d',
        'start_of_week' => 0,
        'items_per_page' => 20
    ];
}

// テーマを取得
$user_theme = $user_settings['theme'];

// 設定更新処理
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_settings'])) {
    try {
        $stmt = $pdo->prepare("
            UPDATE user_settings 
            SET input_mode = ?, 
                theme = ?,
                currency = ?,
                date_format = ?,
                start_of_week = ?,
                items_per_page = ?
            WHERE user_id = ?
        ");
        
        $stmt->execute([
            $_POST['input_mode'],
            $_POST['theme'],
            $_POST['currency'],
            $_POST['date_format'],
            $_POST['start_of_week'],
            $_POST['items_per_page'],
            $user_id
        ]);
        
        // ユーザーテーマも更新（互換性のため）
        $stmt = $pdo->prepare("UPDATE users SET bs_theme = ? WHERE id = ?");
        $stmt->execute([$_POST['theme'], $user_id]);
        
        $success_message = "設定を更新しました。";
        
        // 設定を再読み込み
        $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error_message = "設定の更新に失敗しました: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>設定 - 家計簿アプリ</title>
    <?php if ($user_settings['theme'] === 'nerv'): ?>
        <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="css/nerv-theme.css" rel="stylesheet">
    <?php elseif ($user_settings['theme'] === 'dark'): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.1/dist/darkly/bootstrap.min.css" rel="stylesheet">
    <?php elseif ($user_settings['theme'] === 'darkly'): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.1/dist/darkly/bootstrap.min.css" rel="stylesheet">
    <?php else: ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="<?= $user_settings['theme'] === 'nerv' ? 'nerv-theme' : '' ?>">
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-<?= $user_theme === 'light' ? 'light bg-light' : ($user_theme === 'darkly' ? 'dark bg-dark' : 'dark bg-primary') ?>">
            <div class="container-fluid">
                <a class="navbar-brand" href="#">家計簿アプリ</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav">
                        <li class="nav-item">
                            <a class="nav-link" href="index.php">ホーム</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" aria-current="page" href="settings.php">設定</a>
                        </li>
                    </ul>
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <span class="nav-link">
                                <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($username); ?>
                            </span>
                        </li>
                        <li class="nav-item">
                            <a href="logout.php" class="btn btn-<?= $user_theme === 'light' ? 'outline-dark' : 'outline-light' ?>">
                                <i class="bi bi-box-arrow-right"></i> ログアウト
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="row mt-4">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title mb-0">
                            <i class="bi bi-gear-fill"></i> アプリケーション設定
                        </h2>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success_message)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success_message); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post">
                            <div class="mb-3">
                                <label for="theme" class="form-label">
                                    <i class="bi bi-palette-fill"></i> テーマ設定
                                </label>
                                <select name="theme" id="theme" class="form-select">
                                    <option value="light" <?= $user_settings['theme'] == 'light' ? 'selected' : '' ?>>Light</option>
                                    <option value="dark" <?= $user_settings['theme'] == 'dark' ? 'selected' : '' ?>>Dark</option>
                                    <option value="nerv" <?= $user_settings['theme'] == 'nerv' ? 'selected' : '' ?>>NERV System</option>
                                </select>
                                <div class="form-text">
                                    <?php if ($user_settings['theme'] === 'nerv'): ?>
                                        <span class="status-indicator" style="background-color: var(--nerv-green);"></span>
                                        MAGI SYSTEM STATUS: OPERATIONAL
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="input_mode" class="form-label">
                                    <i class="bi bi-input-cursor-text"></i> 入力モード
                                </label>
                                <select name="input_mode" id="input_mode" class="form-select">
                                    <option value="modal" <?= $user_settings['input_mode'] == 'modal' ? 'selected' : '' ?>>モーダルウィンドウ</option>
                                    <option value="inline" <?= $user_settings['input_mode'] == 'inline' ? 'selected' : '' ?>>インライン表示</option>
                                </select>
                                <div class="form-text">取引入力フォームの表示方法を選択できます。</div>
                            </div>

                            <div class="mb-3">
                                <label for="currency" class="form-label">
                                    <i class="bi bi-currency-exchange"></i> 通貨
                                </label>
                                <select name="currency" id="currency" class="form-select">
                                    <option value="JPY" <?= $user_settings['currency'] == 'JPY' ? 'selected' : '' ?>>日本円 (¥)</option>
                                    <option value="USD" <?= $user_settings['currency'] == 'USD' ? 'selected' : '' ?>>US Dollar ($)</option>
                                    <option value="EUR" <?= $user_settings['currency'] == 'EUR' ? 'selected' : '' ?>>Euro (€)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="date_format" class="form-label">
                                    <i class="bi bi-calendar3"></i> 日付形式
                                </label>
                                <select name="date_format" id="date_format" class="form-select">
                                    <option value="Y-m-d" <?= $user_settings['date_format'] == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                    <option value="Y/m/d" <?= $user_settings['date_format'] == 'Y/m/d' ? 'selected' : '' ?>>YYYY/MM/DD</option>
                                    <option value="d/m/Y" <?= $user_settings['date_format'] == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="start_of_week" class="form-label">
                                    <i class="bi bi-calendar-week"></i> 週の開始日
                                </label>
                                <select name="start_of_week" id="start_of_week" class="form-select">
                                    <option value="0" <?= $user_settings['start_of_week'] == 0 ? 'selected' : '' ?>>日曜日</option>
                                    <option value="1" <?= $user_settings['start_of_week'] == 1 ? 'selected' : '' ?>>月曜日</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="items_per_page" class="form-label">
                                    <i class="bi bi-list-ol"></i> 1ページあたりの表示件数
                                </label>
                                <select name="items_per_page" id="items_per_page" class="form-select">
                                    <option value="10" <?= $user_settings['items_per_page'] == 10 ? 'selected' : '' ?>>10件</option>
                                    <option value="20" <?= $user_settings['items_per_page'] == 20 ? 'selected' : '' ?>>20件</option>
                                    <option value="50" <?= $user_settings['items_per_page'] == 50 ? 'selected' : '' ?>>50件</option>
                                    <option value="100" <?= $user_settings['items_per_page'] == 100 ? 'selected' : '' ?>>100件</option>
                                </select>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="update_settings" class="btn btn-primary">
                                    <i class="bi bi-save"></i> 設定を保存
                                </button>
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
