<?php
require_once __DIR__ . '/../db.php';

try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/../kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // トランザクション開始
    $pdo->beginTransaction();

    // 現在のテーブル構造を確認
    $stmt = $pdo->query("PRAGMA table_info(config)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // items_per_pageカラムの存在確認
    $column_exists = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'items_per_page') {
            $column_exists = true;
            break;
        }
    }

    // カラムが存在しない場合のみ追加
    if (!$column_exists) {
        // SQLiteは直接ALTER TABLE ADD COLUMNをサポートしているので、それを使用
        $pdo->exec("ALTER TABLE config ADD COLUMN items_per_page INTEGER DEFAULT 10");
        echo "items_per_pageカラムを追加しました。\n";
    } else {
        echo "items_per_pageカラムは既に存在します。\n";
    }

    // トランザクションをコミット
    $pdo->commit();
    echo "マイグレーションが完了しました。\n";

} catch (PDOException $e) {
    // エラーが発生した場合はロールバック
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("エラーが発生しました: " . $e->getMessage() . "\n");
}
