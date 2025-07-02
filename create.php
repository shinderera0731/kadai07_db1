<?php
// 共通設定ファイルを読み込み
include 'config.php';

// POSTデータが送信されているかチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = '不正なアクセスです。';
    header('Location: index.php');
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        
        // データベーステーブル作成
        case 'create_tables':
            if (createTables($pdo)) {
                $_SESSION['message'] = '✅ データベーステーブルが正常に作成されました。システムの準備が完了しました！';
            } else {
                $_SESSION['error'] = '❌ テーブル作成に失敗しました。';
            }
            header('Location: index.php');
            exit;

        // 新商品追加
        case 'add_item':
            // 入力値の検証
            $name = trim($_POST['name'] ?? '');
            $category_id = (int)($_POST['category_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);
            $unit = trim($_POST['unit'] ?? '');
            $cost_price = (float)($_POST['cost_price'] ?? 0);
            $selling_price = (float)($_POST['selling_price'] ?? 0);
            $reorder_level = (int)($_POST['reorder_level'] ?? 10);
            $supplier = trim($_POST['supplier'] ?? '') ?: null;
            $expiry_date = $_POST['expiry_date'] ?: null;

            // 必須項目チェック
            if (empty($name) || $category_id <= 0 || empty($unit) || $cost_price < 0 || $selling_price < 0) {
                $_SESSION['error'] = '❌ 必須項目が入力されていないか、不正な値が含まれています。';
                header('Location: input.php');
                exit;
            }

            // 同名商品の重複チェック
            $stmt = $pdo->prepare("SELECT id FROM inventory WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $_SESSION['error'] = "❌ 商品「{$name}」は既に登録されています。";
                header('Location: input.php');
                exit;
            }

            // 商品追加
            $stmt = $pdo->prepare("INSERT INTO inventory (name, category_id, quantity, unit, cost_price, selling_price, reorder_level, supplier, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name,
                $category_id,
                $quantity,
                $unit,
                $cost_price,
                $selling_price,
                $reorder_level,
                $supplier,
                $expiry_date
            ]);

            $item_id = $pdo->lastInsertId();

            // 初期在庫の履歴記録
            if ($quantity > 0) {
                $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, created_by) VALUES (?, '入庫', ?, '新商品登録', 'システム')");
                $stmt->execute([$item_id, $quantity]);
            }

            $_SESSION['message'] = "✅ 商品「{$name}」が正常に追加されました。初期在庫: {$quantity}{$unit}";
            header('Location: input.php');
            exit;

        // 在庫更新（入出庫処理）
        case 'update_stock':
            $item_id = (int)($_POST['item_id'] ?? 0);
            $new_quantity = (int)($_POST['new_quantity'] ?? 0);
            $movement_type = $_POST['movement_type'] ?? '';
            $reason = trim($_POST['reason'] ?? '') ?: null;

            // 入力値の検証
            if ($item_id <= 0 || $new_quantity <= 0 || empty($movement_type)) {
                $_SESSION['error'] = '❌ 必須項目が入力されていないか、不正な値が含まれています。';
                header('Location: input.php');
                exit;
            }

            // 有効な処理種別かチェック
            $valid_types = ['入庫', '出庫', '廃棄', '調整'];
            if (!in_array($movement_type, $valid_types)) {
                $_SESSION['error'] = '❌ 無効な処理種別です。';
                header('Location: input.php');
                exit;
            }

            // 現在の在庫数取得
            $stmt = $pdo->prepare("SELECT name, quantity, unit FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                $_SESSION['error'] = '❌ 指定された商品が見つかりません。';
                header('Location: input.php');
                exit;
            }

            $old_quantity = $current['quantity'];
            $item_name = $current['name'];
            $unit = $current['unit'];

            // 新しい在庫数計算
            switch ($movement_type) {
                case '入庫':
                    $final_quantity = $old_quantity + $new_quantity;
                    $change_amount = $new_quantity;
                    break;
                    
                case '出庫':
                case '廃棄':
                    if ($new_quantity > $old_quantity) {
                        $_SESSION['error'] = "❌ {$movement_type}数量（{$new_quantity}）が現在の在庫数（{$old_quantity}）を超えています。";
                        header('Location: input.php');
                        exit;
                    }
                    $final_quantity = $old_quantity - $new_quantity;
                    $change_amount = $new_quantity;
                    break;
                    
                case '調整':
                    // 調整の場合は、入力値を最終在庫数として扱う
                    $final_quantity = $new_quantity;
                    $change_amount = abs($new_quantity - $old_quantity);
                    break;
            }

            // 在庫数更新
            $stmt = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
            $stmt->execute([$final_quantity, $item_id]);

            // 履歴記録
            if ($movement_type === '調整') {
                // 調整の場合は、増減に応じて履歴を記録
                if ($new_quantity > $old_quantity) {
                    $log_type = '入庫';
                    $log_reason = $reason ?: '棚卸調整（増加）';
                } else {
                    $log_type = '出庫';
                    $log_reason = $reason ?: '棚卸調整（減少）';
                }
            } else {
                $log_type = $movement_type;
                $log_reason = $reason ?: $movement_type;
            }

            $stmt = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, quantity, reason, created_by) VALUES (?, ?, ?, ?, 'システム')");
            $stmt->execute([$item_id, $log_type, $change_amount, $log_reason]);

            // 成功メッセージ
            $operation_desc = [
                '入庫' => '入庫しました',
                '出庫' => '出庫しました',
                '廃棄' => '廃棄しました',
                '調整' => '調整しました'
            ];

            $_SESSION['message'] = "✅ 「{$item_name}」を{$operation_desc[$movement_type]}。" . 
                                 " 変更: {$old_quantity}{$unit} → {$final_quantity}{$unit}";

            // 在庫不足警告
            $stmt = $pdo->prepare("SELECT reorder_level FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $reorder_level = $stmt->fetchColumn();

            if ($final_quantity <= $reorder_level) {
                $_SESSION['message'] .= " ⚠️ 発注点を下回りました！";
            }

            header('Location: input.php');
            exit;

        // 商品削除
        case 'delete_item':
            $item_id = (int)($_POST['item_id'] ?? 0);

            if ($item_id <= 0) {
                $_SESSION['error'] = '❌ 無効な商品IDです。';
                header('Location: select.php');
                exit;
            }

            // 商品名を取得
            $stmt = $pdo->prepare("SELECT name FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);
            $item_name = $stmt->fetchColumn();

            if (!$item_name) {
                $_SESSION['error'] = '❌ 指定された商品が見つかりません。';
                header('Location: select.php');
                exit;
            }

            // 関連する履歴データも削除
            $stmt = $pdo->prepare("DELETE FROM stock_movements WHERE item_id = ?");
            $stmt->execute([$item_id]);

            // 商品削除
            $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
            $stmt->execute([$item_id]);

            $_SESSION['message'] = "✅ 商品「{$item_name}」とその履歴データを削除しました。";
            header('Location: select.php');
            exit;

        default:
            $_SESSION['error'] = '❌ 無効な操作です。';
            header('Location: index.php');
            exit;
    }

} catch (PDOException $e) {
    // データベースエラー
    error_log("Database Error: " . $e->getMessage());
    $_SESSION['error'] = '❌ データベースエラーが発生しました。しばらく待ってから再度お試しください。';
    
    // エラーの種類に応じてリダイレクト先を決定
    if (in_array($action, ['add_item', 'update_stock', 'create_tables'])) {
        header('Location: input.php');
    } else {
        header('Location: index.php');
    }
    exit;
    
} catch (Exception $e) {
    // その他のエラー
    error_log("General Error: " . $e->getMessage());
    $_SESSION['error'] = '❌ システムエラーが発生しました。管理者にお問い合わせください。';
    header('Location: index.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>処理完了 - 🏰 Cinderella cafe</title>
    <?php echo getCommonCSS(); ?>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏰 Cinderella cafe</h1>
            <p>処理完了</p>
        </div>

        <div class="content">
            <div class="card">
                <h3>⚠️ 予期しないエラー</h3>
                <p>処理中に予期しないエラーが発生しました。</p>
                <p>自動でリダイレクトされない場合は、以下のリンクをクリックしてください。</p>
                
                <div style="text-align: center; margin-top: 30px;">
                    <a href="index.php" class="btn">🏠 ホームに戻る</a>
                    <a href="select.php" class="btn">📊 在庫一覧に戻る</a>
                    <a href="input.php" class="btn">➕ 入力画面に戻る</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 3秒後に自動リダイレクト
        setTimeout(function() {
            window.location.href = 'index.php';
        }, 3000);
    </script>
</body>
</html>