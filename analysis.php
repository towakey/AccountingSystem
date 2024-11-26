<?php
require_once 'auth_check.php';
require_once 'db.php';
require_once 'functions.php';

// 支払方法の取得
$stmt = $pdo->prepare("
    SELECT id, name 
    FROM payment_methods 
    WHERE user_id = ? 
    ORDER BY id ASC
");
$stmt->execute([$_SESSION['user_id']]);
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 支払方法ごとの色を定義
$colors = [
    'rgb(255, 159, 64)',   // オレンジ
    'rgb(75, 192, 192)',   // ティール
    'rgb(153, 102, 255)',  // 紫
    'rgb(255, 205, 86)',   // 黄色
    'rgb(54, 162, 235)',   // 青
    'rgb(255, 99, 132)',   // 赤
    'rgb(201, 203, 207)'   // グレー
];

// 過去12ヶ月分の月を生成
$months = array();
$month_data = array();
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $months[] = date('Y年n月', strtotime($month));
    $month_data[$month] = array(
        'total_expense' => 0,
        'total_income' => 0,
        'payment_methods' => array()
    );
    // 支払方法ごとの初期値を設定
    foreach ($payment_methods as $method) {
        $month_data[$month]['payment_methods'][$method['id']] = 0;
    }
}

// 収支データの取得
$stmt = $pdo->prepare("
    SELECT 
        strftime('%Y-%m', date) as month,
        SUM(CASE WHEN transaction_type = 'expense' THEN price ELSE 0 END) as total_expense,
        SUM(CASE WHEN transaction_type = 'income' THEN price ELSE 0 END) as total_income,
        payment_method_id,
        SUM(CASE WHEN transaction_type = 'expense' THEN price ELSE 0 END) as method_expense
    FROM kakeibo_data 
    WHERE user_id = ? 
    AND date >= date('now', '-11 months', 'start of month')
    GROUP BY strftime('%Y-%m', date), payment_method_id
    ORDER BY month ASC, payment_method_id ASC
");
$stmt->execute([$_SESSION['user_id']]);
$monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 取得したデータを配列にマージ
foreach ($monthly_data as $data) {
    if (isset($month_data[$data['month']])) {
        // 支払方法別の支出を加算
        if ($data['payment_method_id'] !== null) {
            $month_data[$data['month']]['payment_methods'][$data['payment_method_id']] = (int)$data['method_expense'];
        }
        // 合計金額を更新（payment_method_idがnullのデータも含める）
        $month_data[$data['month']]['total_expense'] += (int)$data['total_expense'];
        $month_data[$data['month']]['total_income'] += (int)$data['total_income'];
    }
}

// グラフ用のデータを準備
$expenses = array();
$incomes = array();
$payment_method_expenses = array();

// 支払方法ごとの配列を初期化
foreach ($payment_methods as $index => $method) {
    $payment_method_expenses[$method['id']] = array(
        'id' => $method['id'],
        'name' => $method['name'],
        'color' => $colors[$index % count($colors)],
        'data' => array()
    );
}

// データを配列に格納
foreach ($month_data as $data) {
    $expenses[] = $data['total_expense'];
    $incomes[] = $data['total_income'];
    
    // 支払方法ごとのデータを格納
    foreach ($payment_methods as $method) {
        $payment_method_expenses[$method['id']]['data'][] = $data['payment_methods'][$method['id']];
    }
}

// JavaScriptで使用するデータを準備
$chartData = [
    'months' => $months,
    'incomes' => $incomes,
    'expenses' => $expenses,
    'methodDatasets' => array_map(function($method) {
        return [
            'label' => $method['name'],
            'data' => $method['data'],
            'borderColor' => $method['color'],
            'tension' => 0.1,
            'fill' => false,
            'borderDash' => [5, 5],
            'methodId' => $method['id']
        ];
    }, array_values($payment_method_expenses))
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>収支分析 - 家計簿システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <h2>月次収支推移</h2>
        
        <div class="card mb-4">
            <div class="card-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-0">収支グラフ</h5>
                    </div>
                    <div class="col-auto">
                        <div class="btn-group" role="group">
                            <?php foreach ($payment_methods as $method): ?>
                            <input type="checkbox" class="btn-check" id="btn-<?php echo htmlspecialchars($method['id']); ?>" checked autocomplete="off" onchange="togglePaymentMethod(<?php echo $method['id']; ?>)">
                            <label class="btn btn-outline-secondary btn-sm" for="btn-<?php echo htmlspecialchars($method['id']); ?>"><?php echo htmlspecialchars($method['name']); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>月</th>
                        <th class="text-end">収入</th>
                        <th class="text-end">支出</th>
                        <?php foreach ($payment_methods as $method): ?>
                        <th class="text-end"><?php echo htmlspecialchars($method['name']); ?></th>
                        <?php endforeach; ?>
                        <th class="text-end">収支</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $i = 0;
                    foreach ($month_data as $data): 
                    ?>
                        <tr>
                            <td><?php echo $months[$i]; ?></td>
                            <td class="text-end"><?php echo number_format($data['total_income']); ?>円</td>
                            <td class="text-end"><?php echo number_format($data['total_expense']); ?>円</td>
                            <?php foreach ($payment_methods as $method): ?>
                            <td class="text-end"><?php echo number_format($data['payment_methods'][$method['id']]); ?>円</td>
                            <?php endforeach; ?>
                            <td class="text-end">
                                <?php 
                                $balance = $data['total_income'] - $data['total_expense'];
                                $color = $balance >= 0 ? 'text-success' : 'text-danger';
                                echo '<span class="' . $color . '">' . number_format($balance) . '円</span>';
                                ?>
                            </td>
                        </tr>
                    <?php 
                    $i++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let myChart = null;
        const chartData = <?php echo json_encode($chartData); ?>;

        // 支払方法の表示/非表示を切り替える関数
        function togglePaymentMethod(methodId) {
            if (myChart) {
                const methodDataset = myChart.data.datasets.find(dataset => dataset.methodId === methodId);
                if (methodDataset) {
                    methodDataset.hidden = !methodDataset.hidden;
                    myChart.update();
                }
            }
        }

        // グラフの初期化
        window.addEventListener('load', function() {
            const ctx = document.getElementById('monthlyChart').getContext('2d');
            
            // 基本のデータセット
            const datasets = [
                {
                    label: '収入',
                    data: chartData.incomes,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1,
                    fill: false
                },
                {
                    label: '支出（合計）',
                    data: chartData.expenses,
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1,
                    fill: false
                },
                ...chartData.methodDatasets
            ];

            myChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData.months,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString() + '円';
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + '円';
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
