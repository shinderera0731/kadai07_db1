<?php
// 共通設定ファイルを読み込み
include 'config.php';

// データ取得
try {
    // カテゴリ一覧
    $categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    
    // 在庫一覧（入出庫用）
    $inventory = $pdo->query("
        SELECT i.*, c.name as category_name 
        FROM inventory i 
        LEFT JOIN categories c ON i.category_id = c.id 
        ORDER BY c.name, i.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $categories = [];
    $inventory = [];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>商品追加・入出庫 - シンデレラカフェ</title>
    <?php echo getCommonCSS(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏰 Cinderella cafe</h1>
            <p>商品追加・入出庫画面</p>
        </div>

        <div class="content">
            <!-- ナビゲーション -->
            <?php echo getNavigation('input'); ?>

            <!-- メッセージ表示 -->
            <?php showMessage(); ?>

            <!-- システム初期化（テーブルが存在しない場合） -->
            <?php if (empty($categories)): ?>
                <div class="card">
                    <h3>🔧 システム初期化</h3>
                    <p>最初にデータベーステーブルを作成してください。</p>
                    <form method="POST" action="create.php" style="margin-top: 15px;">
                        <input type="hidden" name="action" value="create_tables">
                        <button type="submit" class="btn success">データベーステーブルを作成</button>
                    </form>
                </div>
            <?php else: ?>

            <!-- 商品追加フォーム -->
            <div class="card">
                <h3>➕ 新商品追加</h3>
                <form method="POST" action="create.php">
                    <input type="hidden" name="action" value="add_item">
                    <div class="form-grid">
                        <div>
                            <div class="form-group">
                                <label>商品名 <span style="color: red;">*</span></label>
                                <input type="text" name="name" required placeholder="例：ブラジル産コーヒー豆">
                            </div>
                            <div class="form-group">
                                <label>カテゴリ <span style="color: red;">*</span></label>
                                <select name="category_id" required>
                                    <option value="">選択してください</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>初期在庫数 <span style="color: red;">*</span></label>
                                <input type="number" name="quantity" min="0" required placeholder="例：50">
                            </div>
                            <div class="form-group">
                                <label>単位 <span style="color: red;">*</span></label>
                                <input type="text" name="unit" placeholder="例：kg, 個, L, 袋" required>
                            </div>
                            <div class="form-group">
                                <label>発注点（この数値以下で警告表示）</label>
                                <input type="number" name="reorder_level" min="0" value="10" placeholder="例：10">
                            </div>
                        </div>
                        <div>
                            <div class="form-group">
                                <label>仕入価格（円） <span style="color: red;">*</span></label>
                                <input type="number" name="cost_price" step="0.01" min="0" required placeholder="例：1200.00">
                            </div>
                            <div class="form-group">
                                <label>販売価格（円） <span style="color: red;">*</span></label>
                                <input type="number" name="selling_price" step="0.01" min="0" required placeholder="例：1800.00">
                            </div>
                            <div class="form-group">
                                <label>仕入先</label>
                                <input type="text" name="supplier" placeholder="例：○○商事">
                            </div>
                            <div class="form-group">
                                <label>賞味期限</label>
                                <input type="date" name="expiry_date">
                            </div>
                        </div>
                    </div>
                    <div style="text-align: center; margin-top: 20px;">
                        <button type="submit" class="btn success">💾 商品を追加</button>
                        <button type="reset" class="btn" style="background: #6c757d;">🔄 リセット</button>
                    </div>
                </form>
            </div>

            <!-- 入出庫フォーム -->
            <div class="card" id="movement">
                <h3>🔄 入出庫処理</h3>
                <?php if (count($inventory) > 0): ?>
                    <form method="POST" action="create.php">
                        <input type="hidden" name="action" value="update_stock">
                        <div class="form-grid">
                            <div>
                                <div class="form-group">
                                    <label>商品選択 <span style="color: red;">*</span></label>
                                    <select name="item_id" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($inventory as $item): ?>
                                            <option value="<?php echo $item['id']; ?>">
                                                <?php echo htmlspecialchars($item['name']); ?> 
                                                (現在: <?php echo $item['quantity']; ?><?php echo $item['unit']; ?>)
                                                <?php if ($item['quantity'] <= $item['reorder_level']): ?>
                                                    ⚠️
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>処理種別 <span style="color: red;">*</span></label>
                                    <select name="movement_type" required>
                                        <option value="">選択してください</option>
                                        <option value="入庫">📦 入庫（仕入・補充）</option>
                                        <option value="出庫">📤 出庫（販売・使用）</option>
                                        <option value="廃棄">🗑️ 廃棄</option>
                                        <option value="調整">⚖️ 棚卸調整</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <div class="form-group">
                                    <label>数量 <span style="color: red;">*</span></label>
                                    <input type="number" name="new_quantity" min="1" required placeholder="例：5">
                                </div>
                                <div class="form-group">
                                    <label>理由・メモ</label>
                                    <input type="text" name="reason" placeholder="例：朝の仕入、ランチ販売、期限切れ廃棄">
                                </div>
                            </div>
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <button type="submit" class="btn">🔄 在庫を更新</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert warning">
                        <strong>⚠️ 注意:</strong> 商品が登録されていません。先に商品を追加してください。
                    </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>

            <!-- 使い方ガイド -->
            <div class="card">
                <h3>📖 使い方ガイド</h3>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">
                    <h4>📝 商品追加の手順</h4>
                    <ol style="margin-left: 20px;">
                        <li>商品名、カテゴリ、初期在庫数を入力</li>
                        <li>単位、仕入価格、販売価格を設定</li>
                        <li>発注点を設定（この数値以下で警告表示）</li>
                        <li>「商品を追加」ボタンをクリック</li>
                    </ol>

                    <h4>🔄 入出庫処理の手順</h4>
                    <ol style="margin-left: 20px;">
                        <li>処理したい商品を選択</li>
                        <li>処理種別を選択（入庫、出庫、廃棄、調整）</li>
                        <li>数量を入力</li>
                        <li>理由やメモを記入（任意）</li>
                        <li>「在庫を更新」ボタンをクリック</li>
                    </ol>

                    <h4>💡 便利な機能</h4>
                    <ul style="margin-left: 20px;">
                        <li><strong>自動警告:</strong> 発注点を下回ると⚠️マークが表示</li>
                        <li><strong>履歴記録:</strong> すべての入出庫は自動で記録</li>
                        <li><strong>在庫価値:</strong> 仕入価格×在庫数で自動計算</li>
                    </ul>
                </div>
            </div>

            <!-- クイックリンク -->
            <div style="text-align: center; margin-top: 30px;">
                <a href="index.php" class="btn" style="background: #6c757d;">🏠 ホームに戻る</a>
                <a href="select.php" class="btn">📊 在庫一覧を見る</a>
            </div>
        </div>
    </div>

    <script>
        // フォーム送信時の確認
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = this.querySelector('input[name="action"]').value;
                
                if (action === 'add_item') {
                    const name = this.querySelector('input[name="name"]').value;
                    if (!confirm(`商品「${name}」を追加しますか？`)) {
                        e.preventDefault();
                    }
                }
                
                if (action === 'update_stock') {
                    const movementType = this.querySelector('select[name="movement_type"]').value;
                    const quantity = this.querySelector('input[name="new_quantity"]').value;
                    if (!confirm(`${movementType}処理（数量: ${quantity}）を実行しますか？`)) {
                        e.preventDefault();
                    }
                }
            });
        });

        // 処理種別に応じて説明文を表示
        const movementTypeSelect = document.querySelector('select[name="movement_type"]');
        if (movementTypeSelect) {
            movementTypeSelect.addEventListener('change', function() {
                const infoDiv = document.getElementById('movement-info');
                if (infoDiv) infoDiv.remove();
                
                const info = {
                    '入庫': '在庫数が増加します（仕入、補充など）',
                    '出庫': '在庫数が減少します（販売、使用など）',
                    '廃棄': '在庫数が減少します（期限切れ、破損など）',
                    '調整': '棚卸結果に基づいて在庫数を調整します'
                };
                
                if (this.value && info[this.value]) {
                    const div = document.createElement('div');
                    div.id = 'movement-info';
                    div.style.cssText = 'background: #e7f3ff; padding: 8px; border-radius: 4px; font-size: 14px; margin-top: 5px; color: #0066cc;';
                    div.textContent = '💡 ' + info[this.value];
                    this.parentNode.appendChild(div);
                }
            });
        }

        // リアルタイム利益計算
        const costPriceInput = document.querySelector('input[name="cost_price"]');
        const sellingPriceInput = document.querySelector('input[name="selling_price"]');
        
        function calculateProfit() {
            const costPrice = parseFloat(costPriceInput.value) || 0;
            const sellingPrice = parseFloat(sellingPriceInput.value) || 0;
            const profit = sellingPrice - costPrice;
            const profitMargin = costPrice > 0 ? ((profit / costPrice) * 100).toFixed(1) : 0;
            
            let profitDiv = document.getElementById('profit-info');
            if (!profitDiv) {
                profitDiv = document.createElement('div');
                profitDiv.id = 'profit-info';
                profitDiv.style.cssText = 'background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 14px; margin-top: 10px; border-left: 4px solid #667eea;';
                sellingPriceInput.parentNode.appendChild(profitDiv);
            }
            
            if (costPrice > 0 && sellingPrice > 0) {
                profitDiv.innerHTML = `
                    <strong>💰 利益計算:</strong><br>
                    利益: ¥${profit.toLocaleString()} 
                    (利益率: ${profitMargin}%)
                `;
                profitDiv.style.color = profit > 0 ? '#28a745' : '#dc3545';
            } else {
                profitDiv.innerHTML = '';
            }
        }
        
        if (costPriceInput && sellingPriceInput) {
            costPriceInput.addEventListener('input', calculateProfit);
            sellingPriceInput.addEventListener('input', calculateProfit);
        }
    </script>
</body>
</html>