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

check_login();

$user_id = $_SESSION['user_id'];
// ユーザー名を取得
$username = get_username($pdo, $user_id);

// 取引の登録処理
if (isset($_POST['submit'])) {
    try {
        $pdo->beginTransaction();

        // 取引データの登録
        $stmt = $pdo->prepare("
            INSERT INTO kakeibo_data 
            (user_id, date, store_name, price, payment_method_id, note, transaction_type, category) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $_POST['date'],
            $_POST['store_name'],
            $_POST['price'],
            $_POST['payment_method_id'],
            $_POST['note'] ?? '',
            $_POST['transaction_type'],
            $_POST['category'] ?? ''
        ]);
        
        $transaction_id = $pdo->lastInsertId();

        // 店舗名を履歴に保存
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO stores (user_id, store_name) VALUES (?, ?)");
        $stmt->execute([$user_id, $_POST['store_name']]);

        // 取引項目の登録
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $stmt = $pdo->prepare("
                INSERT INTO transaction_items 
                (transaction_id, product_name, price) 
                VALUES (?, ?, ?)
            ");
            
            foreach ($_POST['items'] as $item) {
                if (!empty($item['name']) && isset($item['price'])) {
                    $stmt->execute([
                        $transaction_id,
                        $item['name'],
                        $item['price']
                    ]);
                    
                    // 商品名を履歴に保存
                    $stmt_product = $pdo->prepare("INSERT OR IGNORE INTO products (user_id, product_name) VALUES (?, ?)");
                    $stmt_product->execute([$user_id, $item['name']]);
                }
            }
        }

        $pdo->commit();
        $_SESSION['alert'] = ['type' => 'success', 'message' => '取引を登録しました。'];
        // 選択された月のページにリダイレクト
        $redirect_month = isset($_POST['selected_month']) ? $_POST['selected_month'] : date('Y-m');
        header("Location: " . $_SERVER['PHP_SELF'] . "?month=" . $redirect_month);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("取引の登録に失敗: " . $e->getMessage());
        $_SESSION['alert'] = ['type' => 'danger', 'message' => '取引の登録に失敗しました。'];
    }
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
        $new_theme = in_array($_POST['theme'], ['light', 'dark', 'nerv']) ? $_POST['theme'] : 'light';
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

// 決済手段の取得
try {
    $stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE user_id = ? ORDER BY is_default DESC, name ASC");
    $stmt->execute([$user_id]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>決済手段の取得に失敗しました: " . $e->getMessage() . "</div>";
    $payment_methods = [];
}

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

// 選択された月の収支合計を計算
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$month_start = $selected_month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

// 収入の合計を取得
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(price), 0) as total
    FROM kakeibo_data 
    WHERE user_id = ? 
    AND date BETWEEN ? AND ?
    AND transaction_type = 'income'
");
$stmt->execute([$user_id, $month_start, $month_end]);
$total_income = $stmt->fetchColumn();

// 支出の合計を取得
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(price), 0) as total
    FROM kakeibo_data 
    WHERE user_id = ? 
    AND date BETWEEN ? AND ?
    AND transaction_type = 'expense'
");
$stmt->execute([$user_id, $month_start, $month_end]);
$total_expense = $stmt->fetchColumn();

// 収支の差額を計算
$balance = $total_income - $total_expense;

