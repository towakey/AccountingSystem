<?php
session_start();
require_once 'db.php';

// 確認画面の表示
if (!isset($_POST['confirm'])) {
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>システムリセット</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-danger text-white">
                            <h3 class="text-center mb-0">⚠️ システムリセット</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <strong>警告:</strong> この操作は以下の項目を削除します：
                                <ul class="mb-0">
                                    <li>すべてのセッション情報</li>
                                    <li>システム設定（configテーブル）</li>
                                    <li>ユーザーデータ</li>
                                    <li>取引履歴データ</li>
                                    <li>店舗・商品データ</li>
                                    <li>ユーザー設定</li>
                                    <li>決済手段データ</li>
                                </ul>
                            </div>
                            <form method="POST">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="confirm" name="confirm" required>
                                    <label class="form-check-label" for="confirm">
                                        上記の内容を理解し、システムをリセットすることに同意します。
                                    </label>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-danger">システムをリセット</button>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="index.php" class="btn btn-link">キャンセル</a>
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
    <?php
    exit;
}

try {
    // データベース接続
    $db = new PDO('sqlite:kakeibo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 既存のすべてのテーブルを取得
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

    // 外部キー制約を一時的に無効化
    $db->exec('PRAGMA foreign_keys = OFF');

    // すべてのテーブルを削除
    foreach ($tables as $table) {
        $db->exec("DROP TABLE IF EXISTS $table");
    }

    // 外部キー制約を再度有効化
    $db->exec('PRAGMA foreign_keys = ON');

    // データベース接続を明示的に閉じる
    $db = null;

    // データベースファイルを削除
    if (file_exists('kakeibo.db')) {
        // ファイルが使用中でないことを確認
        if (is_writable('kakeibo.db')) {
            @unlink('kakeibo.db');
        }
    }

    // セッションの破棄
    session_destroy();

    // 成功メッセージの表示
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>リセット完了</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container mt-5">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h3 class="text-center mb-0">リセット完了</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-success">
                                システムが正常にリセットされました。
                            </div>
                            <div class="text-center">
                                <a href="setup.php" class="btn btn-primary">セットアップ画面へ</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php

} catch (PDOException $e) {
    die("リセット中にエラーが発生しました: " . $e->getMessage());
}
?>
