<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

// ── Auth ─────────────────────────────────────────────────────────────────────

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$reqCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $reqCsrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// ── Config ───────────────────────────────────────────────────────────────────

$config      = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$caldavCfg   = $config['caldav'];
$action      = $_GET['action'] ?? '';

// ── Dispatch ─────────────────────────────────────────────────────────────────

try {
    $caldav = new CalDAV(
        $caldavCfg['url'],
        $caldavCfg['username'],
        $caldavCfg['password'],
        $caldavCfg['calendar_home'] ?? null
    );

    if ($action === 'calendars') {
        echo json_encode(['calendars' => $caldav->getCalendars()]);

    } elseif ($action === 'events') {
        // Fetch from 1 month ago (for month-view backwards navigation) to 2 years ahead
        $start = new DateTime('-1 month');
        $end   = new DateTime('+2 years');

        $calendars  = $caldav->getCalendars();
        $allEvents  = [];

        foreach ($calendars as $cal) {
            $events    = $caldav->getEvents($cal['href'], $cal['color'], $start, $end);
            $allEvents = array_merge($allEvents, $events);
        }

        usort($allEvents, static function (array $a, array $b): int {
            $cmp = strcmp($a['start'], $b['start']);
            if ($cmp !== 0) return $cmp;
            return ($b['allDay'] <=> $a['allDay']); // all-day first within same day
        });

        echo json_encode(['events' => $allEvents]);

    } elseif ($action === 'update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['href'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing href']);
            exit;
        }
        $caldav->updateEvent($data['href'], [
            'summary'      => $data['summary']      ?? '',
            'start'        => $data['start']         ?? '',
            'end'          => $data['end']            ?? '',
            'allDay'       => (bool) ($data['allDay'] ?? false),
            'description'  => $data['description']   ?? '',
            'location'     => $data['location']      ?? '',
            'calendarHref' => $data['calendarHref']  ?? '',
        ]);
        echo json_encode(['ok' => true]);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ═════════════════════════════════════════════════════════════════════════════
// CalDAV client
// ═════════════════════════════════════════════════════════════════════════════

class CalDAV
{
    private string  $baseUrl;
    private string  $username;
    private string  $password;
    private string  $schemeHost;
    private ?string $calendarHome;

    /** Colour palette for calendars that have no server-assigned colour */
    private array $palette = [
        '#3B82F6', '#8B5CF6', '#10B981', '#F59E0B',
        '#EF4444', '#EC4899', '#06B6D4', '#6366F1',
        '#84CC16', '#F97316',
    ];

