<?php
require_once __DIR__ . '/../db.php';

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/../kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // トランザクション開始
    $pdo->beginTransaction();

    // 現在のテーブル構造を表示
    echo "現在のテーブル構造:\n";
    $stmt = $pdo->query("PRAGMA table_info(config)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        echo "{$column['name']} ({$column['type']})\n";
    }

    // 一時テーブルの作成
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            key TEXT NOT NULL,
            value TEXT,
            user_id INTEGER,
            UNIQUE(key, user_id)
        )
    ");

    // 既存のデータを新しいテーブルに移行
    $pdo->exec("
        INSERT OR IGNORE INTO config_new (key, value, user_id)
        SELECT 'items_per_page', '10', id
        FROM users
    ");

    // 古いconfigテーブルを削除
    $pdo->exec("DROP TABLE IF EXISTS config");

    // 新しいテーブルの名前を変更
    $pdo->exec("ALTER TABLE config_new RENAME TO config");

    echo "\n新しいテーブル構造:\n";
    $stmt = $pdo->query("PRAGMA table_info(config)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        echo "{$column['name']} ({$column['type']})\n";
    }

    // トランザクションをコミット
    $pdo->commit();
    echo "\nマイグレーションが完了しました。\n";

} catch (PDOException $e) {
    // エラーが発生した場合はロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("エラーが発生しました: " . $e->getMessage() . "\n");
}
