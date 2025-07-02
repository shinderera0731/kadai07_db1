<?php
// 共通設定ファイルを読み込み
include 'config.php';

// データ取得
try {
    // カテゴリ一覧
    $categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    
    // 在庫一覧
    $inventory = $pdo->query("
        SELECT i.*, c.name as category_name 
        FROM inventory i 
        LEFT JOIN categories c ON i.category_id = c.id 
        ORDER BY c.name, i.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // 在庫不足商品
    $low_stock = $pdo->query("
        SELECT i.*, c.name as category_name 
        FROM inventory i 
        LEFT JOIN categories c ON i.category_id = c.id 
        WHERE i.quantity <= i.reorder_level 
        ORDER BY i.quantity ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // 賞味期限間近商品（7日以内）
    $expiring_soon = $pdo->query("
        SELECT i.*, c.name as category_name 
        FROM inventory i 
        LEFT JOIN categories c ON i.category_id = c.id 
        WHERE i.expiry_date IS NOT NULL 
        AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY i.expiry_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // 最近の入出庫履歴
    $recent_movements = $pdo->query("
        SELECT sm.*, i.name as item_name, i.unit
        FROM stock_movements sm 
        JOIN inventory i ON sm.item_id = i.id 
        ORDER BY sm.created_at DESC 
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 統計情報計算
    $total_items = count($inventory);
    $total_value = array_sum(array_map(function($item) { 
        return $item['quantity'] * $item['cost_price']; 
    }, $inventory));
    
    $low_stock_count = count($low_stock);
    $expiring_count = count($expiring_soon);

} catch (PDOException $e) {
    $categories = [];
    $inventory = [];
    $low_stock = [];
    $expiring_soon = [];
    $recent_movements = [];
    $total_items = 0;
    $total_value = 0;
    $low_stock_count = 0;
    $expiring_count = 0;
}

// フィルタリング機能
$filter_category = $_GET['category'] ?? '';
$filter_status = $_GET['status'] ?? '';

if ($filter_category || $filter_status) {
    $filtered_inventory = array_filter($inventory, function($item) use ($filter_category, $filter_status) {
        $category_match = !$filter_category || $item['category_id'] == $filter_category;
        
        $status_match = true;
        if ($filter_status === 'low_stock') {
            $status_match = $item['quantity'] <= $item['reorder_level'];
        } elseif ($filter_status === 'normal') {
            $status_match = $item['quantity'] > $item['reorder_level'];
        } elseif ($filter_status === 'expiring') {
            $status_match = $item['expiry_date'] && strtotime($item['expiry_date']) <= strtotime('+7 days');
        }
        
        return $category_match && $status_match;
    });
} else {
    $filtered_inventory = $inventory;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>在庫管理 - 🏰 Cinderella cafe</title>
    <?php echo getCommonCSS(); ?>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        .filter-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filter-form .form-group {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 10px;
        }
        .quick-actions {
            text-align: center;
            margin-bottom: 30px;
        }
        .tab-buttons {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .tab-button {
            flex: 1;
            padding: 15px;
            background: #e9ecef;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
        }
        .tab-button.active {
            background: #667eea;
            color: white;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-form .form-group {
                display: block;
                margin-right: 0;
            }
            .tab-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏰 Cinderella cafe</h1>
            <p>在庫管理システム</p>
        </div>

        <div class="content">
            <!-- ナビゲーション -->
            <?php echo getNavigation('select'); ?>

            <!-- メッセージ表示 -->
            <?php showMessage(); ?>

            <!-- システム初期化（テーブルが存在しない場合） -->
            <?php if (empty($categories)): ?>
                <div class="card">
                    <h3>🔧 システム初期化が必要です</h3>
                    <p>データベーステーブルが作成されていません。</p>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="index.php" class="btn success">🏠 ホームに戻る</a>
                    </div>
                </div>
            <?php else: ?>

            <!-- アラート表示 -->
            <?php if ($low_stock_count > 0): ?>
                <div class="alert warning">
                    <strong>⚠️ 在庫不足警告:</strong> <?php echo $low_stock_count; ?>件の商品が発注点を下回っています
                    <a href="?status=low_stock" style="margin-left: 10px; color: #856404; text-decoration: underline;">詳細を見る</a>
                </div>
            <?php endif; ?>

            <?php if ($expiring_count > 0): ?>
                <div class="alert warning">
                    <strong>📅 賞味期限警告:</strong> <?php echo $expiring_count; ?>件の商品が7日以内に期限切れになります
                    <a href="?status=expiring" style="margin-left: 10px; color: #856404; text-decoration: underline;">詳細を見る</a>
                </div>
            <?php endif; ?>

            <!-- 統計カード -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_items; ?></div>
                    <div class="stat-label">総商品数</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $low_stock_count; ?></div>
                    <div class="stat-label">在庫不足</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $expiring_count; ?></div>
                    <div class="stat-label">期限間近</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">¥<?php echo number_format($total_value); ?></div>
                    <div class="stat-label">総在庫価値</div>
                </div>
            </div>

            <!-- クイックアクション -->
            <div class="quick-actions">
                <a href="input.php" class="btn success">➕ 新商品追加</a>
                <a href="input.php#movement" class="btn">🔄 入出庫処理</a>
                <a href="index.php" class="btn" style="background: #6c757d;">🏠 ホーム</a>
                <button onclick="window.print()" class="btn" style="background: #6c757d;">🖨️ 印刷</button>
            </div>

            <!-- タブナビゲーション -->
            <div class="tab-buttons">
                <button class="tab-button active" onclick="switchTab('inventory')">📦 在庫一覧</button>
                <button class="tab-button" onclick="switchTab('alerts')">⚠️ 警告一覧</button>
                <button class="tab-button" onclick="switchTab('history')">📋 入出庫履歴</button>
            </div>

            <!-- 在庫一覧タブ -->
            <div id="inventory" class="tab-content active">
                <!-- フィルター -->
                <div class="filter-form">
                    <h4>🔍 絞り込み検索</h4>
                    <form method="GET">
                        <div class="form-group">
                            <label>カテゴリ</label>
                            <select name="category" onchange="this.form.submit()">
                                <option value="">全て</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" 
                                        <?php echo $filter_category == $category['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>状態</label>
                            <select name="status" onchange="this.form.submit()">
                                <option value="">全て</option>
                                <option value="normal" <?php echo $filter_status === 'normal' ? 'selected' : ''; ?>>正常在庫</option>
                                <option value="low_stock" <?php echo $filter_status === 'low_stock' ? 'selected' : ''; ?>>在庫不足</option>
                                <option value="expiring" <?php echo $filter_status === 'expiring' ? 'selected' : ''; ?>>期限間近</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn" style="margin-top: 24px;">🔍 検索</button>
                            <a href="?" class="btn" style="background: #6c757d; margin-top: 24px;">🔄 リセット</a>
                        </div>
                    </form>
                </div>

                <!-- 在庫一覧テーブル -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>商品名</th>
                                <th>カテゴリ</th>
                                <th>在庫数</th>
                                <th>単位</th>
                                <th>仕入価格</th>
                                <th>販売価格</th>
                                <th>発注点</th>
                                <th>状態</th>
                                <th>賞味期限</th>
                                <th>在庫価値</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($filtered_inventory) > 0): ?>
                                <?php foreach ($filtered_inventory as $item): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($item['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($item['category_name'] ?? '未分類'); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                        <td>¥<?php echo number_format($item['cost_price']); ?></td>
                                        <td>¥<?php echo number_format($item['selling_price']); ?></td>
                                        <td><?php echo $item['reorder_level']; ?></td>
                                        <td>
                                            <?php if ($item['quantity'] <= $item['reorder_level']): ?>
                                                <span class="status-badge status-low">要発注</span>
                                            <?php elseif ($item['expiry_date'] && strtotime($item['expiry_date']) <= strtotime('+7 days')): ?>
                                                <span class="status-badge status-warning">期限間近</span>
                                            <?php else: ?>
                                                <span class="status-badge status-normal">正常</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($item['expiry_date']): ?>
                                                <?php echo $item['expiry_date']; ?>
                                                <?php if (strtotime($item['expiry_date']) <= strtotime('+7 days')): ?>
                                                    ⚠️
                                                <?php endif; ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>¥<?php echo number_format($item['quantity'] * $item['cost_price']); ?></td>
                                        <td>
                                            <form method="POST" action="create.php" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn danger" onclick="return confirm('商品「<?php echo htmlspecialchars($item['name']); ?>」を削除しますか？\n※この操作は元に戻せません。')" style="padding: 5px 10px; font-size: 12px;">🗑️</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; padding: 40px; color: #666;">
                                        <?php if ($filter_category || $filter_status): ?>
                                            🔍 検索条件に一致する商品がありません
                                        <?php else: ?>
                                            📦 登録されている商品がありません
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 警告一覧タブ -->
            <div id="alerts" class="tab-content">
                <div class="card">
                    <h3>⚠️ 在庫不足商品</h3>
                    <?php if (count($low_stock) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>商品名</th>
                                        <th>現在庫数</th>
                                        <th>発注点</th>
                                        <th>不足数</th>
                                        <th>仕入先</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($low_stock as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo $item['quantity']; ?><?php echo $item['unit']; ?></td>
                                            <td><?php echo $item['reorder_level']; ?><?php echo $item['unit']; ?></td>
                                            <td class="status-badge status-low">
                                                <?php echo max(0, $item['reorder_level'] - $item['quantity']); ?><?php echo $item['unit']; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['supplier'] ?? '未設定'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #28a745; font-weight: bold;">✅ 在庫不足の商品はありません</p>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h3>📅 賞味期限間近商品</h3>
                    <?php if (count($expiring_soon) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>商品名</th>
                                        <th>在庫数</th>
                                        <th>賞味期限</th>
                                        <th>残り日数</th>
                                        <th>状態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expiring_soon as $item): ?>
                                        <?php 
                                        $days_until_expiry = floor((strtotime($item['expiry_date']) - time()) / (60 * 60 * 24));
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo $item['quantity']; ?><?php echo $item['unit']; ?></td>
                                            <td><?php echo $item['expiry_date']; ?></td>
                                            <td>
                                                <?php if ($days_until_expiry < 0): ?>
                                                    <span class="status-badge status-low">期限切れ</span>
                                                <?php elseif ($days_until_expiry == 0): ?>
                                                    <span class="status-badge status-warning">本日</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-warning"><?php echo $days_until_expiry; ?>日</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($days_until_expiry < 0): ?>
                                                    <span style="color: #dc3545;">🗑️ 廃棄推奨</span>
                                                <?php elseif ($days_until_expiry <= 3): ?>
                                                    <span style="color: #fd7e14;">⚡ 早期販売推奨</span>
                                                <?php else: ?>
                                                    <span style="color: #ffc107;">⚠️ 注意</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #28a745; font-weight: bold;">✅ 期限間近の商品はありません</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 履歴タブ -->
            <div id="history" class="tab-content">
                <div class="card">
                    <h3>📋 最近の入出庫履歴</h3>
                    <?php if (count($recent_movements) > 0): ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>日時</th>
                                        <th>商品名</th>
                                        <th>処理</th>
                                        <th>数量</th>
                                        <th>理由</th>
                                        <th>担当者</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_movements as $movement): ?>
                                        <tr>
                                            <td><?php echo date('m/d H:i', strtotime($movement['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($movement['item_name']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $movement['movement_type'] === '入庫' ? 'status-normal' : 'status-warning'; ?>">
                                                    <?php echo $movement['movement_type']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $movement['quantity']; ?><?php echo $movement['unit']; ?></td>
                                            <td><?php echo htmlspecialchars($movement['reason'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($movement['created_by']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; color: #666;">📝 履歴データがありません</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // すべてのタブボタンとコンテンツを非アクティブに
            document.querySelectorAll('.tab-button').forEach(button => button.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            // 選択されたタブボタンとコンテンツをアクティブに
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
        }

        // 自動更新機能（5分ごと）
        setInterval(function() {
            // ページを自動更新
            if (confirm('最新の在庫情報を取得しますか？')) {
                location.reload();
            }
        }, 300000); // 5分 = 300,000ミリ秒

        // 印刷用スタイル調整
        window.addEventListener('beforeprint', function() {
            document.querySelectorAll('.tab-content:not(.active)').forEach(content => {
                content.style.display = 'block';
            });
        });

        window.addEventListener('afterprint', function() {
            document.querySelectorAll('.tab-content:not(.active)').forEach(content => {
                content.style.display = 'none';
            });
        });

        // 削除確認の強化
        document.querySelectorAll('button[onclick*="confirm"]').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('本当に削除しますか？\n削除すると元に戻せません。')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>