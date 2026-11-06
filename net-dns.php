<?php
/**
 * ملکینو — بازیابی خودکار DNS و انتقال HTTP مقاوم برای سرویس‌های پیام‌رسان
 * ---------------------------------------------------------------------------
 * مشکل: روی بعضی هاست‌ها نام api.telegram.org / tapi.bale.ai / eitaayar.ir در
 * DNS محلی حل نمی‌شود (خطای کد شبکه ۶) در حالی که خود سرویس در دسترس است.
 *
 * راه‌حل خودکار (بدون نیاز به تنظیم ادمین):
 *   ۱) اگر پروکسی ثبت شده باشد، همه‌چیز مثل قبل از پروکسی می‌رود.
 *   ۲) اتصال مستقیم؛ اگر DNS شکست خورد (کد ۶) یا مقصد در دسترس نبود (۷/۲۸):
 *      - کش محلی IPهای حل‌شده (۶ ساعته، خارج از مسیر وب‌روت)
 *      - DNS سیستم
 *      - پرس‌وجوی DNS روی UDP به resolverهای عمومی (8.8.8.8 / 1.1.1.1 / 9.9.9.9)
 *      - DNS-over-HTTPS روی IPهای عمومی resolverها (نیازی به حل شدن نام خودشان نیست)
 *      - در نهایت فهرست کمکی IPهای شناخته‌شده (فقط api.telegram.org)
 *   ۳) تلاش دوباره با «سنجاق IP» (CURLOPT_RESOLVE) در حالی که نام میزبان/SNI و
 *      اعتبارسنجی گواهی TLS مثل قبل روی خودِ نام باقی می‌ماند؛ پس اگر IP
 *      جعلی/فیلترشده باشد TLS برقرار نمی‌شود و توکن هرگز ارسال نمی‌شود.
 *
 * هیچ توکن/رمزی در این فایل یا کش ذخیره نمی‌شود — فقط IP عمومی سرویس‌ها.
 */

declare(strict_types=1);

