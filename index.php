<?php
session_start();
require_once 'functions.php';
require_once 'db.php';

try {
    $pdo = new PDO('sqlite:kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!table_exists($pdo, 'config')) {
        header('Location: setup.php');
        exit;
    }
} catch (PDOException $e) {
    header('Location: setup.php');
    exit;
}

// 項目名の履歴を取得
$stmt = $pdo->prepare("
    SELECT DISTINCT product_name 
    FROM products 
    WHERE user_id = ? 
    ORDER BY last_used DESC 
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$product_history = $stmt->fetchAll(PDO::FETCH_COLUMN);

check_login();

$user_id = $_SESSION['user_id'];

// ユーザー名を取得
$username = get_username($pdo, $user_id);

// テーマを取得
$user_theme = get_user_theme($pdo, $user_id);

// 決済手段の取得
try {
    $stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE user_id = ? ORDER BY is_default DESC, name ASC");
    $stmt->execute([$user_id]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>決済手段の取得に失敗しました: " . $e->getMessage() . "</div>";
    $payment_methods = [];
}

// ユーザー設定の取得
try {
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_settings) {
        // 設定が存在しない場合は作成
        $stmt = $pdo->prepare("INSERT INTO user_settings (user_id, input_mode, theme) VALUES (?, 'modal', 'light')");
        $stmt->execute([$_SESSION['user_id']]);
        $user_settings = ['input_mode' => 'modal', 'theme' => 'light'];
    }
} catch (PDOException $e) {
    error_log("設定の取得に失敗: " . $e->getMessage());
    $user_settings = ['input_mode' => 'modal', 'theme' => 'light']; // デフォルト値
}

// 設定の更新処理
if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    try {
        $new_mode = $_POST['input_mode'] === 'inline' ? 'inline' : 'modal';
        $new_theme = $_POST['theme'] === 'nerv' ? 'nerv' : 'light';
        $stmt = $pdo->prepare("UPDATE user_settings SET input_mode = ?, theme = ? WHERE user_id = ?");
        $stmt->execute([$new_mode, $new_theme, $_SESSION['user_id']]);
        $user_settings['input_mode'] = $new_mode;
        $user_settings['theme'] = $new_theme;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (PDOException $e) {
        error_log("設定の更新に失敗: " . $e->getMessage());
    }
}

// 入力処理
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit'])) {
    $date = $_POST['date'];
    $transaction_type = $_POST['transaction_type'];
    $store_name = $_POST['store_name'];
    $payment_method_id = $_POST['payment_method_id'];
    $note = !empty($_POST['note']) ? $_POST['note'] : null;
    $total_price = 0;

    // 項目が入力されている場合は合計を計算、そうでない場合は入力された金額を使用
    if (!empty($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $item) {
            if (!empty($item['price'])) {
                $total_price += intval($item['price']);
            }
        }
    } else {
        $total_price = intval($_POST['price']);
    }

    try {
        $pdo->beginTransaction();

        // メインの取引を登録
        $stmt = $pdo->prepare("INSERT INTO kakeibo_data (user_id, date, transaction_type, store_name, price, payment_method_id, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $date, $transaction_type, $store_name, $total_price, $payment_method_id, $note]);
        $transaction_id = $pdo->lastInsertId();

        // 項目の登録
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            $stmt = $pdo->prepare("INSERT INTO transaction_items (transaction_id, product_name, price) VALUES (?, ?, ?)");
            $product_stmt = $pdo->prepare("INSERT OR REPLACE INTO products (user_id, product_name, last_used) VALUES (?, ?, CURRENT_TIMESTAMP)");
            
            foreach ($_POST['items'] as $item) {
                if (!empty($item['name']) && !empty($item['price'])) {
                    $stmt->execute([$transaction_id, $item['name'], $item['price']]);
                    $product_stmt->execute([$user_id, $item['name']]);
                }
            }
        }

        // 店舗の履歴を保存
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO stores (user_id, store_name) VALUES (?, ?)");
        $stmt->execute([$user_id, $store_name]);

        $pdo->commit();
        $_SESSION['alert'] = [
            'type' => 'success',
            'message' => '登録しました'
        ];
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['alert'] = [
            'type' => 'danger',
            'message' => '登録エラー: ' . $e->getMessage()
        ];
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    try {
        $transaction_id = $_POST['transaction_id'];
        $date = $_POST['date'];
        $transaction_type = $_POST['edit_transaction_type'];
        $store_name = $_POST['store_name'];
        $payment_method_id = $_POST['payment_method_id'];
        $note = $_POST['note'];

        // 項目の合計金額を計算
        $total_price = 0;
        if (isset($_POST['edit_items']['price'])) {
            foreach ($_POST['edit_items']['price'] as $price) {
                if (!empty($price)) {
                    $total_price += intval($price);
                }
            }
        }

        $pdo->beginTransaction();

        // メイン取引データの更新
        $stmt = $pdo->prepare("UPDATE kakeibo_data SET date = ?, transaction_type = ?, store_name = ?, price = ?, payment_method_id = ?, note = ? WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$date, $transaction_type, $store_name, $total_price, $payment_method_id, $note, $transaction_id, $user_id]);

        if ($result) {
            // 既存の項目を削除
            $stmt = $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id = ?");
            $stmt->execute([$transaction_id]);

            // 新しい項目を追加
            if (isset($_POST['edit_items']['name']) && isset($_POST['edit_items']['price'])) {
                $stmt = $pdo->prepare("INSERT INTO transaction_items (transaction_id, product_name, price) VALUES (?, ?, ?)");
                
                for ($i = 0; $i < count($_POST['edit_items']['name']); $i++) {
                    $item_name = $_POST['edit_items']['name'][$i];
                    $item_price = $_POST['edit_items']['price'][$i];
                    
                    if (!empty($item_name) && !empty($item_price)) {
                        $stmt->execute([$transaction_id, $item_name, $item_price]);
                        $product_stmt = $pdo->prepare("INSERT OR REPLACE INTO products (user_id, product_name, last_used) VALUES (?, ?, CURRENT_TIMESTAMP)");
                        $product_stmt->execute([$user_id, $item_name]);
                    }
                }
            }

            $pdo->commit();
            $_SESSION['success_message'] = "取引を更新しました。";
        } else {
            throw new Exception("更新に失敗しました。");
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "エラーが発生しました: " . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'delete_transaction':
            try {
                $pdo->beginTransaction();
                
                // 取引に関連する項目を削除
                $stmt = $pdo->prepare("DELETE FROM transaction_items WHERE transaction_id = ?");
                $stmt->execute([$_POST['transaction_id']]);
                
                // 取引を削除
                $stmt = $pdo->prepare("DELETE FROM kakeibo_data WHERE id = ? AND user_id = ?");
                $stmt->execute([$_POST['transaction_id'], $_SESSION['user_id']]);
                
                $pdo->commit();
                $_SESSION['alert'] = ['type' => 'success', 'message' => '取引を削除しました。'];
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['alert'] = ['type' => 'danger', 'message' => 'エラーが発生しました: ' . $e->getMessage()];
            }
            break;

        case 'add_transaction':
            // 処理は上記の入力処理で実行される
            break;
    }
}

// ページネーションの設定を取得
$stmt = $pdo->prepare("
    SELECT value 
    FROM config 
    WHERE user_id = ? AND key = 'items_per_page'
");
$stmt->execute([$user_id]);
$items_per_page = $stmt->fetchColumn() ?: 10; // デフォルトは10件

// 現在のページを取得
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// 取引データの総数を取得
$stmt = $pdo->prepare("SELECT COUNT(*) FROM kakeibo_data WHERE user_id = ?");
$stmt->execute([$user_id]);
$total_items = $stmt->fetchColumn();
$total_pages = ceil($total_items / $items_per_page);

// 取引データを取得
$stmt = $pdo->prepare("
    SELECT 
        k.*,
        p.name as payment_method_name
    FROM kakeibo_data k
    LEFT JOIN payment_methods p ON k.payment_method_id = p.id
    WHERE k.user_id = ? 
    ORDER BY k.date DESC, k.id DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$user_id, $items_per_page, $offset]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 各取引の項目を取得
$items_stmt = $pdo->prepare("
    SELECT id, product_name, price
    FROM transaction_items
    WHERE transaction_id = ?
");

foreach ($transactions as &$transaction) {
    $items_stmt->execute([$transaction['id']]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    $transaction['items_json'] = json_encode($items);
}
unset($transaction); // 参照を解除

// 店舗の履歴を取得
try {
    $stmt = $pdo->prepare("SELECT store_name FROM stores WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stores = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $stores = [];
}

// 商品履歴取得
try {
    $stmt = $pdo->prepare("SELECT product_name FROM products WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $products = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $products = [];
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>家計簿システム</title>
    <?php if ($user_settings['theme'] === 'nerv'): ?>
        <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="css/nerv-theme.css" rel="stylesheet">
    <?php elseif ($user_settings['theme'] === 'dark'): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.1/dist/darkly/bootstrap.min.css" rel="stylesheet">
    <?php else: ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: sans-serif;
        }
        .alert-dismissible {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            min-width: 250px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .chart-container {
            position: relative;
            margin: auto;
            height: 250px;
        }
    </style>
</head>
<body class="<?= $user_settings['theme'] === 'nerv' ? 'nerv-theme' : '' ?>">
    <?php if (isset($_SESSION['alert'])): ?>
        <div class="alert alert-<?= $_SESSION['alert']['type'] ?> alert-dismissible fade show" role="alert" id="autoAlert">
            <?php if ($_SESSION['alert']['type'] === 'success'): ?>
                <i class="bi bi-check-circle-fill"></i>
            <?php else: ?>
                <i class="bi bi-exclamation-triangle-fill"></i>
            <?php endif; ?>
            <?= htmlspecialchars($_SESSION['alert']['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <?php include 'navbar.php'; ?>
    <div class="container">
        <div class="row">
            <div class="col-md-6">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>収支の入力</h2>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#settingsModal">
                            <i class="bi bi-gear"></i> 設定
                        </button>
                    </div>
                </div>

                <!-- 設定モーダル -->
                <div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="settingsModalLabel">表示設定</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update_settings">
                                    <div class="mb-3">
                                        <label class="form-label">入力方式</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="input_mode" id="modalMode" 
                                                value="modal" <?= $user_settings['input_mode'] === 'modal' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="modalMode">
                                                ボタンクリックでモーダル表示
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="input_mode" id="inlineMode" 
                                                value="inline" <?= $user_settings['input_mode'] === 'inline' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="inlineMode">
                                                常に表示
                                            </label>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">テーマ</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="theme" id="lightTheme" 
                                                value="light" <?= $user_settings['theme'] === 'light' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="lightTheme">
                                                ライトテーマ
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="theme" id="darkTheme" 
                                                value="dark" <?= $user_settings['theme'] === 'dark' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="darkTheme">
                                                ダークテーマ
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="theme" id="nervTheme" 
                                                value="nerv" <?= $user_settings['theme'] === 'nerv' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="nervTheme">
                                                NERVテーマ
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                                    <button type="submit" class="btn btn-primary">保存</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- 入力フォーム -->
                <div id="inputForm" class="mb-4" <?= $user_settings['input_mode'] === 'modal' ? 'style="display: none;"' : '' ?>>
                    <form method="post" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label for="date" class="form-label">日付</label>
                            <input type="date" name="date" id="date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">取引種別</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="transaction_type" id="expense" value="expense" checked>
                                <label class="btn btn-outline-danger" for="expense">
                                    <i class="bi bi-dash-circle"></i> 出金
                                </label>
                                <input type="radio" class="btn-check" name="transaction_type" id="income" value="income">
                                <label class="btn btn-outline-success" for="income">
                                    <i class="bi bi-plus-circle"></i> 入金
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="store_name" class="form-label">取引先</label>
                            <input type="text" name="store_name" id="store_name" class="form-control" list="store_list" required>
                            <datalist id="store_list">
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?= htmlspecialchars($store) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="mb-3 payment-method-field">
                            <label for="payment_method_id" class="form-label">決済手段</label>
                            <select name="payment_method_id" id="payment_method_id" class="form-select" required>
                                <?php foreach ($payment_methods as $method): ?>
                                    <option value="<?= $method['id'] ?>" <?= $method['is_default'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($method['name']) ?>
                                        <?php if ($method['withdrawal_day']): ?>
                                            (引落: <?= $method['withdrawal_day'] ?>日)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-end">
                                <a href="payment_methods.php" class="text-decoration-none">
                                    <i class="bi bi-gear"></i> 決済手段を管理
                                </a>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">項目</label>
                            <div id="items-container">
                                <div class="item-row mb-2">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <input type="text" name="items[0][name]" class="form-control item-name" list="product_list" placeholder="項目名">
                                        </div>
                                        <div class="col-md-4">
                                            <input type="number" name="items[0][price]" class="form-control item-price" placeholder="金額">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="button" class="btn btn-outline-danger btn-remove-item" style="display: none;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end mb-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="add-item">
                                    <i class="bi bi-plus-circle"></i> 項目を追加
                                </button>
                            </div>
                            <datalist id="product_list">
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= htmlspecialchars($product) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="mb-3">
                            <label for="price" class="form-label">合計金額</label>
                            <input type="number" name="price" id="price" class="form-control" required>
                            <div class="form-text">項目を入力すると自動計算されます</div>
                        </div>
                        <div class="mb-3">
                            <label for="note" class="form-label">備考（任意）</label>
                            <input type="text" name="note" id="note" class="form-control">
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> 登録
                            </button>
                        </div>
                    </form>
                </div>

                <!-- モーダル表示用のボタン -->
                <?php if ($user_settings['input_mode'] === 'modal'): ?>
                <div class="text-center mb-4">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                        <i class="bi bi-plus-circle"></i> 新しい取引を追加
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h2 class="card-title h5 mb-0">今月の収支</h2>
                    </div>
                    <div class="card-body">
                        <?php
                        // 今月の収支を計算
                        $current_month = date('Y-m');
                        $monthly_income = 0;
                        $monthly_expense = 0;
                        $daily_data = [];
                        
                        // 今月の日付範囲を取得
                        $start_date = $current_month . '-01';
                        $end_date = date('Y-m-t'); // 月末日
                        
                        // 日々のデータを初期化
                        $current = new DateTime($start_date);
                        $end = new DateTime($end_date);
                        while ($current <= $end) {
                            $daily_data[$current->format('Y-m-d')] = [
                                'income' => 0,
                                'expense' => 0
                            ];
                            $current->modify('+1 day');
                        }

                        foreach ($transactions as $data) {
                            if (substr($data['date'], 0, 7) === $current_month) {
                                if ($data['transaction_type'] === 'income') {
                                    $monthly_income += $data['price'];
                                    $daily_data[$data['date']]['income'] += $data['price'];
                                } else {
                                    $monthly_expense += $data['price'];
                                    $daily_data[$data['date']]['expense'] += $data['price'];
                                }
                            }
                        }
                        $monthly_balance = $monthly_income - $monthly_expense;
                        ?>
                        <div class="row text-center mb-4">
                            <div class="col-4">
                                <h5 class="data-header">収入</h5>
                                <p class="h4 income-amount"><?= number_format($monthly_income) ?>円</p>
                            </div>
                            <div class="col-4">
                                <h5 class="data-header">支出</h5>
                                <p class="h4 expense-amount"><?= number_format($monthly_expense) ?>円</p>
                            </div>
                            <div class="col-4">
                                <h5 class="data-header">収支</h5>
                                <p class="h4 balance-amount <?= $monthly_balance >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($monthly_balance) ?>円</p>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <canvas id="pieChart"></canvas>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <canvas id="barChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h2 class="card-title h5 mb-0">取引履歴</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>日付</th>
                            <th>種別</th>
                            <th>取引先</th>
                            <th>項目</th>
                            <th class="text-end">金額</th>
                            <th>決済手段</th>
                            <th>備考</th>
                            <th>編集</th>
                            <th>削除</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $data): ?>
                            <tr>
                                <td><?= htmlspecialchars($data['date']) ?></td>
                                <td>
                                    <?php if ($data['transaction_type'] === 'income'): ?>
                                        <span class="badge bg-success">入金</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">出金</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($data['store_name']) ?></td>
                                <td>
                                    <?php if ($data['items_json'] && $data['items_json'] !== '[]'): ?>
                                        <?php 
                                        $items_array = explode(',', $data['items_json']);
                                        $items = array_map(function($item) {
                                            return json_decode($item, true);
                                        }, $items_array);
                                        $items = array_filter($items); // null値を除去
                                        
                                        if (!empty($items)): 
                                            echo '<ul class="list-unstyled mb-0">';
                                            foreach ($items as $item) {
                                                if (isset($item['name']) && isset($item['price'])) {
                                                    echo '<li>' . htmlspecialchars($item['name']) . ' (' . number_format($item['price']) . '円)</li>';
                                                }
                                            }
                                            echo '</ul>';
                                        endif;
                                        ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-end <?= $data['transaction_type'] === 'income' ? 'text-success' : 'text-danger' ?>">
                                    <?= $data['transaction_type'] === 'income' ? '+' : '-' ?><?= number_format($data['price']) ?>円
                                </td>
                                <td><?= htmlspecialchars($data['payment_method_name']) ?></td>
                                <td><?= $data['note'] ? htmlspecialchars($data['note']) : '-' ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary edit-transaction" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editTransactionModal"
                                            data-id="<?= $data['id'] ?>"
                                            data-date="<?= $data['date'] ?>"
                                            data-type="<?= $data['transaction_type'] ?>"
                                            data-store="<?= htmlspecialchars($data['store_name']) ?>"
                                            data-price="<?= $data['price'] ?>"
                                            data-payment="<?= $data['payment_method_id'] ?>"
                                            data-note="<?= htmlspecialchars($data['note']) ?>"
                                            data-items='<?= htmlspecialchars($data['items_json']) ?>'>
                                        編集
                                    </button>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-danger delete-transaction" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#deleteConfirmModal"
                                            data-id="<?= $data['id'] ?>">
                                        削除
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ページネーション -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="ページナビゲーション" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($current_page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $current_page - 1; ?>" aria-label="前">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($current_page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?php echo $current_page + 1; ?>" aria-label="次">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>

    <!-- 削除確認モーダル -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">取引の削除</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>この取引を削除してもよろしいですか？</p>
                    <p class="text-danger">この操作は取り消せません。</p>
                </div>
                <div class="modal-footer">
                    <form method="post">
                        <input type="hidden" name="action" value="delete_transaction">
                        <input type="hidden" name="transaction_id" id="delete_transaction_id">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-danger">削除</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- 編集モーダル -->
    <div class="modal fade" id="editTransactionModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="editTransactionModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editTransactionModalLabel">取引を編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editTransactionForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="transaction_id" id="edit_transaction_id">
                        
                        <div class="mb-3">
                            <label for="edit_date" class="form-label">日付</label>
                            <input type="date" class="form-control" id="edit_date" name="date" required>
                        </div>

                        <div class="mb-3">
                            <div class="btn-group w-100" role="group" aria-label="取引種類">
                                <input type="radio" class="btn-check" name="edit_transaction_type" id="edit_expense" value="expense">
                                <label class="btn btn-outline-danger" for="edit_expense">支出</label>
                                
                                <input type="radio" class="btn-check" name="edit_transaction_type" id="edit_income" value="income">
                                <label class="btn btn-outline-success" for="edit_income">収入</label>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="edit_store_name" class="form-label">店舗名</label>
                            <input type="text" class="form-control" id="edit_store_name" name="store_name" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_payment_method" class="form-label">支払方法</label>
                            <select class="form-select" id="edit_payment_method" name="payment_method_id" required>
                                <?php foreach ($payment_methods as $method): ?>
                                    <option value="<?= $method['id'] ?>"><?= htmlspecialchars($method['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">項目</label>
                            <div id="editItemsContainer" role="group" aria-label="取引項目リスト">
                                <!-- 項目がここに動的に追加されます -->
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="addEditItemBtn">
                                <i class="bi bi-plus"></i> 項目を追加
                            </button>
                        </div>

                        <div class="mb-3">
                            <label for="edit_price" class="form-label">合計金額</label>
                            <input type="number" class="form-control" id="edit_price" name="price" readonly>
                        </div>

                        <div class="mb-3">
                            <label for="edit_note" class="form-label">メモ</label>
                            <textarea class="form-control" id="edit_note" name="note" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">更新</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 編集モーダルの初期化
            const editModal = document.getElementById('editTransactionModal');
            const editModalBS = new bootstrap.Modal(editModal, {
                backdrop: 'static',
                keyboard: false
            });

            // フォームの初期化
            const editForm = document.getElementById('editTransactionForm');
            const editItemsContainer = document.getElementById('editItemsContainer');
            const editPrice = document.getElementById('edit_price');
            const editPaymentMethod = document.getElementById('edit_payment_method');
            const editDate = document.getElementById('edit_date');

            // 合計金額の計算
            function calculateEditTotal() {
                if (!editItemsContainer || !editPrice) return;
                
                const priceInputs = editItemsContainer.querySelectorAll('.item-price');
                const total = Array.from(priceInputs).reduce((sum, input) => {
                    return sum + (parseFloat(input.value) || 0);
                }, 0);
                
                editPrice.value = total;
            }

            // 項目行の作成
            function createEditItemRow(name = '', price = '', itemId = '') {
                const row = document.createElement('div');
                row.className = 'edit-item-row d-flex gap-2 mb-2';
                
                const nameInput = document.createElement('input');
                nameInput.type = 'text';
                nameInput.className = 'form-control item-name';
                nameInput.name = 'edit_items[name][]';
                nameInput.value = name;
                nameInput.placeholder = '項目名';
                nameInput.required = true;
                nameInput.setAttribute('list', 'product_list');
                
                const priceInput = document.createElement('input');
                priceInput.type = 'number';
                priceInput.className = 'form-control item-price';
                priceInput.name = 'edit_items[price][]';
                priceInput.value = price;
                priceInput.placeholder = '価格';
                priceInput.required = true;
                priceInput.min = '0';
                
                if (itemId) {
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'edit_items[id][]';
                    idInput.value = itemId;
                    row.appendChild(idInput);
                }
                
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-outline-danger btn-remove-item';
                removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
                
                row.appendChild(nameInput);
                row.appendChild(priceInput);
                row.appendChild(removeBtn);
                
                // 価格入力時に合計を更新
                priceInput.addEventListener('input', calculateEditTotal);
                
                return row;
            }

            // フォームのリセット
            function resetEditForm() {
                if (!editForm || !editItemsContainer) return;

                // フォームのリセット
                editForm.reset();

                // 項目のクリア
                editItemsContainer.innerHTML = '';
                const emptyRow = createEditItemRow();
                editItemsContainer.appendChild(emptyRow);

                // 日付を今日の日付に
                if (editDate) {
                    editDate.value = new Date().toISOString().split('T')[0];
                }

                // 支払方法をリセット
                if (editPaymentMethod) {
                    editPaymentMethod.selectedIndex = 0;
                }

                // 合計金額をリセット
                if (editPrice) {
                    editPrice.value = '';
                }
            }

            // モーダルイベントの設定
            if (editModal) {
                editModal.addEventListener('hidden.bs.modal', resetEditForm);
                
                // キャンセルボタンのクリックイベント
                const cancelBtn = editModal.querySelector('.btn-secondary');
                if (cancelBtn) {
                    cancelBtn.addEventListener('click', () => editModalBS.hide());
                }
            }

            // 編集ボタンのクリックイベント
            document.querySelectorAll('.edit-transaction').forEach(button => {
                button.addEventListener('click', function() {
                    if (!editForm) return;

                    const {id, date, type, store, price, payment, note = '', items = '[]'} = this.dataset;
                    let itemsData = [];

                    try {
                        if (items && items !== '[]') {
                            itemsData = JSON.parse(items);
                        }
                    } catch (e) {
                        console.error('項目データの解析に失敗しました:', e);
                        console.log('解析に失敗したデータ:', items);
                    }

                    // フォームの設定
                    document.getElementById('edit_transaction_id').value = id;
                    if (editDate) editDate.value = date;
                    
                    // 取引種類の設定
                    const incomeRadio = document.getElementById('edit_income');
                    const expenseRadio = document.getElementById('edit_expense');
                    if (incomeRadio && expenseRadio) {
                        if (type === 'income') {
                            incomeRadio.checked = true;
                        } else {
                            expenseRadio.checked = true;
                        }
                    }
                    
                    // その他のフィールドの設定
                    const storeInput = document.getElementById('edit_store_name');
                    if (storeInput) storeInput.value = store;
                    
                    if (editPaymentMethod) editPaymentMethod.value = payment;
                    
                    const noteInput = document.getElementById('edit_note');
                    if (noteInput) noteInput.value = note;
                    
                    if (editPrice) editPrice.value = price;

                    // 項目の設定
                    if (editItemsContainer) {
                        editItemsContainer.innerHTML = '';
                        if (itemsData.length > 0) {
                            itemsData.forEach(item => {
                                const row = createEditItemRow(item.product_name, item.price, item.id);
                                editItemsContainer.appendChild(row);
                            });
                        } else {
                            const row = createEditItemRow('', price);
                            editItemsContainer.appendChild(row);
                        }
                    }

                    // モーダルを表示
                    editModalBS.show();
                });
            });

            // 項目の追加ボタン
            const addEditItemBtn = document.getElementById('addEditItemBtn');
            if (addEditItemBtn && editItemsContainer) {
                addEditItemBtn.addEventListener('click', () => {
                    const row = createEditItemRow();
                    editItemsContainer.appendChild(row);
                    calculateEditTotal();
                });
            }

            // 項目の削除と価格変更イベント
            if (editItemsContainer) {
                // 削除ボタンのクリックイベント
                editItemsContainer.addEventListener('click', e => {
                    const removeBtn = e.target.closest('.btn-remove-item');
                    if (removeBtn) {
                        const row = removeBtn.closest('.edit-item-row');
                        if (row) {
                            row.remove();
                            calculateEditTotal();
                        }
                    }
                });

                // 価格入力イベント
                editItemsContainer.addEventListener('input', e => {
                    if (e.target.matches('.item-price')) {
                        calculateEditTotal();
                    }
                });
            }
        });
    </script>
    <!-- 取引追加モーダル -->
<div class="modal fade" id="addTransactionModal" tabindex="-1" aria-labelledby="addTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addTransactionModalLabel">新しい取引を追加</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" class="needs-validation" novalidate>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="modal_date" class="form-label">日付</label>
                        <input type="date" name="date" id="modal_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">取引種別</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="transaction_type" id="modal_expense" value="expense" checked>
                            <label class="btn btn-outline-danger" for="modal_expense">
                                <i class="bi bi-dash-circle"></i> 出金
                            </label>
                            <input type="radio" class="btn-check" name="transaction_type" id="modal_income" value="income">
                            <label class="btn btn-outline-success" for="modal_income">
                                <i class="bi bi-plus-circle"></i> 入金
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="modal_store_name" class="form-label">取引先</label>
                        <input type="text" name="store_name" id="modal_store_name" class="form-control" list="store_list" required>
                    </div>
                    <div class="mb-3 payment-method-field">
                        <label for="modal_payment_method_id" class="form-label">決済手段</label>
                        <select name="payment_method_id" id="modal_payment_method_id" class="form-select" required>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?= $method['id'] ?>" <?= $method['is_default'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($method['name']) ?>
                                    <?php if ($method['withdrawal_day']): ?>
                                        (引落: <?= $method['withdrawal_day'] ?>日)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">項目</label>
                        <div id="modal_items_container">
                            <div class="item-row mb-2">
                                <div class="row">
                                    <div class="col-md-6">
                                        <input type="text" name="items[0][name]" class="form-control item-name" list="product_list" placeholder="項目名">
                                    </div>
                                    <div class="col-md-4">
                                        <input type="number" name="items[0][price]" class="form-control item-price" placeholder="金額">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-outline-danger btn-remove-item">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="text-end mb-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="modal_add_item">
                                <i class="bi bi-plus-circle"></i> 項目を追加
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="modal_price" class="form-label">合計金額</label>
                        <input type="number" name="price" id="modal_price" class="form-control" required>
                        <div class="form-text">項目を入力すると自動計算されます</div>
                    </div>
                    <div class="mb-3">
                        <label for="modal_note" class="form-label">備考（任意）</label>
                        <input type="text" name="note" id="modal_note" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" name="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> 登録
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
