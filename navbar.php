<?php
if (!isset($_SESSION)) {
    session_start();
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">家計簿システム</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="analysis.php">
                        <i class="fas fa-chart-line"></i> 分析
                    </a>
                </li>
            </ul>
            <?php if (isset($_SESSION['user_id'])): ?>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            設定
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#settingsModal">入力設定</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#paymentMethodModal">決済方法管理</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-header">データ管理</li>
                            <li class="dropdown-header"><small>CSVファイル</small></li>
                            <li><a class="dropdown-item" href="/AccountingSystem/tools/export_csv.php?type=transactions&range=month">今月の取引をエクスポート</a></li>
                            <li><a class="dropdown-item" href="/AccountingSystem/tools/export_csv.php?type=transactions&range=all">全期間の取引をエクスポート</a></li>
                            <li><a class="dropdown-item" href="/AccountingSystem/tools/export_csv.php?type=payment_methods">決済方法をエクスポート</a></li>
                            <li><a class="dropdown-item" href="/AccountingSystem/tools/import_csv.php">CSVファイルをインポート</a></li>
                            <li class="dropdown-header"><small>JSONファイル</small></li>
                            <li><a class="dropdown-item" href="import.php">JSONファイルをインポート</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="change_password.php">パスワード変更</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">ログアウト</a></li>
                        </ul>
                    </li>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>
