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

// 現在の年を取得（デフォルト）
$current_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$previous_year = $current_year - 1;

// 利用可能な年のリストを取得
$stmt = $pdo->prepare('SELECT DISTINCT strftime("%Y", date) as year FROM kakeibo_data ORDER BY year DESC');
$stmt->execute();
$available_years = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 月次データの取得（現在年）
function getYearlyData($pdo, $year, $payment_methods, $user_id) {
    $months = [];
    $incomes = array_fill(0, 12, 0);
    $expenses = array_fill(0, 12, 0);
    $method_expenses = [];

    // 支払方法ごとの配列を初期化
    foreach ($payment_methods as $method) {
        $method_expenses[$method['id']] = array_fill(0, 12, 0);
    }

    // 月次データの取得
    $stmt = $pdo->prepare('
        SELECT 
            strftime("%m", date) as month,
            transaction_type,
            payment_method_id,
            SUM(price) as total
        FROM kakeibo_data
        WHERE strftime("%Y", date) = :year
        AND user_id = :user_id
        GROUP BY strftime("%m", date), transaction_type, payment_method_id
        ORDER BY month
    ');
    $stmt->execute(['year' => $year, 'user_id' => $user_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // データの整形
    foreach ($results as $row) {
        $month_index = intval($row['month']) - 1;
        if ($row['transaction_type'] === 'income') {
            $incomes[$month_index] = $row['total'];
        } else {
            $expenses[$month_index] += $row['total'];
            if ($row['payment_method_id']) {
                $method_expenses[$row['payment_method_id']][$month_index] = $row['total'];
            }
        }
    }

    // 月名の配列を生成
    for ($i = 1; $i <= 12; $i++) {
        $months[] = $i . '月';
    }

    return [
        'months' => $months,
        'incomes' => $incomes,
        'expenses' => $expenses,
        'method_expenses' => $method_expenses
    ];
}

// 現在年と前年のデータを取得
$current_year_data = getYearlyData($pdo, $current_year, $payment_methods, $_SESSION['user_id']);
$previous_year_data = getYearlyData($pdo, $previous_year, $payment_methods, $_SESSION['user_id']);

// Chart.js用のデータセット作成
$colors = [
    'rgb(255, 99, 132)',   // 赤
    'rgb(54, 162, 235)',   // 青
    'rgb(255, 206, 86)',   // 黄
    'rgb(75, 192, 192)',   // 緑
    'rgb(153, 102, 255)',  // 紫
    'rgb(255, 159, 64)',   // オレンジ
    'rgb(199, 199, 199)',  // グレー
    'rgb(83, 102, 255)',   // 青紫
    'rgb(255, 99, 255)',   // ピンク
];

// 支払方法ごとのデータセット作成
$methodDatasets = [];
foreach ($payment_methods as $index => $method) {
    $color = $colors[$index % count($colors)];
    $methodDatasets[] = [
        'label' => $method['name'],
        'methodId' => $method['id'],
        'data' => $current_year_data['method_expenses'][$method['id']],
        'borderColor' => $color,
        'backgroundColor' => $color,
        'tension' => 0.1,
        'fill' => false,
        'hidden' => false
    ];
}

// 前年の支払方法ごとのデータセット作成
$previousMethodDatasets = [];
foreach ($payment_methods as $index => $method) {
    $color = $colors[$index % count($colors)];
    $previousMethodDatasets[] = [
        'label' => $method['name'] . '（前年）',
        'methodId' => $method['id'],
        'data' => $previous_year_data['method_expenses'][$method['id']],
        'borderColor' => $color,
        'backgroundColor' => $color,
        'tension' => 0.1,
        'fill' => false,
        'hidden' => true,
        'borderDash' => [5, 5]
    ];
}

// チャートデータをJSON形式で準備
$chartData = [
    'months' => $current_year_data['months'],
    'incomes' => $current_year_data['incomes'],
    'expenses' => $current_year_data['expenses'],
    'previousIncomes' => $previous_year_data['incomes'],
    'previousExpenses' => $previous_year_data['expenses'],
    'methodDatasets' => $methodDatasets,
    'previousMethodDatasets' => $previousMethodDatasets
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>月次収支推移</h2>
            <div class="d-flex gap-3">
                <select class="form-select" id="yearSelect" onchange="changeYear(this.value)">
                    <?php foreach ($available_years as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo $year == $current_year ? 'selected' : ''; ?>>
                        <?php echo $year; ?>年
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="compareLastYear" onchange="toggleYearComparison()">
                    <label class="form-check-label" for="compareLastYear">前年比較</label>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
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
            </div>
            
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">支払方法別割合</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="pieChart"></canvas>
                    </div>
                </div>
                
                <!-- 年間サマリーカードを追加 -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">年間サマリー</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th>年間収入：</th>
                                <td class="text-end"><?php echo number_format(array_sum($current_year_data['incomes'])); ?>円</td>
                            </tr>
                            <tr>
                                <th>年間支出：</th>
                                <td class="text-end"><?php echo number_format(array_sum($current_year_data['expenses'])); ?>円</td>
                            </tr>
                            <tr>
                                <th>収支差額：</th>
                                <td class="text-end">
                                    <?php 
                                    $annual_balance = array_sum($current_year_data['incomes']) - array_sum($current_year_data['expenses']);
                                    $color = $annual_balance >= 0 ? 'text-success' : 'text-danger';
                                    echo '<span class="' . $color . '">' . number_format($annual_balance) . '円</span>';
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 月次データテーブル -->
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
                    foreach ($current_year_data['months'] as $month): 
                    ?>
                        <tr>
                            <td><?php echo $month; ?></td>
                            <td class="text-end"><?php echo number_format($current_year_data['incomes'][$i]); ?>円</td>
                            <td class="text-end"><?php echo number_format($current_year_data['expenses'][$i]); ?>円</td>
                            <?php foreach ($payment_methods as $method): ?>
                            <td class="text-end"><?php echo number_format($current_year_data['method_expenses'][$method['id']][$i]); ?>円</td>
                            <?php endforeach; ?>
                            <td class="text-end">
                                <?php 
                                $balance = $current_year_data['incomes'][$i] - $current_year_data['expenses'][$i];
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
        let pieChart = null;
        const chartData = <?php echo json_encode($chartData); ?>;

        // 年の変更
        function changeYear(year) {
            window.location.href = 'analysis.php?year=' + year;
        }

        // 前年比較の切り替え
        function toggleYearComparison() {
            const isComparing = document.getElementById('compareLastYear').checked;
            
            // 前年のデータセットを表示/非表示
            const previousDatasets = [
                myChart.data.datasets.find(d => d.label === '収入（前年）'),
                myChart.data.datasets.find(d => d.label === '支出（合計）（前年）'),
                ...chartData.previousMethodDatasets.map(d => 
                    myChart.data.datasets.find(cd => cd.label === d.label)
                )
            ];

            previousDatasets.forEach(dataset => {
                if (dataset) {
                    dataset.hidden = !isComparing;
                }
            });

            myChart.update();
        }

        // 支払方法の表示/非表示を切り替える関数
        function togglePaymentMethod(methodId) {
            if (myChart) {
                // 現在年のデータセット
                const methodDataset = myChart.data.datasets.find(dataset => 
                    dataset.methodId === methodId && !dataset.label.includes('（前年）')
                );
                if (methodDataset) {
                    methodDataset.hidden = !methodDataset.hidden;
                }

                // 前年のデータセット
                const previousMethodDataset = myChart.data.datasets.find(dataset => 
                    dataset.methodId === methodId && dataset.label.includes('（前年）')
                );
                if (previousMethodDataset && !previousMethodDataset.hidden) {
                    previousMethodDataset.hidden = methodDataset.hidden;
                }

                myChart.update();
            }
        }

        // パイチャートのデータを計算する関数
        function calculatePieChartData() {
            const methodTotals = {};
            let totalExpense = 0;

            // 各支払方法の合計を計算
            chartData.methodDatasets.forEach(method => {
                const sum = method.data.reduce((acc, curr) => acc + curr, 0);
                methodTotals[method.label] = sum;
                totalExpense += sum;
            });

            // パーセンテージを計算
            const labels = [];
            const data = [];
            const backgroundColor = [];

            chartData.methodDatasets.forEach(method => {
                const percentage = (methodTotals[method.label] / totalExpense) * 100;
                if (percentage > 0) {  // 0%の場合は表示しない
                    labels.push(method.label);
                    data.push(percentage);
                    backgroundColor.push(method.borderColor);
                }
            });

            return { labels, data, backgroundColor };
        }

        // グラフの初期化
        window.addEventListener('load', function() {
            const ctx = document.getElementById('monthlyChart').getContext('2d');
            const pieCtx = document.getElementById('pieChart').getContext('2d');
            
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
                    label: '収入（前年）',
                    data: chartData.previousIncomes,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1,
                    fill: false,
                    hidden: true,
                    borderDash: [5, 5]
                },
                {
                    label: '支出（合計）',
                    data: chartData.expenses,
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1,
                    fill: false
                },
                {
                    label: '支出（合計）（前年）',
                    data: chartData.previousExpenses,
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1,
                    fill: false,
                    hidden: true,
                    borderDash: [5, 5]
                },
                ...chartData.methodDatasets,
                ...chartData.previousMethodDatasets
            ];

            // 折れ線グラフの初期化
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

            // パイチャートの初期化
            const pieData = calculatePieChartData();
            pieChart = new Chart(pieCtx, {
                type: 'pie',
                data: {
                    labels: pieData.labels,
                    datasets: [{
                        data: pieData.data,
                        backgroundColor: pieData.backgroundColor
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed.toFixed(1) + '%';
                                }
                            }
                        },
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
