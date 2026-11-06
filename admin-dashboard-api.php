<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
if (is_file(__DIR__ . '/jalali-lib.php')) {
    require_once __DIR__ . '/jalali-lib.php';
}
if (is_file(__DIR__ . '/visit-request-lib.php')) {
    require_once __DIR__ . '/visit-request-lib.php';
}

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tz = new DateTimeZone('Asia/Tehran');
$now = new DateTime('now', $tz);
$today = $now->format('Y-m-d');
$tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');

$faDate = static function (?string $iso) {
    if (!$iso) {
        return '—';
    }
    if (function_exists('melkinoFormatTehranFa')) {
        try {
            return melkinoFormatTehranFa($iso);
        } catch (Throwable $e) {
        }
    }
    return $iso;
};

$out = [
    'success' => true,
    'now' => [
        'iso' => $now->format('c'),
        'date' => $today,
        'time' => $now->format('H:i:s'),
        'weekday' => $now->format('N'),
        'date_fa' => $faDate($now->format('Y-m-d H:i:s')),
    ],
    'pending_ads' => [],
    'pending_ads_count' => 0,
    'pending_partnership' => [],
    'pending_partnership_count' => 0,
    'new_requests' => [],
    'new_requests_count' => 0,
    'new_visits' => [],
    'new_visits_count' => 0,
    'new_tickets' => [],
    'new_tickets_count' => 0,
    'visits_today' => [],
    'visits_tomorrow' => [],
];

if (!($pdo instanceof PDO)) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $st = $pdo->query("SELECT id, title, status, property_type, transaction_type, created_at FROM ads WHERE status IN ('pending','new','waiting') ORDER BY created_at DESC, id DESC LIMIT 12");
    $out['pending_ads'] = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $c = $pdo->query("SELECT COUNT(*) FROM ads WHERE status IN ('pending','new','waiting')");
    $out['pending_ads_count'] = $c ? (int) $c->fetchColumn() : count($out['pending_ads']);
} catch (Throwable $e) {
    try {
        $st = $pdo->query("SELECT id, title, status, created_at FROM ads WHERE status = 'pending' ORDER BY id DESC LIMIT 12");
        $out['pending_ads'] = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $out['pending_ads_count'] = count($out['pending_ads']);
    } catch (Throwable $e2) {
    }
}

try {
    $st = $pdo->query("SELECT id, code, title, status, property_type, created_at FROM partnership_requests WHERE status = 'pending' ORDER BY created_at DESC, id DESC LIMIT 12");
    $out['pending_partnership'] = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $c = $pdo->query("SELECT COUNT(*) FROM partnership_requests WHERE status = 'pending'");
    $out['pending_partnership_count'] = $c ? (int) $c->fetchColumn() : count($out['pending_partnership']);
    // مشارکت در ساخت هم نوعی آگهی است و در صف تأیید نمایش داده می‌شود؛
    // بنابراین شمارندهٔ «آگهی منتظر تأیید» باید آن را هم بشمارد تا با
    // تعداد ردیف‌های لیست برابر بماند.
    $out['pending_ads_count'] += $out['pending_partnership_count'];
} catch (Throwable $e) {
}

try {
    $st = $pdo->query("SELECT id, tracking_code, status, name, phone, property_type, transaction_type, created_at FROM property_requests WHERE status IN ('new','جدید','') OR status IS NULL ORDER BY created_at DESC, id DESC LIMIT 12");
    $out['new_requests'] = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $c = $pdo->query("SELECT COUNT(*) FROM property_requests WHERE status IN ('new','جدید','') OR status IS NULL");
    $out['new_requests_count'] = $c ? (int) $c->fetchColumn() : count($out['new_requests']);
} catch (Throwable $e) {
    try {
        $st = $pdo->query('SELECT id, tracking_code, name, created_at FROM property_requests ORDER BY id DESC LIMIT 8');
        $out['new_requests'] = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $out['new_requests_count'] = count($out['new_requests']);
    } catch (Throwable $e2) {
    }
}

try {
    $st = $pdo->query("SELECT id, ad_id, ad_title, name, phone, status, preferred_date, time_slot, weekday, tracking_code, created_at FROM visit_requests WHERE COALESCE(archived,0)=0 AND (status = 'new' OR status IS NULL OR status = '') ORDER BY created_at DESC, id DESC LIMIT 12");
    $out['new_visits'] = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $c = $pdo->query("SELECT COUNT(*) FROM visit_requests WHERE COALESCE(archived,0)=0 AND (status = 'new' OR status IS NULL OR status = '')");
    $out['new_visits_count'] = $c ? (int) $c->fetchColumn() : count($out['new_visits']);
} catch (Throwable $e) {
}

$packVisit = static function (array $it) use ($pdo, $faDate): array {
    $when = '';
    if (function_exists('melkinoVisitWhenLabel')) {
        try {
            $when = melkinoVisitWhenLabel($it);
        } catch (Throwable $e) {
            $when = '';
        }
    }
    $parts = [];
    if (function_exists('melkinoVisitWhenParts')) {
        try {
            $parts = melkinoVisitWhenParts($it) ?: [];
        } catch (Throwable $e) {
        }
    }
    $title = trim((string) ($it['ad_title'] ?? ''));
    if ($title === '' && !empty($it['ad_id'])) {
        try {
            $s = $pdo->prepare('SELECT title FROM ads WHERE id = ? LIMIT 1');
            $s->execute([(string) $it['ad_id']]);
            $title = (string) $s->fetchColumn();
        } catch (Throwable $e) {
        }
    }
    $slot = (string) ($it['time_slot'] ?? '');
    $slotFa = $slot === 'morning' ? 'صبح' : ($slot === 'evening' ? 'عصر' : $slot);
    return [
        'id' => (int) ($it['id'] ?? 0),
        'ad_id' => (string) ($it['ad_id'] ?? ''),
        'ad_title' => $title !== '' ? $title : ('آگهی ' . ($it['ad_id'] ?? '')),
        'user' => trim((string) ($it['name'] ?? '')) ?: '—',
        'phone' => trim((string) ($it['phone'] ?? '')) ?: '—',
        'date' => (string) ($it['preferred_date'] ?? ''),
        'date_fa' => $parts['date'] ?? $faDate($it['preferred_date'] ?? null),
        'weekday' => $parts['weekday'] ?? (string) ($it['weekday'] ?? ''),
        'slot' => $slotFa,
        'when' => $when,
        'tracking_code' => (string) ($it['tracking_code'] ?? ''),
        'status' => (string) ($it['status'] ?? 'new'),
    ];
};

try {
    $st = $pdo->prepare("SELECT * FROM visit_requests WHERE COALESCE(archived,0)=0 AND preferred_date IN (?, ?) ORDER BY preferred_date ASC, time_slot ASC, id ASC");
    $st->execute([$today, $tomorrow]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $item = $packVisit($row);
        if (($row['preferred_date'] ?? '') === $today) {
            $out['visits_today'][] = $item;
        } else {
            $out['visits_tomorrow'][] = $item;
        }
    }
} catch (Throwable $e) {
}

try {
    $st = $pdo->query("SELECT id, subject, status, created_at FROM support_tickets WHERE status IN ('open','new','answered') ORDER BY created_at DESC, id DESC LIMIT 12");
    $out['new_tickets'] = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $c = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','new','answered')");
    $out['new_tickets_count'] = $c ? (int) $c->fetchColumn() : count($out['new_tickets']);
} catch (Throwable $e) {
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
