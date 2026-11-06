<?php
/**
 * Eitaa mini-app authentication — official decoded key=value HMAC contract.
 * Sources: developer.eitaa.com/docs/Develop/AuthorizationViaHash and /JsSDK.
 * No SDK/unsafe user/contact/phone is trusted before this server verifier.
 * Existing users/login_events columns are reused. Only TWO additive tables.
 */
declare(strict_types=1);

if (!function_exists('melkinoEitaaVerifyInitDataEx')) {
    /** $now is an injectable clock for CLI tests, never taken from an HTTP request. */
    function melkinoEitaaVerifyInitDataEx(string $input, string $token, ?int $now = null): array
    {
        $bad = static fn(string $error): array => ['user' => null, 'error' => $error];
        if ($input === '' || trim($token) === '') return $bad('empty');
        if (strlen($input) > 32768) return $bad('too_large');
        $pairs = [];
        foreach (explode('&', $input) as $segment) {
            if ($segment === '' || strpos($segment, '=') === false) return $bad('bad_format');
            [$key, $value] = explode('=', $segment, 2);
            if (preg_match('/%(?![0-9a-fA-F]{2})/', $key . $value)) return $bad('bad_encoding');
            $key = urldecode($key);
            $value = urldecode($value);
            // Avoid parse_str key rewriting, duplicate-key ambiguity and newline injection.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)
                || array_key_exists($key, $pairs) || preg_match('/[\x00\r\n]/', $value)) {
                return $bad('bad_format');
            }
            $pairs[$key] = $value;
            if (count($pairs) > 64) return $bad('bad_format');
        }
        $hash = $pairs['hash'] ?? '';
        if (!preg_match('/^[a-fA-F0-9]{64}$/D', $hash)) return $bad('bad_hash');
        unset($pairs['hash']); // All OTHER fields, including device_id/start_param, stay signed.
        ksort($pairs, SORT_STRING);
        $check = [];
        foreach ($pairs as $key => $value) $check[] = $key . '=' . $value;
        $secret = hash_hmac('sha256', trim($token), 'WebAppData', true);
        if (!hash_equals(hash_hmac('sha256', implode("\n", $check), $secret), strtolower($hash))) {
            return $bad('bad_hash');
        }
        $auth = $pairs['auth_date'] ?? '';
        if (!preg_match('/^[1-9][0-9]{8,10}$/D', $auth)) return $bad('bad_date');
        $now = $now ?? time();
        $authDate = (int)$auth;
        if ($authDate > $now + 60) return $bad('future');
        if ($now - $authDate > 600) return $bad('expired');
        $user = json_decode($pairs['user'] ?? '', true, 16, JSON_BIGINT_AS_STRING);
        if (!is_array($user) || !isset($user['id'])
            || !(is_int($user['id']) || is_string($user['id']))) return $bad('no_user_id');
        $id = (string)$user['id'];
        $maxId = '4503599627370495'; // Official WebAppUser contract: at most 52 significant bits.
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id)
            || (strlen($id) === strlen($maxId) && strcmp($id, $maxId) > 0)) return $bad('no_user_id');
        foreach (['first_name','last_name','username','language_code','photo_url'] as $field) {
            if (isset($user[$field]) && !is_string($user[$field])) return $bad('bad_user');
        }
        $username = (string)($user['username'] ?? '');
        // Username is NOT guaranteed by Eitaa. Numeric user.id is the identity.
        if ($username !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $username)) {
            $username = '';
        }
        return [
            'error' => null,
            'hash_sha256' => hash('sha256', strtolower($hash)),
            'auth_date' => $authDate,
            'expires_at' => $authDate + 600,
            'user' => [
                'id' => $id,
                'first_name' => mb_substr((string)($user['first_name'] ?? ''), 0, 100),
                'last_name' => mb_substr((string)($user['last_name'] ?? ''), 0, 100),
                'username' => $username,
                'language_code' => mb_substr((string)($user['language_code'] ?? ''), 0, 10),
                'auth_date' => $authDate,
                'allows_write_to_pm' => ($user['allows_write_to_pm'] ?? false) === true,
            ],
        ];
    }
}

if (!function_exists('melkinoEitaaEnsureTables')) {
    function melkinoEitaaEnsureTables(PDO $pdo): void
    {
        // Do not ALTER existing tables, nor run DDL inside the login transaction.
        foreach ([
            'melkino_eitaa_accounts' => 'CREATE TABLE IF NOT EXISTS melkino_eitaa_accounts (
                eitaa_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                user_id INT UNSIGNED NULL,
                PRIMARY KEY (eitaa_id), KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'melkino_eitaa_auth_uses' => 'CREATE TABLE IF NOT EXISTS melkino_eitaa_auth_uses (
                init_hash_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                binding_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                user_id INT UNSIGNED NULL,
                expires_at BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (init_hash_sha256), KEY idx_expiry (expires_at), KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ] as $table => $ddl) {
            try {
                $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1146) throw $e;
                $pdo->exec($ddl);
            }
        }
    }
}

