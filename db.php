<?php
$db_path = str_replace('\\', '/', dirname(__FILE__)) . '/kakeibo.db';
try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // カテゴリーカラムの追加
    try {
        $pdo->exec("
            ALTER TABLE kakeibo_data ADD COLUMN category TEXT DEFAULT '雑費';
        ");
        
        // 既存のデータにデフォルトカテゴリーを設定
        $pdo->exec("
            UPDATE kakeibo_data 
            SET category = '雑費' 
            WHERE category IS NULL;
        ");
    } catch (PDOException $e) {
        // ALTER TABLE が失敗した場合（カラムが既に存在する場合）はエラーを無視
        if (!strpos($e->getMessage(), 'duplicate column name')) {
            throw $e;
        }
    }

    // カテゴリーの定数を定義
    define('TRANSACTION_CATEGORIES', [
        '雑費',
        '食費',
        '電気代',
        'ガス代',
        '水道代',
        '通信費',
        '交際費',
        '家賃',
        '医療費',
        '保険料',
        '日用品費',
        '被服費',
        '交通費'
    ]);

} catch (PDOException $e) {
    echo "接続失敗: " . $e->getMessage();
    exit;
}
?>