    public function __construct(
        string  $url,
        string  $username,
        string  $password,
        ?string $calendarHome = null
    ) {
        $this->baseUrl      = rtrim($url, '/');
        $this->username     = $username;
        $this->password     = $password;
        $this->calendarHome = $calendarHome ? rtrim($calendarHome, '/') : null;

        $parsed = parse_url($this->baseUrl);
        $this->schemeHost = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $this->schemeHost .= ':' . $parsed['port'];
        }
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    private function curlRequest(
        string $method,
        string $url,
        string $body = '',
        array  $extraHeaders = []
    ): array {
        if (!str_starts_with($url, 'http')) {
            $url = $this->schemeHost . $url;
        }

        $ch = curl_init($url);

        // Build headers; default Content-Type only when there's a body and caller
        // hasn't supplied their own.
        $extraKeys = array_map('strtolower', array_keys($extraHeaders));
        $headers   = [];
        if ($body !== '' && !in_array('content-type', $extraKeys, true)) {
            $headers[] = 'Content-Type: application/xml; charset=utf-8';
        }
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_HTTPAUTH       => CURLAUTH_ANY,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,   // include response headers in output
        ]);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw     = curl_exec($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("cURL error: $error");
        }

        $rawStr  = (string) $raw;
        $respHdr = substr($rawStr, 0, $hdrSize);
        $respBody = substr($rawStr, $hdrSize);

        $etag = null;
        if (preg_match('/^ETag:\s*("?[^"\r\n]+"?)/mi', $respHdr, $m)) {
            $etag = trim($m[1]);
        }

        return ['status' => $status, 'body' => $respBody, 'etag' => $etag];
    }

    private function propfind(string $url, string $body, int $depth = 0): DOMDocument
    {
        $result = $this->curlRequest('PROPFIND', $url, $body, ['Depth' => (string) $depth]);
        if ($result['status'] >= 400) {
            throw new RuntimeException("PROPFIND {$result['status']} on $url");
        }
        return $this->parseXml($result['body']);
    }

    private function report(string $url, string $body): DOMDocument
    {
        $result = $this->curlRequest('REPORT', $url, $body, ['Depth' => '1']);
        if ($result['status'] >= 400) {
            throw new RuntimeException("REPORT {$result['status']} on $url");
        }
        return $this->parseXml($result['body']);
    }

    private function parseXml(string $xml): DOMDocument
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml)) {
            $err = libxml_get_last_error();
            libxml_clear_errors();
            throw new RuntimeException('Invalid XML: ' . ($err ? $err->message : 'unknown'));
        }
        libxml_clear_errors();
        return $dom;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('d',  'DAV:');
        $xp->registerNamespace('c',  'urn:ietf:params:xml:ns:caldav');
        $xp->registerNamespace('cs', 'http://calendarserver.org/ns/');
        $xp->registerNamespace('ic', 'http://apple.com/ns/ical/');
        return $xp;
    }

    private function resolveHref(string $href): string
    {
        return str_starts_with($href, 'http') ? $href : $this->schemeHost . $href;
    }

    // ── Discovery ────────────────────────────────────────────────────────────

    private function discoverCalendarHome(): string
    {
        if ($this->calendarHome !== null) {
            return $this->calendarHome;
        }

        // Step 1: current-user-principal
        $xml1 = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>';

        $principalHref = null;
        try {
            $dom  = $this->propfind($this->baseUrl, $xml1, 0);
            $xp   = $this->xpath($dom);
            $node = $xp->query('//d:current-user-principal/d:href')->item(0);
            if ($node) $principalHref = trim($node->textContent);
        } catch (Throwable) {}

        // Fallback: try well-known
        if (!$principalHref) {
            try {
                $wk   = $this->schemeHost . '/.well-known/caldav';
                $dom  = $this->propfind($wk, $xml1, 0);
                $xp   = $this->xpath($dom);
                $node = $xp->query('//d:current-user-principal/d:href')->item(0);
                if ($node) $principalHref = trim($node->textContent);
            } catch (Throwable) {}
        }

        if (!$principalHref) {
            $principalHref = $this->baseUrl;
        }

        // Step 2: calendar-home-set from principal
        $xml2 = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop><c:calendar-home-set/></d:prop>
</d:propfind>';

        try {
            $dom  = $this->propfind($this->resolveHref($principalHref), $xml2, 0);
            $xp   = $this->xpath($dom);
            $node = $xp->query('//c:calendar-home-set/d:href')->item(0);
            if ($node) return trim($node->textContent);
        } catch (Throwable) {}

        return $principalHref;
    }

    // ── Public API ───────────────────────────────────────────────────────────

    public function getCalendars(): array
    {
        $homeHref = $this->discoverCalendarHome();

        $xml = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"
            xmlns:cs="http://calendarserver.org/ns/" xmlns:ic="http://apple.com/ns/ical/">
    <d:prop>
        <d:displayname/>
        <d:resourcetype/>
        <ic:calendar-color/>
    </d:prop>
</d:propfind>';

        $dom  = $this->propfind($this->resolveHref($homeHref), $xml, 1);
        $xp   = $this->xpath($dom);

        $calendars   = [];
        $colorIndex  = 0;

        foreach ($xp->query('//d:response') as $resp) {
            // Must have a <c:calendar> resourcetype
            if ($xp->query('.//c:calendar', $resp)->length === 0) continue;

            $href  = trim($xp->query('d:href', $resp)->item(0)?->textContent ?? '');
            $name  = trim($xp->query('.//d:displayname', $resp)->item(0)?->textContent ?? '');
            $color = trim($xp->query('.//ic:calendar-color', $resp)->item(0)?->textContent ?? '');

            // Trim alpha channel (#RRGGBBAA → #RRGGBB)
            if (strlen($color) === 9 && $color[0] === '#') {
                $color = substr($color, 0, 7);
            }

            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = $this->palette[$colorIndex % count($this->palette)];
                $colorIndex++;
            }

            if (!$name) {
                $name = basename(rtrim($href, '/'));
            }

            $calendars[] = ['href' => $href, 'name' => $name, 'color' => $color];
        }

        return $calendars;
    }

    public function getEvents(
        string   $calendarHref,
        string   $color,
        DateTime $start,
        DateTime $end
    ): array {
        $startStr = $start->format('Ymd\T000000\Z');
        $endStr   = $end->format('Ymd\T235959\Z');

        $xml = '<?xml version="1.0" encoding="utf-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop>
        <d:getetag/>
        <c:calendar-data/>
    </d:prop>
    <c:filter>
        <c:comp-filter name="VCALENDAR">
            <c:comp-filter name="VEVENT">
                <c:time-range start="' . $startStr . '" end="' . $endStr . '"/>
            </c:comp-filter>
        </c:comp-filter>
    </c:filter>
</c:calendar-query>';

        $dom = $this->report($this->resolveHref($calendarHref), $xml);
        $xp  = $this->xpath($dom);

        $events = [];
        foreach ($xp->query('//d:response') as $resp) {
            $hrefNode = $xp->query('d:href', $resp)->item(0);
            $etagNode = $xp->query('.//d:getetag', $resp)->item(0);
            $calNode  = $xp->query('.//c:calendar-data', $resp)->item(0);
            if (!$calNode || !$hrefNode) continue;

            $eventHref = trim($hrefNode->textContent);
            $etag      = $etagNode ? trim($etagNode->textContent, '" ') : '';

            foreach ($this->parseICalendar($calNode->textContent) as $raw) {
                $result = $this->processEvent($raw, $eventHref, $etag, $calendarHref, $color, $start, $end);
                if ($result) {
                    $events = array_merge($events, $result);
                }
            }
        }

        return $events;
    }

    // ── iCalendar parser ─────────────────────────────────────────────────────

    private function parseICalendar(string $data): array
    {
        // Unfold continuation lines
        $data  = preg_replace('/\r?\n[ \t]/', '', $data);
        $lines = preg_split('/\r?\n/', $data);

        $events  = [];
        $current = null;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') continue;

            if (strcasecmp($line, 'BEGIN:VEVENT') === 0) {
                $current = [];
                continue;
            }
            if (strcasecmp($line, 'END:VEVENT') === 0 && $current !== null) {
                $events[] = $current;
                $current  = null;
                continue;
            }
            if ($current === null) continue;

            $colon = strpos($line, ':');
            if ($colon === false) continue;

            $propFull = substr($line, 0, $colon);
            $value    = substr($line, $colon + 1);

            // Split name;PARAM=VAL;…
            $segments = explode(';', $propFull);
            $propName = strtoupper(array_shift($segments));
            $params   = [];
            foreach ($segments as $seg) {
                if (strpos($seg, '=') !== false) {
                    [$pk, $pv]        = explode('=', $seg, 2);
                    $params[strtoupper($pk)] = $pv;
                }
            }

            // EXDATE can appear multiple times
            if ($propName === 'EXDATE') {
                $current['EXDATE'][] = ['value' => $value, 'params' => $params];
            } else {
                $current[$propName] = ['value' => $value, 'params' => $params];
            }
        }

        return $events;
    }

    private function parseICalDate(string $value, ?string $tzid = null): ?DateTime
    {
        $value = trim($value);

        // DATE: YYYYMMDD  (all-day)
        if (preg_match('/^\d{8}$/', $value)) {
            $dt = DateTime::createFromFormat('Ymd', $value, new DateTimeZone('UTC'));
            return $dt ?: null;
        }

        // DATETIME UTC: YYYYMMDDTHHMMSSZ
        if (preg_match('/^\d{8}T\d{6}Z$/i', $value)) {
            $dt = DateTime::createFromFormat('Ymd\THis\Z', strtoupper($value), new DateTimeZone('UTC'));
            return $dt ?: null;
        }

        // DATETIME local: YYYYMMDDTHHMMSS  (with optional TZID)
        if (preg_match('/^\d{8}T\d{6}$/i', $value)) {
            $tz = new DateTimeZone('UTC');
            if ($tzid) {
                try { $tz = new DateTimeZone($tzid); } catch (Throwable) {}
            }
            $dt = DateTime::createFromFormat('Ymd\THis', $value, $tz);
            if ($dt) $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt ?: null;
        }

        return null;
    }

    private function processEvent(
        array    $raw,
        string   $eventHref,
        string   $etag,
        string   $calHref,
        string   $color,
        DateTime $rangeStart,
        DateTime $rangeEnd
    ): ?array {
        if (!isset($raw['DTSTART'])) return null;

        $dtstart = $this->parseICalDate(
            $raw['DTSTART']['value'],
            $raw['DTSTART']['params']['TZID'] ?? null
        );
        if (!$dtstart) return null;

        $allDay = strlen(trim($raw['DTSTART']['value'])) === 8;

        // DTEND / DURATION
        $dtend = null;
        if (isset($raw['DTEND'])) {
            $dtend = $this->parseICalDate(
                $raw['DTEND']['value'],
                $raw['DTEND']['params']['TZID'] ?? null
            );
        } elseif (isset($raw['DURATION'])) {
            $dtend = clone $dtstart;
            try {
                $dtend->add(new DateInterval($raw['DURATION']['value']));
            } catch (Throwable) {
                $dtend->modify('+1 hour');
            }
        }

        if (!$dtend) {
            $dtend = clone $dtstart;
            $allDay ? $dtend->modify('+1 day') : $dtend->modify('+1 hour');
        }

        // Collect EXDATEs
        $exdates = [];
        foreach ($raw['EXDATE'] ?? [] as $group) {
            foreach (explode(',', $group['value']) as $ed) {
                $exdates[] = trim($ed);
            }
        }

        $isRecurring = isset($raw['RRULE']);

        $base = [
            'uid'          => trim($raw['UID']['value'] ?? uniqid()),
            'summary'      => $this->unescape($raw['SUMMARY']['value'] ?? 'Untitled'),
            'description'  => $this->unescape($raw['DESCRIPTION']['value'] ?? ''),
            'location'     => $this->unescape($raw['LOCATION']['value'] ?? ''),
            'allDay'       => $allDay,
            'calendarHref' => $calHref,
            'color'        => $color,
            'href'         => $eventHref,
            'etag'         => $etag,
            'isRecurring'  => $isRecurring,
            'dtstart'      => $dtstart,
            'dtend'        => $dtend,
            'rrule'        => $raw['RRULE']['value'] ?? null,
            'exdates'      => $exdates,
        ];

        $instances = $this->expandEvent($base, $rangeStart, $rangeEnd);

        return array_map(static function (array $inst): array {
            return [
                'uid'          => $inst['uid'],
                'summary'      => $inst['summary'],
                'description'  => $inst['description'],
                'location'     => $inst['location'],
                'start'        => $inst['dtstart']->format(DateTime::ATOM),
                'end'          => $inst['dtend']->format(DateTime::ATOM),
                'allDay'       => $inst['allDay'],
                'calendarHref' => $inst['calendarHref'],
                'color'        => $inst['color'],
                'href'         => $inst['href'],
                'etag'         => $inst['etag'],
                'isRecurring'  => $inst['isRecurring'],
            ];
        }, $instances);
    }

    private function unescape(string $v): string
    {
        return str_replace(
            ['\\,', '\\;', '\\n', '\\N', '\\\\'],
            [',',   ';',   "\n",  "\n",  '\\'],
            trim($v)
        );
    }

    // ── RRULE expander ───────────────────────────────────────────────────────

    private function expandEvent(array $event, DateTime $rangeStart, DateTime $rangeEnd): array
    {
        if (!$event['rrule']) {
            // Single event: include only if it overlaps the range
            if ($event['dtend'] < $rangeStart || $event['dtstart'] > $rangeEnd) return [];
            return [$event];
        }

        // Parse RRULE parts
        $parts = [];
        foreach (explode(';', $event['rrule']) as $part) {
            if (strpos($part, '=') === false) continue;
            [$k, $v]           = explode('=', $part, 2);
            $parts[strtoupper($k)] = $v;
        }

        $freq     = $parts['FREQ'] ?? 'DAILY';
        $maxCount = isset($parts['COUNT']) ? (int) $parts['COUNT'] : 10000;
        $until    = isset($parts['UNTIL']) ? $this->parseICalDate($parts['UNTIL']) : null;
        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));

        $dayMap = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
        $byDay  = [];
        if (isset($parts['BYDAY'])) {
            foreach (explode(',', $parts['BYDAY']) as $d) {
                $d = strtoupper(preg_replace('/^[+-]?\d+/', '', trim($d)));
                if (isset($dayMap[$d])) $byDay[] = $dayMap[$d];
            }
        }

        $duration = $event['dtstart']->diff($event['dtend']);
        $h        = (int) $event['dtstart']->format('H');
        $mi       = (int) $event['dtstart']->format('i');
        $s        = (int) $event['dtstart']->format('s');
        $instances = [];
        $count     = 0;

        if ($freq === 'WEEKLY' && !empty($byDay)) {
            // Walk week by week, generating an occurrence for each BYDAY day
            $weekCursor = clone $event['dtstart'];
            $dow = (int) $weekCursor->format('w');
            if ($dow > 0) $weekCursor->modify('-' . $dow . ' days'); // back to Sunday

            sort($byDay);
            $done = false;

            for ($w = 0; $w < 500 && !$done; $w++) {
                foreach ($byDay as $targetDow) {
                    $occ = clone $weekCursor;
                    $occ->modify('+' . $targetDow . ' days');
                    $occ->setTime($h, $mi, $s);

                    if ($occ < $event['dtstart'])      continue; // before series start
                    if ($until && $occ > $until)       { $done = true; break; }
                    if ($occ > $rangeEnd)              { $done = true; break; }
                    if ($count >= $maxCount)           { $done = true; break; }

                    $occEnd = clone $occ;
                    $occEnd->add($duration);
                    $count++;

                    if ($occEnd >= $rangeStart && !$this->isExcluded($occ, $event['exdates'])) {
                        $instances[] = array_merge($event, ['dtstart' => $occ, 'dtend' => $occEnd]);
                    }
                }
                $weekCursor->modify("+{$interval} weeks");
            }
        } else {
            $cursor = clone $event['dtstart'];

            for ($i = 0; $i < 3000; $i++) {
                if ($until && $cursor > $until) break;
                if ($cursor > $rangeEnd)        break;
                if ($count >= $maxCount)        break;

                $occEnd = clone $cursor;
                $occEnd->add($duration);
                $count++;

                if ($occEnd >= $rangeStart && !$this->isExcluded($cursor, $event['exdates'])) {
                    $instances[] = array_merge($event, [
                        'dtstart' => clone $cursor,
                        'dtend'   => clone $occEnd,
                    ]);
                }

                switch ($freq) {
                    case 'DAILY':   $cursor->modify("+{$interval} day");   break;
                    case 'WEEKLY':  $cursor->modify("+{$interval} week");  break;
                    case 'MONTHLY': $cursor->modify("+{$interval} month"); break;
                    case 'YEARLY':  $cursor->modify("+{$interval} year");  break;
                    default:        $cursor->modify("+{$interval} day");
                }
            }
        }

        return $instances;
    }

    private function isExcluded(DateTime $dt, array $exdates): bool
    {
        $checks = [
            $dt->format('Ymd\THis\Z'),
            $dt->format('Ymd\THis'),
            $dt->format('Ymd'),
        ];
        foreach ($checks as $key) {
            if (in_array($key, $exdates, true)) return true;
        }
        return false;
    }

    // ── Event update ─────────────────────────────────────────────────────────

    public function updateEvent(string $href, array $data): void
    {
        $url = $this->resolveHref($href);

        // Fetch current content and fresh ETag
        $get = $this->curlRequest('GET', $url, '', ['Accept' => 'text/calendar']);
        if ($get['status'] >= 400) {
            throw new RuntimeException("Cannot fetch event for update (HTTP {$get['status']})");
        }

        $updated = $this->updateICalendar($get['body'], $data);
        $ifMatch = $get['etag'] ?? '*';

        $newCalHref = $data['calendarHref'] ?? '';
        $moving     = $newCalHref !== '' && rtrim($newCalHref, '/') !== rtrim(dirname($href), '/');

        if ($moving) {
            // PUT to new calendar, then DELETE from old
            $filename = basename($href);
            $newUrl   = $this->resolveHref(rtrim($newCalHref, '/') . '/' . $filename);

            $put = $this->curlRequest('PUT', $newUrl, $updated, [
                'Content-Type' => 'text/calendar; charset=utf-8',
            ]);
            if ($put['status'] < 200 || $put['status'] >= 300) {
                throw new RuntimeException("Move (PUT) failed (HTTP {$put['status']})");
            }

            $del = $this->curlRequest('DELETE', $url, '', ['If-Match' => $ifMatch]);
            if ($del['status'] >= 400) {
                throw new RuntimeException("Move (DELETE) failed (HTTP {$del['status']})");
            }
        } else {
            $put = $this->curlRequest('PUT', $url, $updated, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'If-Match'     => $ifMatch,
            ]);
            if ($put['status'] < 200 || $put['status'] >= 300) {
                throw new RuntimeException("Event update failed (HTTP {$put['status']})");
            }
        }
    }

    private function updateICalendar(string $ical, array $data): string
    {
        // Unfold continuation lines then split
        $ical  = preg_replace('/\r?\n[ \t]/', '', $ical);
        $lines = preg_split('/\r?\n/', $ical);

        $now   = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');

        $replace = [
            'SUMMARY'       => $this->foldIcal('SUMMARY:'       . $this->escapeIcal($data['summary'])),
            'DTSTART'       => $this->buildDtProp('DTSTART', $data['start'], (bool) $data['allDay']),
            'DTEND'         => $this->buildDtProp('DTEND',   $data['end'],   (bool) $data['allDay']),
            'DTSTAMP'       => "DTSTAMP:$now",
            'LAST-MODIFIED' => "LAST-MODIFIED:$now",
            'DESCRIPTION'   => ($data['description'] ?? '') !== ''
                                ? $this->foldIcal('DESCRIPTION:' . $this->escapeIcal($data['description']))
                                : '',  // empty = remove property
            'LOCATION'      => ($data['location'] ?? '') !== ''
                                ? $this->foldIcal('LOCATION:'    . $this->escapeIcal($data['location']))
                                : '',
        ];

        $inEvent = false;
        $written = [];
        $out     = [];

        foreach ($lines as $line) {
            $t = rtrim($line);
            if ($t === '') continue;

            if (strcasecmp($t, 'BEGIN:VEVENT') === 0) {
                $inEvent = true;
                $out[]   = 'BEGIN:VEVENT';
                continue;
            }

            if (strcasecmp($t, 'END:VEVENT') === 0) {
                // Write any replacements not yet encountered in the original
                foreach ($replace as $prop => $val) {
                    if (!in_array($prop, $written, true) && $val !== '') {
                        $out[] = $val;
                    }
                }
                $inEvent = false;
                $out[]   = 'END:VEVENT';
                continue;
            }

            if (!$inEvent) {
                $out[] = $line;
                continue;
            }

            // Determine property name (stops at ; or :)
            $colon   = strpos($t, ':');
            if ($colon === false) { $out[] = $line; continue; }
            $semi    = strpos($t, ';');
            $end     = ($semi !== false && $semi < $colon) ? $semi : $colon;
            $prop    = strtoupper(substr($t, 0, $end));

            if (array_key_exists($prop, $replace) && !in_array($prop, $written, true)) {
                $written[] = $prop;
                if ($replace[$prop] !== '') {
                    $out[] = $replace[$prop];   // use updated value
                }
                // else: property cleared — omit it entirely
            } else {
                $out[] = $line;                 // keep original
            }
        }

        return implode("\r\n", $out) . "\r\n";
    }

    private function buildDtProp(string $prop, string $isoUtc, bool $allDay): string
    {
        $dt = new DateTime($isoUtc, new DateTimeZone('UTC'));
        return $allDay
            ? "{$prop};VALUE=DATE:" . $dt->format('Ymd')
            : "{$prop}:"           . $dt->format('Ymd\THis\Z');
    }

    private function foldIcal(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $out  .= substr($line, 0, 75) . "\r\n ";
            $line  = substr($line, 75);
        }
        return $out . $line;
    }

    private function escapeIcal(string $v): string
    {
        $v = str_replace('\\', '\\\\', $v);
        $v = str_replace("\n", '\\n',  $v);
        $v = str_replace(';',  '\\;',  $v);
        $v = str_replace(',',  '\\,',  $v);
        return $v;
    }
}
