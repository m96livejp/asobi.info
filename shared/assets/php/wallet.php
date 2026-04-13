<?php
/**
 * あそびウォレット（AC）共通API
 *
 * 使用方法:
 *   require_once '/opt/asobi/shared/assets/php/wallet.php';
 *
 * 主要関数:
 *   asobiWalletBalance($userId)                              残高取得
 *   asobiWalletCharge($userId, $jpy, $acBase, $acBonus, $refId)  チャージ記録
 *   asobiWalletExchange($userId, $ac, $site, $note, $refId)     消費（FIFO）
 *   asobiWalletHistory($userId, $limit = 50)                 履歴取得
 *   asobiWalletLots($userId)                                 有効なロット一覧
 *   asobiWalletExpireOld()                                   失効処理（cron）
 *   asobiWalletAdjust($userId, $amount, $note)               管理者手動調整
 *
 *   asobiWalletPresets()                                     有効プリセット一覧
 *   asobiWalletPresetAll()                                   全プリセット（管理者用）
 *   asobiWalletCampaignGet()                                 キャンペーン状態
 *
 * 設計:
 *   - 有効期限6ヶ月のロット管理（FIFO消費）
 *   - 複数ロットがある場合、古いロットから消費される
 *   - 失効処理は cron で日次実行
 *   - すべての変動は wallet_transactions に記録
 */

require_once __DIR__ . '/users_db.php';

const ASOBI_WALLET_EXPIRE_MONTHS = 6;

/**
 * 残高取得（存在しなければ0）
 */
function asobiWalletBalance(int $userId): int {
    $db = asobiUsersDb();
    $stmt = $db->prepare("SELECT balance FROM wallets WHERE user_id = ?");
    $stmt->execute([$userId]);
    $bal = $stmt->fetchColumn();
    return $bal !== false ? (int)$bal : 0;
}

/**
 * チャージ記録（ロット作成＋残高増加）
 * @param int $userId
 * @param int $jpy       支払金額（円）
 * @param int $acBase    基本付与AC
 * @param int $acBonus   ボーナスAC
 * @param string $refId  Stripe決済ID等
 * @return int lot_id
 */