if (!function_exists('melkinoEitaaExchange')) {
    /**
     * Called ONLY with the result of the server-side verifier.
     * Per-account row lock works even on old users schemas without a unique eitaa_id.
     * Per-proof row prevents cross-session replay; same authenticated session may retry.
     * No phone/account merge, no raw initData storage and no external message sending.
     */
    function melkinoEitaaExchange(PDO $pdo, array $proof, string $binding, int $sessionUid): array
    {
        if (empty($proof['user']) || !empty($proof['error']) || $binding === '') {
            throw new RuntimeException('bad_proof', 401);
        }
        melkinoEitaaEnsureTables($pdo);
        $pdo->prepare('DELETE FROM melkino_eitaa_auth_uses WHERE expires_at < ?')->execute([time()]);
        $u = $proof['user'];
        $eid = (string)$u['id'];
        $bindingHash = hash('sha256', $binding);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO melkino_eitaa_accounts (eitaa_id) VALUES (?)
                ON DUPLICATE KEY UPDATE eitaa_id = VALUES(eitaa_id)')->execute([$eid]);
            $lock = $pdo->prepare('SELECT user_id FROM melkino_eitaa_accounts WHERE eitaa_id = ? FOR UPDATE');
            $lock->execute([$eid]);
            $lock->fetchColumn();

            $pdo->prepare('INSERT INTO melkino_eitaa_auth_uses (init_hash_sha256,binding_sha256,expires_at)
                VALUES (?,?,?) ON DUPLICATE KEY UPDATE init_hash_sha256 = VALUES(init_hash_sha256)')
                ->execute([$proof['hash_sha256'], $bindingHash, $proof['expires_at']]);
            $used = $pdo->prepare('SELECT binding_sha256,user_id FROM melkino_eitaa_auth_uses WHERE init_hash_sha256 = ? FOR UPDATE');
            $used->execute([$proof['hash_sha256']]);
            $use = $used->fetch(PDO::FETCH_ASSOC);
            if (!$use || !hash_equals((string)$use['binding_sha256'], $bindingHash)) {
                throw new RuntimeException('replayed', 409);
            }
            $st = $pdo->prepare('SELECT id,eitaa_id,eitaa_username,telegram_id,bale_id,phone,name,is_active,name_locked
                FROM users WHERE eitaa_id = ? ORDER BY id ASC LIMIT 2');
            $st->execute([$eid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 1) throw new RuntimeException('duplicate_identity', 409);
            $row = $rows[0] ?? null;
            if ($row && (int)$row['is_active'] !== 1) throw new RuntimeException('account_disabled', 403);
            if (!empty($use['user_id'])) {
                if (!$row || (int)$use['user_id'] !== $sessionUid || (int)$row['id'] !== $sessionUid) {
                    throw new RuntimeException('replayed', 409);
                }
                $pdo->commit();
                return ['user' => $row, 'reused' => true];
            }
            $name = mb_substr(trim($u['first_name'] . ' ' . $u['last_name']), 0, 200);
            if (!$row) {
                $pdo->prepare('INSERT INTO users (eitaa_id,eitaa_username,first_name,last_name,name,
                    is_active,first_login,created_at,login_count) VALUES (?,?,?,?,?,1,NOW(),NOW(),0)')
                    ->execute([$eid, $u['username'] ?: null, $u['first_name'], $u['last_name'], $name]);
                $uid = (int)$pdo->lastInsertId();
            } else {
                $uid = (int)$row['id'];
            }
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
            $pdo->prepare("UPDATE users SET
                first_name = IF(name_locked = 0, ?, first_name),
                last_name = IF(name_locked = 0, ?, last_name),
                name = IF(name_locked = 0 AND ? <> '', ?, name),
                eitaa_username = IF(? <> '', ?, eitaa_username),
                language_code = IF(? <> '', ?, language_code),
                last_platform = 'eitaa', last_ip = ?, user_agent = ?,
                last_login = NOW(), login_count = login_count + 1, updated_at = NOW()
                WHERE id = ?")
                ->execute([$u['first_name'], $u['last_name'], $name, $name,
                    $u['username'], $u['username'], $u['language_code'], $u['language_code'], $ip, $ua, $uid]);
            $pdo->prepare('UPDATE melkino_eitaa_accounts SET user_id = ? WHERE eitaa_id = ?')->execute([$uid, $eid]);
            $pdo->prepare('UPDATE melkino_eitaa_auth_uses SET user_id = ? WHERE init_hash_sha256 = ?')
                ->execute([$uid, $proof['hash_sha256']]);
            // One actual successful authentication, not one per SDK retry/page load.
            $pdo->prepare("INSERT INTO login_events
                (user_id,eitaa_id,username,name,platform,ip_address,ip,user_agent,language_code,created_at)
                VALUES (?,?,?,?,'eitaa',?,?,?,?,NOW())")
                ->execute([$uid, $eid, $u['username'], mb_substr($name, 0, 191), $ip, $ip, $ua, $u['language_code']]);
            $st->execute([$eid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $pdo->commit();
            return ['user' => $row, 'reused' => false];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('melkinoEitaaSafeTarget')) {
    function melkinoEitaaSafeTarget($value): string
    {
        if (!is_string($value) || strlen($value) > 512) return 'home.php';
        // Only root public PHP pages. No scheme, slash, backslash, fragment or traversal.
        if (!preg_match('/^[a-zA-Z0-9_-]+\.php(?:\?[a-zA-Z0-9_=&%.,+-]*)?$/D', $value)) return 'home.php';
        $path = strtok($value, '?');
        if (preg_match('/^(?:admin|auth|login|logout|eitaa-app|office)/i', $path)) return 'home.php';
        parse_str((string)(parse_url($value, PHP_URL_QUERY) ?? ''), $q);
        foreach (array_keys($q) as $key) {
            if (in_array(strtolower((string)$key), ['t','action','redirect','csrf_token'], true)
                || stripos((string)$key, 'tgwebapp') === 0) return 'home.php';
        }
        return $value;
    }
}

if (!function_exists('melkinoEitaaContext')) {
    /** Routing hint only; never proof of identity. */
    function melkinoEitaaContext(): bool
    {
        if (!empty($_SESSION['melkino_eitaa_context'])) return true;
        if (($_GET['messenger'] ?? '') === 'eitaa') return true;
        return stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'eitaa') !== false;
    }
}
