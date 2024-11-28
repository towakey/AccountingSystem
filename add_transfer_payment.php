<?php
session_start();
try {
    $pdo = new PDO('sqlite:kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ユーザーIDを取得（デフォルトユーザーを1とします）
    $user_id = 1;

    // 決済方法の存在確認
    $stmt = $pdo->prepare("SELECT id FROM payment_methods WHERE name = ? AND user_id = ?");
    $stmt->execute(['振り込み', $user_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        // 振り込み決済方法を追加
        $stmt = $pdo->prepare("INSERT INTO payment_methods (user_id, name, type, withdrawal_day, is_default) VALUES (?, ?, ?, NULL, 0)");
        $stmt->execute([$user_id, '振り込み', 'bank']);
        echo "決済方法「振り込み」を追加しました。\n";
    } else {
        echo "決済方法「振り込み」は既に存在します。\n";
    }
} catch (PDOException $e) {
    echo "エラー: " . $e->getMessage() . "\n";
}
?>
