<?php
require_once 'db.php';
require_once 'functions.php';

check_login();

$user_id = $_SESSION['user_id'];
$username = get_username($pdo, $user_id);
$user_theme = get_user_theme($pdo, $user_id);

// 決済手段の追加/編集処理
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_method'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO payment_methods (user_id, name, type, withdrawal_day) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                $_POST['name'],
                $_POST['type'],
                !empty($_POST['withdrawal_day']) ? $_POST['withdrawal_day'] : null
            ]);
            $_SESSION['alert'] = ['type' => 'success', 'message' => '決済手段を追加しました'];
        } catch (PDOException $e) {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => '決済手段の追加に失敗しました: ' . $e->getMessage()];
        }
    } elseif (isset($_POST['delete_method'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ? AND user_id = ? AND is_default = 0");
            $stmt->execute([$_POST['method_id'], $user_id]);
            if ($stmt->rowCount() > 0) {
                $_SESSION['alert'] = ['type' => 'success', 'message' => '決済手段を削除しました'];
            } else {
                $_SESSION['alert'] = ['type' => 'warning', 'message' => 'デフォルトの決済手段は削除できません'];
            }
        } catch (PDOException $e) {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => '決済手段の削除に失敗しました: ' . $e->getMessage()];
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// 決済手段一覧の取得
try {
    $stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE user_id = ? ORDER BY is_default DESC, name ASC");
    $stmt->execute([$user_id]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['alert'] = ['type' => 'danger', 'message' => 'データ取得エラー: ' . $e->getMessage()];
    $payment_methods = [];
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>決済手段管理 - 家計簿アプリ</title>
    <?php if ($user_theme === 'darkly'): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.1/dist/darkly/bootstrap.min.css" rel="stylesheet">
    <?php elseif ($user_theme === 'light'): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php elseif ($user_theme === 'dark'): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.1/dist/darkly/bootstrap.min.css" rel="stylesheet">
    <?php else: ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
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
<body>
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
                            <a class="nav-link active" aria-current="page" href="payment_methods.php">決済手段</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="settings.php">設定</a>
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
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title h5 mb-0">
                            <i class="bi bi-credit-card"></i> 決済手段の追加
                        </h2>
                    </div>
                    <div class="card-body">
                        <form method="post" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label for="name" class="form-label">決済手段名</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            <div class="mb-3">
                                <label for="type" class="form-label">種類</label>
                                <select class="form-select" id="type" name="type" required>
                                    <option value="credit">クレジットカード</option>
                                    <option value="debit">デビットカード</option>
                                    <option value="prepaid">チャージ式</option>
                                    <option value="cash">現金</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="withdrawal_day" class="form-label">引き落とし日（任意）</label>
                                <input type="number" class="form-control" id="withdrawal_day" name="withdrawal_day" min="1" max="31">
                                <div class="form-text">クレジットカードの場合は引き落とし日を設定できます</div>
                            </div>
                            <div class="d-grid">
                                <button type="submit" name="add_method" class="btn btn-primary">
                                    <i class="bi bi-plus-circle"></i> 追加
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title h5 mb-0">
                            <i class="bi bi-list"></i> 登録済み決済手段
                        </h2>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>決済手段名</th>
                                    <th>種類</th>
                                    <th>引き落とし日</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_methods as $method): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($method['name']) ?>
                                            <?php if ($method['is_default']): ?>
                                                <span class="badge bg-primary">デフォルト</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $type_labels = [
                                                'cash' => '現金',
                                                'credit' => 'クレジットカード',
                                                'debit' => 'デビットカード',
                                                'prepaid' => 'チャージ式'
                                            ];
                                            echo htmlspecialchars($type_labels[$method['type']]);
                                            ?>
                                        </td>
                                        <td>
                                            <?= $method['withdrawal_day'] ? htmlspecialchars($method['withdrawal_day']) . '日' : '-' ?>
                                        </td>
                                        <td>
                                            <?php if (!$method['is_default']): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="method_id" value="<?= $method['id'] ?>">
                                                    <button type="submit" name="delete_method" class="btn btn-sm btn-outline-danger" onclick="return confirm('この決済手段を削除してもよろしいですか？')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // アラートの自動非表示
        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.getElementById('autoAlert');
            if (alert) {
                setTimeout(function() {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 3000);
            }
        });

        // 入力フォームのバリデーション
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms)
                .forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        form.classList.add('was-validated')
                    }, false)
                })
        })()

        // 種類が変更されたときの引き落とし日の有効/無効切り替え
        document.getElementById('type').addEventListener('change', function() {
            const withdrawalDay = document.getElementById('withdrawal_day');
            if (this.value === 'credit') {
                withdrawalDay.removeAttribute('disabled');
            } else {
                withdrawalDay.setAttribute('disabled', 'disabled');
                withdrawalDay.value = '';
            }
        });
    </script>
</body>
</html>
