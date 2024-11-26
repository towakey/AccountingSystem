<?php
require_once 'auth_check.php';
require_once 'db.php';
require_once 'functions.php';

// 過去12ヶ月分の月を生成
$months = array();
$month_data = array();
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $months[] = date('Y年n月', strtotime($month));
    $month_data[$month] = array(
        'total_expense' => 0,
        'total_income' => 0
    );
}

// データを取得
$stmt = $pdo->prepare("
    SELECT 
        strftime('%Y-%m', date) as month,
        SUM(CASE WHEN transaction_type = 'expense' THEN price ELSE 0 END) as total_expense,
        SUM(CASE WHEN transaction_type = 'income' THEN price ELSE 0 END) as total_income
    FROM kakeibo_data 
    WHERE user_id = ? 
    AND date >= date('now', '-11 months', 'start of month')
    GROUP BY strftime('%Y-%m', date)
    ORDER BY month ASC
");
$stmt->execute([$_SESSION['user_id']]);
$monthly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 取得したデータを配列にマージ
foreach ($monthly_data as $data) {
    if (isset($month_data[$data['month']])) {
        $month_data[$data['month']] = array(
            'total_expense' => (int)$data['total_expense'],
            'total_income' => (int)$data['total_income']
        );
    }
}

// グラフ用のデータを準備
$expenses = array();
$incomes = array();
foreach ($month_data as $data) {
    $expenses[] = $data['total_expense'];
    $incomes[] = $data['total_income'];
}
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
        const ctx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($months); ?>,
                datasets: [
                    {
                        label: '収入',
                        data: <?php echo json_encode($incomes); ?>,
                        borderColor: 'rgb(75, 192, 192)',
                        tension: 0.1,
                        fill: false
                    },
                    {
                        label: '支出',
                        data: <?php echo json_encode($expenses); ?>,
                        borderColor: 'rgb(255, 99, 132)',
                        tension: 0.1,
                        fill: false
                    }
                ]
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
    </script>
</body>
</html>