// カード別集計を取得
$stmt = $pdo->prepare("
    SELECT 
        p.name as payment_method,
        SUM(k.price) as total
    FROM kakeibo_data k
    JOIN payment_methods p ON k.payment_method_id = p.id
    WHERE k.user_id = ? 
    AND k.date BETWEEN ? AND ?
    AND k.transaction_type = 'expense'
    GROUP BY p.name
    ORDER BY total DESC
");
$stmt->execute([$user_id, $month_start, $month_end]);
$payment_totals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// カテゴリー別集計を取得
$stmt = $pdo->prepare("
    SELECT 
        category,
        SUM(price) as total
    FROM kakeibo_data 
    WHERE user_id = ? 
    AND date BETWEEN ? AND ?
    AND transaction_type = 'expense'
    GROUP BY category
    ORDER BY total DESC
");
$stmt->execute([$user_id, $month_start, $month_end]);
$category_totals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// カテゴリー別の配列を準備
$category_labels = [];
$category_data = [];
$category_colors = [
    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
    '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF9F40',
    '#36A2EB', '#FFCE56', '#9966FF'
];

foreach ($category_totals as $index => $total) {
    $category_labels[] = $total['category'];
    $category_data[] = $total['total'];
}

// 過去6ヶ月分のカテゴリ別支出データを取得
$months = [];
$month_data = [];
for ($i = 5; $i >= 0; $i--) {
    $target_month = date('Y-m', strtotime("-$i months", strtotime($selected_month)));
    $months[] = date('n月', strtotime($target_month));
    
    $month_start = $target_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    
    $stmt = $pdo->prepare("
        SELECT 
            category,
            SUM(price) as total
        FROM kakeibo_data 
        WHERE user_id = ? 
        AND date BETWEEN ? AND ?
        AND transaction_type = 'expense'
        GROUP BY category
    ");
    $stmt->execute([$user_id, $month_start, $month_end]);
    $month_totals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($month_totals as $total) {
        if (!isset($month_data[$total['category']])) {
            $month_data[$total['category']] = array_fill(0, 6, 0);
        }
        $month_data[$total['category']][5-$i] = $total['total'];
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
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

// 取引データの総数を取得
$stmt = $pdo->prepare("SELECT COUNT(*) FROM kakeibo_data WHERE user_id = ? AND date BETWEEN ? AND ?");
$stmt->execute([$user_id, $month_start, $month_end]);
$total_items = $stmt->fetchColumn();
$total_pages = ceil($total_items / $items_per_page);

// 取引データを取得
$stmt = $pdo->prepare("
    SELECT 
        k.*,
        p.name as payment_method_name
    FROM kakeibo_data k
    LEFT JOIN payment_methods p ON k.payment_method_id = p.id
    WHERE k.user_id = ? AND k.date BETWEEN ? AND ?
    ORDER BY k.date DESC, k.id DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$user_id, $month_start, $month_end, $items_per_page, $offset]);
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

// APIエンドポイントの処理
if (isset($_GET['api']) && $_GET['api'] === 'get_more_transactions') {
    header('Content-Type: application/json');
    echo json_encode([
        'transactions' => $transactions,
        'hasMore' => ($offset + $items_per_page) < $total_items
    ]);
    exit;
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .alert-dismissible {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            min-width: 250px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
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
                </div>

                <!-- 入力フォーム -->
                <div id="inputForm" class="mb-4" <?= $user_settings['input_mode'] === 'modal' ? 'style="display: none;"' : '' ?>>
                    <form method="post" class="needs-validation" novalidate>
                        <input type="hidden" name="selected_month" value="<?php echo $selected_month; ?>">
                        <div class="mb-3">
                            <label for="date" class="form-label">日付</label>
                            <input type="date" name="date" id="date" class="form-control" required value="<?php echo $selected_month . '-01'; ?>">
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
                        <div class="mb-3">
                            <label for="category" class="form-label">カテゴリー</label>
                            <select name="category" id="category" class="form-select" required>
                                <?php foreach (TRANSACTION_CATEGORIES as $category): ?>
                                    <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
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
                                            <input type="number" name="items[0][price]" class="form-control item-price" placeholder="金額" oninput="updateTotalPrice('inputForm')">
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
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h6 class="card-subtitle mb-2 text-muted">収入</h6>
                                        <p class="card-text text-success h4">
                                            +<?php echo number_format($total_income); ?>円
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h6 class="card-subtitle mb-2 text-muted">支出</h6>
                                        <p class="card-text text-danger h4">
                                            -<?php echo number_format($total_expense); ?>円
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <h6 class="card-subtitle mb-2 text-muted">収支</h6>
                                        <p class="card-text h4 <?php echo $balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo ($balance >= 0 ? '+' : '-') . number_format(abs($balance)); ?>円
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($payment_totals)): ?>
                        <div class="mt-4">
                            <h6 class="mb-3">決済方法別支出</h6>
                            <?php foreach ($payment_totals as $payment): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="payment-name"><?php echo htmlspecialchars($payment['payment_method']); ?></span>
                                <span class="text-danger">-<?php echo number_format($payment['total']); ?>円</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">決済方法別支出</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="paymentChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">カテゴリー別支出</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">過去6ヶ月のカテゴリ別支出</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="past6MonthsCategoryChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 取引履歴 -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title h5 mb-0">取引履歴</h2>
                <div class="d-flex gap-2 align-items-center">
                    <button class="btn btn-outline-secondary btn-sm" onclick="changeMonth(-1)">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <span id="currentMonth" class="fw-bold">
                        <?php echo date('Y年n月', strtotime($selected_month)); ?>
                    </span>
                    <button class="btn btn-outline-secondary btn-sm" onclick="changeMonth(1)">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>日付</th>
                            <th>取引先</th>
                            <th>カテゴリー</th>
                            <th>決済方法</th>
                            <th class="text-end">金額</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody">
                        <?php foreach ($transactions as $data): ?>
                            <tr>
                                <td><?= date('Y/m/d', strtotime($data['date'])) ?></td>
                                <td><?= htmlspecialchars($data['store_name']) ?></td>
                                <td><?= htmlspecialchars($data['category']) ?></td>
                                <td><?= htmlspecialchars($data['payment_method_name']) ?></td>
                                <td class="text-end">
                                    <?php 
                                    $price = $data['price'];
                                    $is_invalid = !is_numeric($price) || $price === '';
                                    ?>
                                    <?php if ($is_invalid): ?>
                                        <i class="bi bi-exclamation-triangle-fill text-warning" 
                                           data-bs-toggle="tooltip" 
                                           data-bs-placement="top" 
                                           title="金額データに問題があります"></i>
                                    <?php endif; ?>
                                    <?= $data['transaction_type'] === 'income' ? '+' : '-' ?><?= number_format(floatval($price)) ?>円
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="editTransaction(<?php echo $data['id']; ?>)">
                                        <i class="bi bi-pencil"></i> 編集
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="deleteTransaction(<?php echo $data['id']; ?>)">
                                        <i class="bi bi-trash"></i> 削除
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (($page * $items_per_page) < $total_items): ?>
                <div class="text-center p-3">
                    <button type="button" class="btn btn-outline-primary" onclick="loadMoreTransactions()">
                        さらに読み込む
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
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
    <div class="modal fade" id="editTransactionModal" tabindex="-1" aria-labelledby="editTransactionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editTransactionModalLabel">取引を編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editTransactionForm" onsubmit="return false;">
                        <input type="hidden" name="selected_month" value="<?php echo $selected_month; ?>">
                        <input type="hidden" name="id">
                        <div class="mb-3">
                            <label for="edit_date" class="form-label">日付</label>
                            <input type="date" name="date" id="edit_date" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">取引種別</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="transaction_type" id="edit_transaction_type_expense" value="expense">
                                <label class="btn btn-outline-danger" for="edit_transaction_type_expense">
                                    <i class="bi bi-dash-circle"></i> 出金
                                </label>
                                <input type="radio" class="btn-check" name="transaction_type" id="edit_transaction_type_income" value="income">
                                <label class="btn btn-outline-success" for="edit_transaction_type_income">
                                    <i class="bi bi-plus-circle"></i> 入金
                                </label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_store_name" class="form-label">取引先</label>
                            <input type="text" name="store_name" id="edit_store_name" class="form-control" list="stores" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_category" class="form-label">カテゴリー</label>
                            <select name="category" id="edit_category" class="form-select" required>
                                <?php foreach (TRANSACTION_CATEGORIES as $category): ?>
                                    <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_payment_method" class="form-label">決済方法</label>
                            <select name="payment_method_id" id="edit_payment_method" class="form-select" required>
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
                            <label class="form-label">取引項目</label>
                            <div id="edit_items_container">
                                <!-- 取引項目がここに動的に追加されます -->
                            </div>
                            <div class="text-end mb-2">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addEditItem()">
                                    <i class="bi bi-plus-circle"></i> 項目を追加
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_price" class="form-label">合計金額</label>
                            <input type="number" name="price" id="edit_price" class="form-control" required>
                            <div class="form-text">項目を入力すると自動計算されます</div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_note" class="form-label">備考（任意）</label>
                            <input type="text" name="note" id="edit_note" class="form-control">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="button" class="btn btn-primary" onclick="submitEditForm()">更新</button>
                </div>
            </div>
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

    <!-- 取引追加モーダル -->
    <div class="modal fade" id="addTransactionModal" tabindex="-1" aria-labelledby="addTransactionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addTransactionModalLabel">新しい取引を追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" class="needs-validation" novalidate>
                    <input type="hidden" name="selected_month" value="<?php echo $selected_month; ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="modal_date" class="form-label">日付</label>
                            <input type="date" name="date" id="modal_date" class="form-control" required value="<?php echo $selected_month . '-01'; ?>">
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
                        <div class="mb-3">
                            <label for="modal_category" class="form-label">カテゴリー</label>
                            <select name="category" id="modal_category" class="form-select" required>
                                <?php foreach (TRANSACTION_CATEGORIES as $category): ?>
                                    <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
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
                                            <input type="number" name="items[0][price]" class="form-control item-price" placeholder="金額" oninput="updateTotalPrice('addTransactionModal')">
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
                                <button type="button" class="btn btn-outline-primary btn-sm" id="modal_add_item" data-modal-id="addTransactionModal">
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
    <!-- 決済方法管理モーダル -->
    <div class="modal fade" id="paymentMethodModal" tabindex="-1" aria-labelledby="paymentMethodModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="paymentMethodModalLabel">決済方法の管理</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary" onclick="addPaymentMethod()">
                            <i class="bi bi-plus-circle"></i> 新規追加
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>決済方法</th>
                                    <th>種類</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="paymentMethodsList">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 決済方法追加モーダル -->
    <div class="modal fade" id="addPaymentMethodModal" tabindex="-1" aria-labelledby="addPaymentMethodModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addPaymentMethodModalLabel">決済方法の追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form onsubmit="event.preventDefault(); submitPaymentMethod(this);">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="payment_method_name" class="form-label">決済方法名</label>
                            <input type="text" class="form-control" id="payment_method_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="payment_method_type" class="form-label">種類</label>
                            <select class="form-select" id="payment_method_type" name="type" required>
                                <option value="">選択してください</option>
                                <option value="cash">現金</option>
                                <option value="credit">クレジットカード</option>
                                <option value="debit">デビットカード</option>
                                <option value="prepaid">プリペイド</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> 追加
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>

<script>
    // DOMContentLoadedイベントで全ての初期化を行う
    document.addEventListener('DOMContentLoaded', function() {
        // グローバル変数の初期化
        let settingsModalInstance;
        let editModalInstance;
        let currentPage = 1;
        const itemsPerPage = <?php echo $items_per_page; ?>;
        let isLoading = false;

        // モーダルの初期化
        const editModal = document.getElementById('editTransactionModal');
        if (editModal) {
            editModalInstance = new bootstrap.Modal(editModal);
        }

        // 設定モーダルの初期化
        const settingsModal = document.getElementById('settingsModal');
        if (settingsModal) {
            settingsModalInstance = new bootstrap.Modal(settingsModal);
        }

        // 設定ボタンのイベントリスナー
        const settingsButton = document.querySelector('.settings-button');
        if (settingsButton) {
            settingsButton.addEventListener('click', function() {
                if (settingsModalInstance) {
                    settingsModalInstance.show();
                }
            });
        }

        // 決済方法のリストを読み込む
        loadPaymentMethods();

        // 項目追加ボタンの設定
        const addItemButton = document.getElementById('modal_add_item');
        if (addItemButton) {
            addItemButton.addEventListener('click', function() {
                const container = document.getElementById('modal_items_container');
                if (!container) return;
                
                const itemCount = container.getElementsByClassName('item-row').length;
                const newItem = document.createElement('div');
                newItem.className = 'item-row mb-2';
                newItem.innerHTML = `
                    <div class="row">
                        <div class="col-md-6">
                            <input type="text" name="items[${itemCount}][name]" class="form-control item-name" list="product_list" placeholder="項目名">
                        </div>
                        <div class="col-md-4">
                            <input type="number" name="items[${itemCount}][price]" class="form-control item-price" placeholder="金額" oninput="updateTotalPrice('inputForm')">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-outline-danger btn-remove-item" style="display: none;">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
                container.appendChild(newItem);
            });
        }

        // 削除ボタンの初期設定
        document.querySelectorAll('.btn-remove-item').forEach(button => {
            button.addEventListener('click', function() {
                this.closest('.item-row').remove();
                updateTotalPrice('addTransactionModal');
            });
        });
    });

    // 設定モーダルを表示する関数
    function showSettings() {
        if (settingsModalInstance) {
            settingsModalInstance.show();
        }
    }

    // 月を変更する関数
    function changeMonth(diff) {
        const urlParams = new URLSearchParams(window.location.search);
        let currentMonth = urlParams.get('month') || '<?php echo date('Y-m'); ?>';
        
        // 月を変更
        let date = new Date(currentMonth + '-01');
        date.setMonth(date.getMonth() + diff);
        
        // 新しい月を YYYY-MM 形式で取得
        const newMonth = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
        
        // URLを更新して画面をリロード
        urlParams.set('month', newMonth);
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    };

    async function deleteTransaction(id) {
        if (!id) {
            console.error('取引IDが指定されていません');
            return;
        }

        if (!confirm('この取引を削除してもよろしいですか？')) {
            return;
        }

        try {
            console.log('削除開始 - 取引ID:', id);
            const response = await fetch(`api/transaction.php?id=${id}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            console.log('APIレスポンス状態:', response.status, response.statusText);
            const responseText = await response.text();
            console.log('APIレスポンス本文:', responseText);

            let result;
            try {
                result = JSON.parse(responseText);
                console.log('パース済みレスポンス:', result);
            } catch (e) {
                console.error('JSONパースエラー:', e);
                throw new Error('サーバーからの応答を解析できませんでした');
            }

            if (!response.ok) {
                throw new Error(`APIエラー: ${response.status} ${response.statusText}`);
            }

            if (result.success) {
                // 成功メッセージを表示
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                alertDiv.style.zIndex = '9999';
                alertDiv.innerHTML = `
                    <i class="bi bi-check-circle-fill"></i>
                    ${result.message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                document.body.appendChild(alertDiv);

                // 2秒後にページをリロード
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                throw new Error(result.message || '削除に失敗しました');
            }
        } catch (error) {
            console.error('エラー詳細:', error);
            alert('削除に失敗しました: ' + error.message);
        }
    }

    async function editTransaction(id) {
        try {
            console.log('編集開始 - 取引ID:', id);

            // 取引データを取得
            const response = await fetch(`api/transaction.php?id=${id}`);
            const result = await response.json();
            
            console.log('APIレスポンス:', result);
            
            if (!result.success) {
                throw new Error(result.message);
            }

            const data = result.data;
            console.log('取得したデータ:', data);

            // フォームに値を設定
            const form = document.getElementById('editTransactionForm');
            
            // hidden input
            form.querySelector('[name="id"]').value = data.id;
            console.log('ID設定:', data.id);
            
            // 日付
            form.querySelector('[name="date"]').value = data.date;
            console.log('日付設定:', data.date);
            
            // 取引種別
            const typeRadio = form.querySelector(`input[name="transaction_type"][value="${data.transaction_type}"]`);
            if (typeRadio) {
                typeRadio.checked = true;
                console.log('取引種別設定:', data.transaction_type);
            }
            
            // 取引先
            form.querySelector('[name="store_name"]').value = data.store_name || '';
            console.log('取引先設定:', data.store_name);
            
            // カテゴリー
            const categorySelect = form.querySelector(`select[name="category"]`);
            if (categorySelect) {
                categorySelect.value = data.category || '';
                console.log('カテゴリー設定:', data.category);
            }
            
            // 決済方法
            form.querySelector('[name="payment_method_id"]').value = data.payment_method_id;
            console.log('決済方法設定:', data.payment_method_id);
            
            // 金額
            form.querySelector('[name="price"]').value = data.price;
            console.log('金額設定:', data.price);
            
            // 備考
            form.querySelector('[name="note"]').value = data.note || '';
            console.log('備考設定:', data.note);

            // 取引項目を設定
            const itemsContainer = document.getElementById('edit_items_container');
            itemsContainer.innerHTML = '';
            
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const itemHtml = `
                        <div class="mb-2 item-row">
                            <div class="row">
                                <div class="col-7">
                                    <input type="text" class="form-control item-name" value="${item.product_name}" required>
                                </div>
                                <div class="col-4">
                                    <input type="number" class="form-control item-price" value="${item.price}" required oninput="updateTotalPrice('editTransactionForm')">
                                </div>
                                <div class="col-1">
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.item-row').remove(); updateTotalPrice('editTransactionForm')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    `;
                    itemsContainer.insertAdjacentHTML('beforeend', itemHtml);
                });
                console.log('取引項目設定完了');
            }

            // モーダルを表示
            const modal = bootstrap.Modal.getInstance(document.getElementById('editTransactionModal'));
            modal.show();
            console.log('モーダル表示完了');
        } catch (error) {
            console.error('編集エラー:', error);
            alert('取引データの取得に失敗しました: ' + error.message);
        }
    }

    // フォーム送信処理
    async function submitEditForm() {
        const form = document.getElementById('editTransactionForm');
        if (!form) {
            console.error('編集フォームが見つかりません');
            return;
        }
        await updateTransaction(form);
    }

    // 取引の更新
    async function updateTransaction(form) {
        try {
            // フォームデータを収集
            const items = [];
            form.querySelectorAll('.item-row').forEach(row => {
                items.push({
                    product_name: row.querySelector('.item-name').value,
                    price: parseInt(row.querySelector('.item-price').value)
                });
            });

            // デバッグ用の値の確認
            const formData = {
                id: form.querySelector('[name="id"]').value,
                date: form.querySelector('[name="date"]').value,
                transaction_type: form.querySelector('input[name="transaction_type"]:checked')?.value,
                store_name: form.querySelector('[name="store_name"]').value,
                category: form.querySelector('select[name="category"]').value,
                payment_method_id: form.querySelector('[name="payment_method_id"]').value,
                price: parseInt(form.querySelector('[name="price"]').value),
                note: form.querySelector('[name="note"]').value,
                items: items
            };

            console.log('送信前のデータ:', formData);

            // 必須項目のチェック
            if (!formData.id) throw new Error('取引IDが見つかりません');
            if (!formData.date) throw new Error('日付を入力してください');
            if (!formData.transaction_type) throw new Error('取引種別を選択してください');
            if (!formData.store_name) throw new Error('取引先を入力してください');
            if (!formData.category) throw new Error('カテゴリーを選択してください');
            if (!formData.payment_method_id) throw new Error('決済方法を選択してください');
            if (!formData.price) throw new Error('金額を入力してください');

            // APIを呼び出して更新
            const response = await fetch('api/transaction.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });

            let result;
            try {
                result = await response.json();
                console.log('APIレスポンス:', result);
            } catch (e) {
                console.error('JSONパースエラー:', e);
                throw new Error('サーバーからの応答を解析できませんでした');
            }

            if (!response.ok) {
                throw new Error(`APIエラー: ${response.status} ${response.statusText}`);
            }

            if (result.success) {
                // 成功メッセージを表示
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show';
                alertDiv.innerHTML = `
                    <i class="bi bi-check-circle-fill"></i>
                    取引を更新しました
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                document.querySelector('.container').insertBefore(alertDiv, document.querySelector('.container').firstChild);

                // モーダルを閉じる
                const modal = bootstrap.Modal.getInstance(document.getElementById('editTransactionModal'));
                modal.hide();

                // 1秒後にページをリロード
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                throw new Error(result.message || '更新に失敗しました');
            }
        } catch (error) {
            console.error('エラー詳細:', error);
            alert('更新に失敗しました: ' + error.message);
        }
    }

    // 項目の追加（編集モーダル用）
    function addEditItem() {
        const container = document.getElementById('edit_items_container');
        const itemHtml = `
            <div class="mb-2 item-row">
                <div class="row">
                    <div class="col-7">
                        <input type="text" class="form-control item-name" required>
                    </div>
                    <div class="col-4">
                        <input type="number" class="form-control item-price" required oninput="updateTotalPrice('editTransactionForm')">
                    </div>
                    <div class="col-1">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.item-row').remove(); updateTotalPrice('editTransactionForm')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', itemHtml);
    }

    // 合計金額の更新
    function updateTotalPrice(formId) {
        const form = document.getElementById(formId);
        if (!form) return;

        let total = 0;
        form.querySelectorAll('.item-price').forEach(input => {
            const price = parseInt(input.value) || 0;
            total += price;
        });

        const priceInput = form.querySelector('[name="price"]');
        if (priceInput) {
            priceInput.value = total;
        }
    }

    let currentPage = 1;
    const itemsPerPage = <?php echo $items_per_page; ?>;
    let isLoading = false;

    async function loadMoreTransactions() {
        if (isLoading) return;
        isLoading = true;
        
        const nextPage = currentPage + 1;
        const selectedMonth = document.getElementById('currentMonth').textContent.trim()
            .replace('年', '-').replace('月', '')
            .split('-')
            .map(num => num.padStart(2, '0'))
            .join('-');

        try {
            const response = await fetch(`index.php?api=get_more_transactions&page=${nextPage}&limit=${itemsPerPage}&month=${selectedMonth}`);
            const data = await response.json();

            if (data.transactions && data.transactions.length > 0) {
                const tbody = document.getElementById('transactionsTableBody');
                
                data.transactions.forEach(transaction => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${formatDate(transaction.date)}</td>
                        <td>${escapeHtml(transaction.store_name)}</td>
                        <td>${escapeHtml(transaction.category)}</td>
                        <td>${escapeHtml(transaction.payment_method_name)}</td>
                        <td class="text-end">
                            ${transaction.transaction_type === 'income' ? '+' : '-'}${numberFormat(transaction.price)}円
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-primary" onclick="editTransaction(${transaction.id})">
                                <i class="bi bi-pencil"></i> 編集
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="deleteTransaction(${transaction.id})">
                                <i class="bi bi-trash"></i> 削除
                            </button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });

                currentPage = nextPage;

                // もっと読み込むボタンの表示/非表示
                const loadMoreButton = document.querySelector('.btn-outline-primary');
                if (!data.hasMore && loadMoreButton) {
                    loadMoreButton.parentElement.remove();
                }
            }
        } catch (error) {
            console.error('取引データの読み込みに失敗:', error);
            alert('データの読み込みに失敗しました。');
        } finally {
            isLoading = false;
        }
    }

    // ユーティリティ関数
    function formatDate(dateStr) {
        const date = new Date(dateStr);
        return `${date.getFullYear()}/${String(date.getMonth() + 1).padStart(2, '0')}/${String(date.getDate()).padStart(2, '0')}`;
    }

    function numberFormat(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // ツールチップの初期化
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // 取引種類の変更時に決済手段を更新する関数
        function updatePaymentMethod(transactionType, formElement) {
            const paymentMethodSelect = formElement.querySelector('select[name="payment_method_id"]');
            if (!paymentMethodSelect) return;

            if (transactionType === 'income') {
                // 入金時は振り込みと現金のみ表示
                paymentMethodSelect.innerHTML = `
                    <option value="1">振り込み</option>
                    <option value="2">現金</option>
                `;
            } else {
                // 支出時は全ての決済方法を表示（引き落としがデフォルト）
                paymentMethodSelect.innerHTML = `
                    <?php
                    // 支出用の決済方法リストを取得
                    $stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE user_id = ? ORDER BY is_default DESC, name ASC");
                    $stmt->execute([$user_id]);
                    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($payment_methods as $method): ?>
                        <option value="<?= $method['id'] ?>" <?= $method['is_default'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($method['name']) ?>
                            <?php if ($method['withdrawal_day']): ?>
                                (引落: <?= $method['withdrawal_day'] ?>日)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                `;
            }
        }

        // ラジオボタンの変更イベントを監視
        document.querySelectorAll('input[type="radio"][name="transaction_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const form = this.closest('form');
                updatePaymentMethod(this.value, form);
            });
        });

        // 初期表示時の設定
        document.querySelectorAll('form').forEach(form => {
            const checkedRadio = form.querySelector('input[type="radio"][name="transaction_type"]:checked');
            if (checkedRadio) {
                updatePaymentMethod(checkedRadio.value, form);
            }
        });

        // 編集モーダルでの取引種類変更時の処理
        const editTransactionTypeRadios = document.querySelectorAll('#editTransactionModal input[name="transaction_type"]');
        editTransactionTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const form = document.getElementById('editTransactionForm');
                updatePaymentMethod(this.value, form);
            });
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // 決済方法別グラフの設定
        const paymentCtx = document.getElementById('paymentChart').getContext('2d');
        new Chart(paymentCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($payment_totals, 'payment_method')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($payment_totals, 'total')); ?>,
                    backgroundColor: <?php echo json_encode(array_slice($category_colors, 0, count($payment_totals))); ?>
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${context.label}: ¥${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        // カテゴリー別グラフの設定
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($category_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($category_data); ?>,
                    backgroundColor: <?php echo json_encode(array_slice($category_colors, 0, count($category_totals))); ?>
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${context.label}: ¥${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        // 過去6ヶ月のカテゴリ別グラフの設定
        const past6MonthsCategoryCtx = document.getElementById('past6MonthsCategoryChart').getContext('2d');
        const categoryDatasets = [];
        <?php 
        $colorIndex = 0;
        foreach ($month_data as $category => $values) {
            echo "categoryDatasets.push({
                label: " . json_encode($category) . ",
                data: " . json_encode($values) . ",
                backgroundColor: " . json_encode($category_colors[$colorIndex % count($category_colors)]) . ",
                borderColor: " . json_encode($category_colors[$colorIndex % count($category_colors)]) . ",
                borderWidth: 1
            });\n";
            $colorIndex++;
        }
        ?>
        new Chart(past6MonthsCategoryCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($months); ?>,
                datasets: categoryDatasets
            },
            options: {
                responsive: true,
                scales: {
                    x: {
                        stacked: true
                    },
                    y: {
                        stacked: true,
                        ticks: {
                            callback: function(value) {
                                return '¥' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ¥${context.raw.toLocaleString()}`;
                            }
                        }
                    }
                }
            }
        });
    });
</script>

<script src="assets/js/payment_methods.js"></script>
