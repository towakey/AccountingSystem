<?php
// セッション開始（セッションが開始されていない場合のみ）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ログインチェック関数
function check_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// ユーザーのテーマを取得する関数
function get_user_theme($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT bs_theme FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $theme = $stmt->fetch(PDO::FETCH_ASSOC);
    return $theme ? $theme['bs_theme'] : 'default';
}

// システム固有のIDを生成する関数
function generateUniqueId() {
    return uniqid('user_', true);
}

// ユーザー名を取得する関数
function get_username($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ? $user['username'] : '';
}

// ユーザー設定を取得
function get_user_settings($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        // デフォルト設定を返す
        return [
            'input_mode' => 'modal',
            'theme' => 'light',
            'currency' => 'JPY',
            'date_format' => 'Y-m-d',
            'start_of_week' => 0,
            'items_per_page' => 20
        ];
    }
    
    return $settings;
}

// 日付をフォーマット
function format_date($date, $format = null) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        return date('Y-m-d', strtotime($date));
    }
    
    if ($format === null) {
        $settings = get_user_settings($pdo, $_SESSION['user_id']);
        $format = $settings['date_format'];
    }
    
    return date($format, strtotime($date));
}

// 金額をフォーマット
function format_currency($amount, $currency = null) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        return '¥' . number_format($amount);
    }
    
    if ($currency === null) {
        $settings = get_user_settings($pdo, $_SESSION['user_id']);
        $currency = $settings['currency'];
    }
    
    switch ($currency) {
        case 'USD':
            return '$' . number_format($amount, 2);
        case 'EUR':
            return '€' . number_format($amount, 2);
        case 'JPY':
        default:
            return '¥' . number_format($amount);
    }
}
?>