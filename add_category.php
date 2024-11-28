<?php
try {
    $pdo = new PDO('sqlite:kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // categoriesテーブルが存在しない場合は作成
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // カテゴリの存在確認
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = ? AND type = 'expense'");
    $stmt->execute(['引き落とし']);
    $exists = $stmt->fetchColumn();

    if (!$exists) {
        // カテゴリの追加
        $stmt = $pdo->prepare("INSERT INTO categories (name, type) VALUES (?, 'expense')");
        $stmt->execute(['引き落とし']);
        echo "カテゴリ「引き落とし」を追加しました。\n";
    } else {
        echo "カテゴリ「引き落とし」は既に存在します。\n";
    }
} catch (PDOException $e) {
    echo "エラー: " . $e->getMessage() . "\n";
}
?>