function asobiWalletCharge(int $userId, int $jpy, int $acBase, int $acBonus, string $refId = '', bool $isTest = false): int {
    $db = asobiUsersDb();
    $db->beginTransaction();
    try {
        $acTotal = $acBase + $acBonus;
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . ASOBI_WALLET_EXPIRE_MONTHS . ' months'));
        $testFlag = $isTest ? 1 : 0;

        // ロット作成
        $stmt = $db->prepare("
            INSERT INTO wallet_charge_lots
            (user_id, charge_jpy, ac_base, ac_bonus, ac_total, ac_remaining, expires_at, stripe_payment_id, is_test)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $jpy, $acBase, $acBonus, $acTotal, $acTotal, $expiresAt, $refId, $testFlag]);
        $lotId = (int)$db->lastInsertId();

        // ウォレット残高更新
        $db->prepare("
            INSERT INTO wallets (user_id, balance, total_charged_jpy, updated_at)
            VALUES (?, ?, ?, datetime('now','localtime'))
            ON CONFLICT(user_id) DO UPDATE SET
                balance = balance + excluded.balance,
                total_charged_jpy = total_charged_jpy + excluded.total_charged_jpy,
                updated_at = excluded.updated_at
        ")->execute([$userId, $acTotal, $jpy]);

        $balance = asobiWalletBalance($userId);

        // 取引履歴
        $noteText = "チャージ {$jpy}円 (基本{$acBase}+ボーナス{$acBonus})" . ($isTest ? ' [TEST]' : '');
        $db->prepare("
            INSERT INTO wallet_transactions (user_id, type, amount, balance_after, lot_id, reference_id, note, is_test)
            VALUES (?, 'charge', ?, ?, ?, ?, ?, ?)
        ")->execute([$userId, $acTotal, $balance, $lotId, $refId, $noteText, $testFlag]);

        $db->commit();
        return $lotId;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * AC消費（FIFO: 有効期限が古いロットから消費）
 * @param int $userId
 * @param int $ac      消費AC
 * @param string $site コンテンツサイト（aic/image等）
 * @param string $note 用途メモ
 * @param string $refId 参照ID（ジョブID等）
 * @return bool true=成功、false=残高不足
 */
function asobiWalletExchange(int $userId, int $ac, string $site = '', string $note = '', string $refId = ''): bool {
    if ($ac <= 0) return false;
    $db = asobiUsersDb();
    $db->beginTransaction();
    try {
        // 残高チェック
        $balance = asobiWalletBalance($userId);
        if ($balance < $ac) {
            $db->rollBack();
            return false;
        }

        // FIFO消費: expires_atの古いロットから減算
        $remaining = $ac;
        $stmt = $db->prepare("
            SELECT id, ac_remaining FROM wallet_charge_lots
            WHERE user_id = ? AND ac_remaining > 0 AND expires_at > datetime('now','localtime')
            ORDER BY expires_at ASC, id ASC
        ");
        $stmt->execute([$userId]);
        $lots = $stmt->fetchAll();

        $lotIds = [];
        foreach ($lots as $lot) {
            if ($remaining <= 0) break;
            $take = min($remaining, (int)$lot['ac_remaining']);
            $db->prepare("UPDATE wallet_charge_lots SET ac_remaining = ac_remaining - ? WHERE id = ?")
               ->execute([$take, $lot['id']]);
            $remaining -= $take;
            $lotIds[] = $lot['id'];
        }

        if ($remaining > 0) {
            // ここに来る=残高不整合
            $db->rollBack();
            return false;
        }

        // 残高減算
        $db->prepare("UPDATE wallets SET balance = balance - ?, updated_at = datetime('now','localtime') WHERE user_id = ?")
           ->execute([$ac, $userId]);

        $newBalance = asobiWalletBalance($userId);

        // 取引履歴
        $db->prepare("
            INSERT INTO wallet_transactions (user_id, type, amount, balance_after, site, lot_id, reference_id, note)
            VALUES (?, 'exchange', ?, ?, ?, ?, ?, ?)
        ")->execute([$userId, -$ac, $newBalance, $site, $lotIds ? $lotIds[0] : null, $refId, $note]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 取引履歴取得
 */
function asobiWalletHistory(int $userId, int $limit = 50): array {
    $db = asobiUsersDb();
    $stmt = $db->prepare("
        SELECT id, type, amount, balance_after, site, reference_id, note, is_test, admin_comment, created_at
        FROM wallet_transactions
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

/**
 * 有効なロット一覧（ユーザー画面で期限別残高を表示する用）
 */
function asobiWalletLots(int $userId): array {
    $db = asobiUsersDb();
    $stmt = $db->prepare("
        SELECT id, charge_jpy, ac_base, ac_bonus, ac_total, ac_remaining, expires_at, created_at
        FROM wallet_charge_lots
        WHERE user_id = ? AND ac_remaining > 0 AND expires_at > datetime('now','localtime')
        ORDER BY expires_at ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * 失効処理（cron日次実行）
 * 期限切れロットの残AC を失効させる
 */
function asobiWalletExpireOld(): array {
    $db = asobiUsersDb();
    $db->beginTransaction();
    try {
        // 期限切れかつ残高が残っているロットを抽出
        $stmt = $db->prepare("
            SELECT id, user_id, ac_remaining
            FROM wallet_charge_lots
            WHERE ac_remaining > 0 AND expires_at <= datetime('now','localtime')
        ");
        $stmt->execute();
        $expired = $stmt->fetchAll();

        $totalExpired = 0;
        foreach ($expired as $lot) {
            $userId = (int)$lot['user_id'];
            $lotId = (int)$lot['id'];
            $ac = (int)$lot['ac_remaining'];

            // 残高減算
            $db->prepare("UPDATE wallets SET balance = balance - ?, updated_at = datetime('now','localtime') WHERE user_id = ?")
               ->execute([$ac, $userId]);
            $newBalance = asobiWalletBalance($userId);

            // ロットを失効
            $db->prepare("UPDATE wallet_charge_lots SET ac_remaining = 0 WHERE id = ?")->execute([$lotId]);

            // 取引履歴
            $db->prepare("
                INSERT INTO wallet_transactions (user_id, type, amount, balance_after, lot_id, note)
                VALUES (?, 'expire', ?, ?, ?, '有効期限切れによる失効')
            ")->execute([$userId, -$ac, $newBalance, $lotId]);

            $totalExpired += $ac;
        }

        $db->commit();
        return ['lots' => count($expired), 'ac' => $totalExpired];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 管理者手動調整（ボーナス付与・補填等）
 * @param int $amount 正=付与、負=減算
 */
function asobiWalletAdjust(int $userId, int $amount, string $note = ''): bool {
    if ($amount === 0) return false;
    $db = asobiUsersDb();
    $db->beginTransaction();
    try {
        if ($amount > 0) {
            // 付与: 新しいロットを作成（6ヶ月有効）
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . ASOBI_WALLET_EXPIRE_MONTHS . ' months'));
            $db->prepare("
                INSERT INTO wallet_charge_lots
                (user_id, charge_jpy, ac_base, ac_bonus, ac_total, ac_remaining, expires_at)
                VALUES (?, 0, 0, ?, ?, ?, ?)
            ")->execute([$userId, $amount, $amount, $amount, $expiresAt]);

            $db->prepare("
                INSERT INTO wallets (user_id, balance, updated_at)
                VALUES (?, ?, datetime('now','localtime'))
                ON CONFLICT(user_id) DO UPDATE SET
                    balance = balance + excluded.balance,
                    updated_at = excluded.updated_at
            ")->execute([$userId, $amount]);
        } else {
            // 減算: FIFOで消費
            $ac = -$amount;
            $balance = asobiWalletBalance($userId);
            if ($balance < $ac) {
                $db->rollBack();
                return false;
            }
            $remaining = $ac;
            $stmt = $db->prepare("
                SELECT id, ac_remaining FROM wallet_charge_lots
                WHERE user_id = ? AND ac_remaining > 0 AND expires_at > datetime('now','localtime')
                ORDER BY expires_at ASC, id ASC
            ");
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll() as $lot) {
                if ($remaining <= 0) break;
                $take = min($remaining, (int)$lot['ac_remaining']);
                $db->prepare("UPDATE wallet_charge_lots SET ac_remaining = ac_remaining - ? WHERE id = ?")
                   ->execute([$take, $lot['id']]);
                $remaining -= $take;
            }
            $db->prepare("UPDATE wallets SET balance = balance - ?, updated_at = datetime('now','localtime') WHERE user_id = ?")
               ->execute([$ac, $userId]);
        }

        $newBalance = asobiWalletBalance($userId);
        $db->prepare("
            INSERT INTO wallet_transactions (user_id, type, amount, balance_after, note)
            VALUES (?, 'adjust', ?, ?, ?)
        ")->execute([$userId, $amount, $newBalance, $note]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 取引履歴への管理者コメント追加/更新
 */
function asobiWalletAdminComment(int $txId, string $comment, int $adminUserId): bool {
    $db = asobiUsersDb();
    $stmt = $db->prepare("
        UPDATE wallet_transactions
        SET admin_comment = ?, admin_comment_at = datetime('now','localtime'), admin_comment_by = ?
        WHERE id = ?
    ");
    $stmt->execute([$comment, $adminUserId, $txId]);
    return $stmt->rowCount() > 0;
}

/**
 * 有効なプリセット一覧（ユーザー画面用、ボーナスはキャンペーン状態を反映）
 */
function asobiWalletPresets(): array {
    $db = asobiUsersDb();
    $campaign = asobiWalletCampaignGet();
    $stmt = $db->prepare("
        SELECT id, charge_jpy, ac_base, ac_bonus
        FROM wallet_presets
        WHERE is_active = 1
        ORDER BY sort_order ASC, charge_jpy ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $bonusOn = !empty($campaign['bonus_enabled']);
    return array_map(function($r) use ($bonusOn) {
        $bonus = $bonusOn ? (int)$r['ac_bonus'] : 0;
        return [
            'id'         => (int)$r['id'],
            'charge_jpy' => (int)$r['charge_jpy'],
            'ac_base'    => (int)$r['ac_base'],
            'ac_bonus'   => $bonus,
            'ac_total'   => (int)$r['ac_base'] + $bonus,
        ];
    }, $rows);
}

/**
 * 全プリセット（管理画面用、ボーナスも常に返す）
 */
function asobiWalletPresetAll(): array {
    $db = asobiUsersDb();
    $stmt = $db->query("SELECT * FROM wallet_presets ORDER BY charge_jpy ASC");
    return $stmt->fetchAll();
}

/**
 * キャンペーン状態取得
 */
function asobiWalletCampaignGet(): array {
    $db = asobiUsersDb();
    $row = $db->query("SELECT * FROM wallet_campaign WHERE id = 1")->fetch();
    return $row ?: ['bonus_enabled' => 0, 'label' => '期間限定ボーナス実施中'];
}