if (!function_exists('melkinoDnsCacheDir')) {

    /** شاخهٔ کش DNS (ابتدا tmp سیستم؛ در صورت عدم دسترسی، شاخهٔ مخفی کنار فایل). */
    function melkinoDnsCacheDir(): string
    {
        $override = $GLOBALS['MELKINO_DNS_CACHE_DIR'] ?? '';
        if (is_string($override) && $override !== '') {
            if (!is_dir($override)) {
                @mkdir($override, 0700, true);
            }
            return $override;
        }
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'melkino-netdns';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = __DIR__ . DIRECTORY_SEPARATOR . '.netdns-cache';
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }
        return $dir;
    }

    /** خواندن کش IPهای حل‌شده (TTL شش ساعته). @return array{ips:string[],source:string,ts:int} */
    function melkinoDnsCacheRead(string $host): array
    {
        $file = melkinoDnsCacheDir() . DIRECTORY_SEPARATOR . 'dns-' . sha1(strtolower($host)) . '.json';
        if (!is_file($file)) {
            return ['ips' => [], 'source' => '', 'ts' => 0];
        }
        $j = json_decode((string)@file_get_contents($file), true);
        if (!is_array($j) || !is_array($j['ips'] ?? null)) {
            return ['ips' => [], 'source' => '', 'ts' => 0];
        }
        $ts = (int)($j['ts'] ?? 0);
        if ($ts <= 0 || (time() - $ts) > 21600) {
            return ['ips' => [], 'source' => '', 'ts' => $ts];
        }
        $ips = array_values(array_filter(array_map('strval', $j['ips']), static function ($ip): bool {
            return (bool)filter_var($ip, FILTER_VALIDATE_IP);
        }));
        return ['ips' => $ips, 'source' => (string)($j['source'] ?? ''), 'ts' => $ts];
    }

    function melkinoDnsCacheWrite(string $host, array $ips, string $source): void
    {
        $ips = array_values(array_filter(array_map('strval', $ips), static function ($ip): bool {
            return (bool)filter_var($ip, FILTER_VALIDATE_IP);
        }));
        if ($ips === []) {
            return;
        }
        $dir = melkinoDnsCacheDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'dns-' . sha1(strtolower($host)) . '.json';
        @file_put_contents($file, json_encode(['ips' => $ips, 'source' => $source, 'ts' => time()]), LOCK_EX);
    }

    /** حذف کش یک میزبان (برای زمانی که IPها عوض شده‌اند). */
    function melkinoDnsCacheClear(string $host = ''): void
    {
        $dir = melkinoDnsCacheDir();
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'dns-*.json') ?: [] as $f) {
            if ($host === '' || basename($f) === 'dns-' . sha1(strtolower($host)) . '.json') {
                @unlink($f);
            }
        }
    }

    /** حل نام با DNS سیستم (بدون شبکهٔ دست‌ساز). @return string[] */
    function melkinoDnsResolveSystem(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $rec = @dns_get_record($host, DNS_A);
            if (is_array($rec)) {
                foreach ($rec as $r) {
                    if (!empty($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = (string)$r['ip'];
                    }
                }
            }
        }
        if ($ips === []) {
            $h = @gethostbyname($host);
            if (is_string($h) && $h !== $host && filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $h;
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * پرس‌وجوی DNS روی UDP (رکورد A) به یک resolver مشخص — بدون نیاز به DNS.
     * @return string[] IPهای A
     */
    function melkinoDnsQueryUdp(string $host, string $resolverIp, float $timeout = 1.5): array
    {
        if (!function_exists('stream_socket_client')) {
            return [];
        }
        $host = strtolower(trim($host));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return [];
        }
        $qname = '';
        foreach (explode('.', rtrim($host, '.')) as $label) {
            if ($label === '') {
                continue;
            }
            $qname .= chr(strlen($label)) . $label;
        }
        $qname .= "\x00";
        $id = random_int(0, 0xFFFF);
        // هدر: ID، پرچم RD، یک پرس‌وجو، صفر پاسخ/authority/additional
        $packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0) . $qname . pack('nn', 1, 1); // TYPE A, CLASS IN
        $errno = 0;
        $err = '';
        $fp = @stream_socket_client('udp://' . $resolverIp . ':53', $errno, $err, $timeout);
        if (!is_resource($fp)) {
            return [];
        }
        stream_set_timeout($fp, (int)$timeout, (int)round(($timeout - (int)$timeout) * 1e6));
        @fwrite($fp, $packet);
        $resp = (string)@fread($fp, 2048);
        @fclose($fp);
        if (strlen($resp) < 12) {
            return [];
        }
        $ips = [];
        $len = strlen($resp);
        $pos = 12;
        $qd = unpack('n', substr($resp, 4, 2))[1];
        $an = unpack('n', substr($resp, 6, 2))[1];
        // رد شدن از بخش پرس‌وجو
        for ($i = 0; $i < $qd; $i++) {
            while ($pos < $len && ord($resp[$pos]) !== 0) {
                $l = ord($resp[$pos]);
                if (($l & 0xC0) === 0xC0) {
                    $pos += 2;
                    continue 2;
                }
                $pos += $l + 1;
            }
            $pos += 5; // صفرِ انتهای نام + type + class
        }
        for ($i = 0; $i < $an && $pos + 12 <= $len; $i++) {
            if ((ord($resp[$pos]) & 0xC0) === 0xC0) {
                $pos += 2;
            } else {
                while ($pos < $len && ord($resp[$pos]) !== 0) {
                    $pos += ord($resp[$pos]) + 1;
                }
                $pos += 1;
            }
            if ($pos + 10 > $len) {
                break;
            }
            $type = unpack('n', substr($resp, $pos, 2))[1];
            $rdlen = unpack('n', substr($resp, $pos + 8, 2))[1];
            $pos += 10;
            if ($pos + $rdlen > $len) {
                break;
            }
            $rdata = substr($resp, $pos, $rdlen);
            $pos += $rdlen;
            if ($type === 1 && $rdlen === 4) {
                $ip = inet_ntop($rdata);
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * پرس‌وجوی DNS-over-HTTPS (JSON) با سنجاق IP خودِ resolver تا به DNS نیاز نباشد.
     * هم پاسخ Google (dns.google/resolve) و هم Cloudflare (dns-query) پشتیبانی می‌شود.
     * @return string[] IPهای A
     */
    function melkinoDnsQueryDoh(string $host, string $dohHost, string $pinIp): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }
        $host = strtolower(trim($host));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return [];
        }
        $path = $dohHost === 'cloudflare-dns.com'
            ? '/dns-query?name=' . rawurlencode($host) . '&type=A'
            : '/resolve?name=' . rawurlencode($host) . '&type=A';
        $url = 'https://' . $dohHost . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/dns-json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_RESOLVE')) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$dohHost . ':443:' . $pinIp]);
        }
        $body = curl_exec($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            return [];
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return [];
        }
        $ips = [];
        foreach ((array)($j['Answer'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            if ((int)($a['type'] ?? 0) === 1 && is_string($a['data'] ?? null)
                && filter_var($a['data'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = (string)$a['data'];
            }
        }
        return array_values(array_unique($ips));
    }

    /** فهرست کمکی IPهای شناخته‌شده — فقط به‌عنوان آخرین گام (TLS هویت را تأیید می‌کند). @return string[] */
    function melkinoDnsBootstrapIps(string $host): array
    {
        $map = [
            // IPهای عمومیِ طولانی‌عمر endpoint تلگرام (در صورت تغییر، TLS آن‌ها را رد می‌کند و ضرری ندارد)
            'api.telegram.org' => ['149.154.167.220', '91.108.56.130'],
        ];
        return $map[strtolower(trim($host))] ?? [];
    }

    /**
     * حل هوشمند نام میزبان: کش ← DNS سیستم ← UDP ← DoH ← فهرست کمکی.
     * @return array{ips:string[],source:string,tried:string[]}
     */
    function melkinoDnsResolveSmart(string $host, ?callable $fetch = null): array
    {
        $host = strtolower(trim($host));
        $tried = [];
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return ['ips' => [], 'source' => 'none', 'tried' => ['invalid-host']];
        }

        // ۱) کش
        $cache = melkinoDnsCacheRead($host);
        if ($cache['ips'] !== []) {
            return ['ips' => $cache['ips'], 'source' => 'cache', 'tried' => ['cache']];
        }

        // ۲) DNS سیستم
        $sysFn = $GLOBALS['MELKINO_DNS_SYSTEM'] ?? null;
        $tried[] = 'system';
        $ips = is_callable($sysFn) ? array_values(array_filter((array)$sysFn($host))) : melkinoDnsResolveSystem($host);
        if ($ips !== []) {
            melkinoDnsCacheWrite($host, $ips, 'system');
            return ['ips' => $ips, 'source' => 'system', 'tried' => $tried];
        }

        // ۳) DNS روی UDP به resolverهای عمومی
        $udpFn = $GLOBALS['MELKINO_DNS_UDP'] ?? null;
        foreach (['8.8.8.8', '1.1.1.1', '9.9.9.9'] as $resolver) {
            $tried[] = 'udp:' . $resolver;
            $ips = is_callable($udpFn)
                ? array_values(array_filter((array)$udpFn($host, $resolver)))
                : melkinoDnsQueryUdp($host, $resolver);
            if ($ips !== []) {
                melkinoDnsCacheWrite($host, $ips, 'udp:' . $resolver);
                return ['ips' => $ips, 'source' => 'udp:' . $resolver, 'tried' => $tried];
            }
        }

        // ۴) DNS-over-HTTPS روی IPهای عمومی (سنجاق‌شده)
        $fetch = $fetch ?? ($GLOBALS['MELKINO_DNS_FETCH'] ?? null);
        foreach ([['dns.google', '8.8.8.8'], ['dns.google', '8.8.4.4'], ['cloudflare-dns.com', '1.1.1.1']] as $doh) {
            [$dohHost, $pinIp] = $doh;
            $tried[] = 'doh:' . $dohHost . '@' . $pinIp;
            $ips = is_callable($fetch)
                ? array_values(array_filter((array)$fetch($host, $dohHost, $pinIp)))
                : melkinoDnsQueryDoh($host, $dohHost, $pinIp);
            if ($ips !== []) {
                melkinoDnsCacheWrite($host, $ips, 'doh:' . $dohHost);
                return ['ips' => $ips, 'source' => 'doh:' . $dohHost, 'tried' => $tried];
            }
        }

        // ۵) فهرست کمکی
        $boot = melkinoDnsBootstrapIps($host);
        if ($boot !== []) {
            $tried[] = 'bootstrap';
            melkinoDnsCacheWrite($host, $boot, 'bootstrap');
            return ['ips' => $boot, 'source' => 'bootstrap', 'tried' => $tried];
        }
        return ['ips' => [], 'source' => 'none', 'tried' => $tried];
    }

    /**
     * فال‌بکِ file_get_contents برای هاست‌های بدون curl — با پشتیبانی سنجاق IP
     * (نام/SNI و اعتبارسنجی گواهی روی نام اصلی می‌ماند).
     * @return array{http:int,errno:int,err:string,body:string}
     */
    function melkinoNetFgc(string $url, $post, array $opts, string $proxy, string $resolveIp): array
    {
        if (is_array($post)) {
            return ['http' => 0, 'errno' => 2, 'err' => 'multipart requires curl', 'body' => ''];
        }
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        $headers = (array)($opts['headers'] ?? []);
        if ($host !== '' && $resolveIp !== '') {
            $headers[] = 'Host: ' . $host; // نام اصلی برای هدر Host
        }
        $httpOptions = [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => (string)$post,
            'timeout' => (int)($opts['timeout'] ?? 20),
            'ignore_errors' => true,
        ];
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($host !== '') {
            $ssl['peer_name'] = $host;
        }
        $ctx = ['http' => $httpOptions, 'ssl' => $ssl];
        if ($proxy !== '') {
            // فال‌بکِ stream فقط از پروکسیِ HTTP پشتیبانی می‌کند
            $httpOptions['proxy'] = preg_replace('#^https?://#i', 'tcp://', $proxy);
            $httpOptions['request_fulluri'] = true;
            $ctx = ['http' => $httpOptions, 'ssl' => $ssl];
        }
        $target = $url;
        if ($proxy === '' && $resolveIp !== '' && $host !== '') {
            $scheme = (string)(parse_url($url, PHP_URL_SCHEME) ?? 'https');
            $path = (string)(parse_url($url, PHP_URL_PATH) ?? '/');
            $query = parse_url($url, PHP_URL_QUERY);
            $target = $scheme . '://' . $resolveIp . $path . (is_string($query) && $query !== '' ? '?' . $query : '');
        }
        $context = stream_context_create($ctx);
        $response = @file_get_contents($target, false, $context);
        if ($response === false) {
            return ['http' => 0, 'errno' => $resolveIp === '' ? 6 : 7, 'err' => 'stream failed', 'body' => ''];
        }
        $status = 0;
        foreach ((array)($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m)) {
                $status = (int)$m[1];
            }
        }
        return ['http' => $status, 'errno' => 0, 'err' => '', 'body' => (string)$response];
    }

    /**
     * یک تلاش curl با پروکسی یا سنجاق IP مشخص.
     * @return array{http:int,errno:int,err:string,body:string}
     */
    function melkinoNetHttpOne(string $url, $post, array $opts, string $proxy, string $resolveIp): array
    {
        $runner = $opts['runner'] ?? null;
        if (is_callable($runner)) {
            return $runner($url, $post, $opts, $proxy, $resolveIp);
        }
        if (!function_exists('curl_init')) {
            return melkinoNetFgc($url, $post, $opts, $proxy, $resolveIp);
        }
        $isMultipart = is_array($post);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)($opts['timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => (int)($opts['connect_timeout'] ?? 10),
            CURLOPT_HTTPHEADER => (array)($opts['headers'] ?? []),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if ($isMultipart && defined('CURLOPT_SAFE_UPLOAD')) {
            curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
        }
        if ($proxy !== '') {
            if (defined('CURLOPT_PROXY')) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }
            $lower = strtolower($proxy);
            if (strpos($lower, 'socks5h://') === 0 && defined('CURLPROXY_SOCKS5_HOSTNAME')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            } elseif (strpos($lower, 'socks5://') === 0 && defined('CURLPROXY_SOCKS5')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            } elseif (strpos($lower, 'socks4://') === 0 && defined('CURLPROXY_SOCKS4')) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
            }
        } elseif ($resolveIp !== '' && defined('CURLOPT_RESOLVE')) {
            $scheme = (string)(parse_url($url, PHP_URL_SCHEME) ?? 'https');
            $port = (int)(parse_url($url, PHP_URL_PORT) ?? ($scheme === 'http' ? 80 : 443));
            $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
            if ($host !== '') {
                curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $resolveIp]);
            }
        }
        $result = curl_exec($ch);
        $errno = (int)curl_errno($ch);
        $err = (string)curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['http' => $http, 'errno' => $errno, 'err' => $err, 'body' => is_string($result) ? $result : ''];
    }

    /**
     * HTTP مقاوم: پروکسی (در صورت تنظیم) ← مستقیم ← تلاش مجدد با سنجاق IP پس از
     * بازیابی DNS. خروجی: ok/http/errno/err/body/via/ip/resolve.
     * @return array{ok:bool,http:int,errno:int,err:string,body:string,via:string,ip:string,resolve:array{ips:string[],source:string,tried:string[]}}
     */
    function melkinoNetHttpCall(string $url, $post, array $opts = []): array
    {
        $proxy = array_key_exists('proxy', $opts)
            ? trim((string)$opts['proxy'])
            : (function_exists('melkinoProxy') ? trim((string)melkinoProxy()) : '');
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        $empty = [
            'ok' => false, 'http' => 0, 'errno' => 0, 'err' => '', 'body' => '',
            'via' => $proxy !== '' ? 'proxy' : 'direct', 'ip' => '',
            'resolve' => ['ips' => [], 'source' => '', 'tried' => []],
        ];

        $r = melkinoNetHttpOne($url, $post, $opts, $proxy, '');
        if ((int)$r['errno'] === 0) {
            return array_merge($empty, ['ok' => true, 'http' => (int)$r['http'], 'body' => (string)$r['body'],
                'via' => $proxy !== '' ? 'proxy' : 'direct']);
        }

        // با پروکسی، DNS را پروکسی انجام می‌دهد؛ بازیابی DNS فقط برای اتصال مستقیم است
        $retryable = $proxy === '' && $host !== '' && in_array((int)$r['errno'], [6, 7, 28], true);
        $resolveInfo = ['ips' => [], 'source' => '', 'tried' => []];
        if ($retryable) {
            $resolveInfo = melkinoDnsResolveSmart($host);
            foreach ($resolveInfo['ips'] as $ip) {
                $r2 = melkinoNetHttpOne($url, $post, $opts, '', (string)$ip);
                if ((int)$r2['errno'] === 0) {
                    return array_merge($empty, [
                        'ok' => true, 'http' => (int)$r2['http'], 'body' => (string)$r2['body'],
                        'via' => 'resolve', 'ip' => (string)$ip, 'resolve' => $resolveInfo,
                    ]);
                }
                $r = $r2; // آخرین خطا برای گزارش
            }
        }
        return array_merge($empty, [
            'ok' => false, 'http' => (int)($r['http'] ?? 0), 'errno' => (int)$r['errno'],
            'err' => (string)($r['err'] ?? ''), 'body' => (string)($r['body'] ?? ''),
            'via' => $proxy !== '' ? 'proxy' : 'direct',
            'resolve' => $resolveInfo,
        ]);
    }

    /**
     * متن خطای فارسی برای errnoهای شبکه — با در نظر گرفتن تلاش بازیابی DNS.
     */
    function melkinoNetErrHint(int $errno, array $call, string $host = ''): string
    {
        $resolved = !empty($call['resolve']['ips']);
        $host = $host !== '' ? $host : (string)($call['host'] ?? '');
        if ($errno === 6 && !$resolved) {
            return 'نام سرویس (' . $host . ') روی هاست حل نشد و بازیابی خودکار DNS '
                . '(UDP/DoH روی resolverهای عمومی) هم نتوانست نام را پیدا کند؛ '
                . 'دسترسی خروجی هاست به DNS عمومی هم مسدود است. در بخش «ربات‌ها و پیام‌رسان‌ها» '
                . '(تنظیمات ← پروکسی) یک پروکسی ثبت کنید یا هاست با دسترسی خروجی آزاد بگیرید.';
        }
        if ($errno === 6 && $resolved) {
            $hintDns = 'DNS با مسیر جایگزین بازیابی شد';
        } else {
            $hintDns = '';
        }
        if (in_array($errno, [7, 28, 35, 60], true) && $resolved) {
            $base = $errno === 7
                ? 'اتصال رد شد'
                : ($errno === 28 ? 'مهلت اتصال تمام شد' : 'اتصال TLS برقرار نشد');
            return $base . ' — آدرس IP سرویس با DNS جایگزین پیدا شد اما هاست نتوانست به آن وصل شود '
                . '(احتمالاً فیلتر در سطح IP/SNI). راه‌حل: در بخش «ربات‌ها و پیام‌رسان‌ها» '
                . '(تنظیمات ← پروکسی) یک پروکسی ثبت کنید.';
        }
        if ($errno === 7) {
            return 'اتصال رد شد — دسترسی هاست به سرویس مسدود است؛ پروکسی را در بخش «ربات‌ها و پیام‌رسان‌ها» تنظیم کنید.';
        }
        if ($errno === 28) {
            return 'مهلت اتصال تمام شد — احتمال فیروال/مسدود بودن سرویس؛ پروکسی را در بخش «ربات‌ها و پیام‌رسان‌ها» تنظیم کنید.';
        }
        if (in_array($errno, [35, 60], true)) {
            return 'اتصال TLS برقرار نشد — احتمال فیلتر SNI یا میانیِ گواهی؛ پروکسی را تنظیم کنید.';
        }
        return 'تنظیمات شبکه/پروکسی هاست را بررسی کنید.';
    }
}
