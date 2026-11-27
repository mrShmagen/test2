<?php
// Include only from authenticated cabinet.php. Credentials live in private pass.php.
function loyalty1c_db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO('mysql:host='.LOYALTY_DB_HOST.';dbname='.LOYALTY_DB_NAME.';charset=utf8mb4',LOYALTY_DB_USER,LOYALTY_DB_PASS,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
function loyalty1c_card(int $uid): ?array {
    $q=loyalty1c_db()->prepare('SELECT card_number,barcode,last_name,first_name,middle_name,phone,birth_date,cashback_percent,bonus_balance,is_active,updated_at FROM loyalty_cards_1c WHERE user_id=?');
    $q->execute([$uid]);
    $r=$q->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}
function loyalty1c_link(int $uid,string $code): bool {
    if ($uid<=0 || !defined('LOYALTY_LINK_PEPPER') || strlen(LOYALTY_LINK_PEPPER)<32 || LOYALTY_LINK_PEPPER==='REPLACE_WITH_RANDOM_SECRET_KEY') throw new RuntimeException('configuration');
    $pdo=loyalty1c_db();
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('INSERT INTO loyalty_link_attempts(user_id,window_started,attempts) VALUES (?,NOW(),0) ON DUPLICATE KEY UPDATE user_id=user_id');
        $q->execute([$uid]);
        $q=$pdo->prepare('SELECT attempts,window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE) AS reset_window FROM loyalty_link_attempts WHERE user_id=? FOR UPDATE');
        $q->execute([$uid]);$a=$q->fetch(PDO::FETCH_ASSOC);
        if (!(int)$a['reset_window'] && (int)$a['attempts']>=5) {$pdo->commit();return false;}
        if ((int)$a['reset_window']) {
            $q=$pdo->prepare('UPDATE loyalty_link_attempts SET attempts=1,window_started=NOW() WHERE user_id=?');
        } else {
            $q=$pdo->prepare('UPDATE loyalty_link_attempts SET attempts=attempts+1 WHERE user_id=?');
        }
        $q->execute([$uid]);
        // Serialize network-wide guessing limit; successful claims restore one attempt.
        $pdo->exec('INSERT INTO loyalty_link_global_limit(id,window_started,attempts) VALUES (1,NOW(),0) ON DUPLICATE KEY UPDATE id=id');
        $g=$pdo->query('SELECT attempts,window_started<=DATE_SUB(NOW(),INTERVAL 15 MINUTE) AS reset_window FROM loyalty_link_global_limit WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if ((int)$g['reset_window']) {
            $pdo->exec('UPDATE loyalty_link_global_limit SET window_started=NOW(),attempts=0 WHERE id=1');
        } elseif ((int)$g['attempts']>=30) {$pdo->commit();return false;}
        $pdo->exec('UPDATE loyalty_link_global_limit SET attempts=attempts+1 WHERE id=1');
        if (!preg_match('/\A[0-9]{6}\z/',$code)) {$pdo->commit();return false;}
        $hash=hash_hmac('sha256','link-v2:'.$code,LOYALTY_LINK_PEPPER,true);
        $q=$pdo->prepare('SELECT card_id FROM loyalty_link_codes WHERE code_hash=? AND consumed_at IS NULL AND expires_at>NOW()');
        $q->execute([$hash]);$found=$q->fetchColumn();
        if ($found===false) {$pdo->commit();return false;}
        $id=(int)$found;
        // Match issuer lock order: card first, token second; recheck current token.
        $q=$pdo->prepare('SELECT id,owner_guid,user_id,is_active,barcode FROM loyalty_cards_1c WHERE id=? FOR UPDATE');
        $q->execute([$id]);$card=$q->fetch(PDO::FETCH_ASSOC);
        if (!$card || (int)$card['is_active']!==1 || $card['user_id']!==null || !$card['owner_guid'] || !$card['barcode']) {$pdo->commit();return false;}
        $q=$pdo->prepare('SELECT code_hash,owner_guid FROM loyalty_link_codes WHERE card_id=? AND consumed_at IS NULL AND expires_at>NOW() FOR UPDATE');
        $q->execute([$id]);$token=$q->fetch(PDO::FETCH_ASSOC);
        if (!$token || $token['owner_guid']!==$card['owner_guid'] || !hash_equals($token['code_hash'],$hash)) {$pdo->commit();return false;}
        $q=$pdo->prepare('SELECT id FROM loyalty_cards_1c WHERE user_id=?');
        $q->execute([$uid]);
        if ($q->fetchColumn()!==false) {$pdo->commit();return false;}
        $q=$pdo->prepare('UPDATE loyalty_cards_1c SET user_id=?,linked_at=NOW() WHERE id=? AND user_id IS NULL AND is_active=1');
        $q->execute([$uid,$id]);
        if ($q->rowCount()!==1) throw new RuntimeException('link conflict');
        $q=$pdo->prepare('UPDATE loyalty_link_codes SET consumed_at=NOW() WHERE card_id=? AND consumed_at IS NULL');
        $q->execute([$id]);
        if ($q->rowCount()!==1) throw new RuntimeException('token conflict');
        $q=$pdo->prepare("INSERT INTO loyalty_link_log(card_id,user_id,event_type) VALUES (?,?,'linked')");
        $q->execute([$id,$uid]);
        $pdo->exec('UPDATE loyalty_link_global_limit SET attempts=attempts-1 WHERE id=1 AND attempts>0');
        $pdo->commit();return true;
    } catch(Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
