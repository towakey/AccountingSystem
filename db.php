<?php
try {
    $pdo = new PDO('sqlite:kakeibo.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "接続失敗: " . $e->getMessage();
    exit;
}
?>