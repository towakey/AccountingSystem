<?php
session_start();
try {
    $pdo = new PDO('sqlite:kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ユーザーIDを取得（デフォルトユーザーを1とします）
    $user_id = 1;

    // 既存の全ての決済方法のデフォルト設定を解除
    $stmt = $pdo->prepare("UPDATE payment_methods SET is_default = 0 WHERE user_id = ?");
    $stmt->execute([$user_id]);

    // 決済方法の存在確認
    $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE name = ? AND user_id = ?");
    $stmt->execute(['引き落とし', $user_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // 既存の引き落とし設定を更新
        $stmt = $pdo->prepare("UPDATE payment_methods SET is_default = 1, withdrawal_day = NULL WHERE id = ?");
        $stmt->execute([$existing['id']]);
        echo "決済方法「引き落とし」をデフォルトに設定し、引き落とし日を削除しました。\n";
    } else {
        // 新しい決済方法を追加
        $stmt = $pdo->prepare("INSERT INTO payment_methods (user_id, name, type, withdrawal_day, is_default) VALUES (?, ?, ?, NULL, 1)");
        $stmt->execute([$user_id, '引き落とし', 'bank']);
        echo "決済方法「引き落とし」を追加し、デフォルトに設定しました。\n";
    }
} catch (PDOException $e) {
    echo "エラー: " . $e->getMessage() . "\n";
}
?>
