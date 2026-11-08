<?php
declare(strict_types=1);

const BCV_URL = 'https://www.bcv.org.ve/';
const BINANCE_P2P_URL = 'https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search';
const BCV_CACHE_TTL = 21600;
const USDT_CACHE_TTL = 600;
const REQUEST_TIMEOUT = 15;
const CACHE_DIR = __DIR__ . '/../cache';
const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'Método no permitido'], 405);
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nowCaracas(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('America/Caracas'));
}

function nowIso(): string
{
    return nowCaracas()->format('c');
}

function readCache(string $name): ?array
{
    $file = CACHE_DIR . '/' . $name;
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function writeCache(string $name, array $data): void
{
    if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0775, true) && !is_dir(CACHE_DIR)) {
        return;
    }
    if (!is_writable(CACHE_DIR)) {
        return;
    }

    $file = CACHE_DIR . '/' . $name;
    $tmp = $file . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
    }
}

function httpRequest(string $url, string $method = 'GET', ?array $jsonBody = null, bool $insecure = false): string
{
    $headers = [
        'Accept: application/json, text/html;q=0.9, */*;q=0.8',
        'User-Agent: ' . USER_AGENT,
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo iniciar cURL');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
        if ($insecure) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Error de conexión: ' . ($error !== '' ? $error : 'desconocido'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status);
        }
        return (string) $body;
    }

    $contextOptions = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'timeout'       => REQUEST_TIMEOUT,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => !$insecure,
            'verify_peer_name' => !$insecure,
        ],
    ];
    if ($jsonBody !== null) {
        $contextOptions['http']['content'] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
    }

    $body = @file_get_contents($url, false, stream_context_create($contextOptions));
    if ($body === false) {
        throw new RuntimeException('No se pudo conectar con el servicio');
    }

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $status = (int) $match[1];
    }
    if ($status >= 400) {
        throw new RuntimeException('HTTP ' . $status);
    }

    return $body;
}

function parseVesNumber(string $raw): float
{
    $raw = trim($raw);
    $raw = (string) preg_replace('/[^0-9.,-]/', '', $raw);
    if ($raw === '' || $raw === '-' || $raw === '.' || $raw === ',') {
        throw new RuntimeException('Número inválido');
    }

    $lastDot = strrpos($raw, '.');
    $lastComma = strrpos($raw, ',');
    if ($lastDot !== false && $lastComma !== false) {
        if ($lastComma > $lastDot) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
    } elseif ($lastComma !== false) {
        $raw = str_replace(',', '.', $raw);
    }

    $value = (float) $raw;
    if (!is_finite($value) || $value <= 0) {
        throw new RuntimeException('Número inválido');
    }

    return $value;
}

function extractBcvRate(DOMXPath $xpath, string $id): float
{
    $nodes = $xpath->query(
        '//*[@id="' . $id . '"]//*[contains(concat(" ", normalize-space(@class), " "), " strong-tb ")]'
    );
    if ($nodes === false || $nodes->length === 0) {
        throw new RuntimeException('No se encontró la tasa ' . strtoupper($id));
    }

    return parseVesNumber((string) $nodes->item(0)->textContent);
}

function scrapeBcv(): array
{
    $html = httpRequest(BCV_URL, 'GET', null, true);
    $usd = null;
    $eur = null;
    $valueDate = null;
    $valueDateText = null;

    if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            $xpath = new DOMXPath($doc);
            try {
                $usd = extractBcvRate($xpath, 'dolar');
                $eur = extractBcvRate($xpath, 'euro');
            } catch (RuntimeException $e) {
                $usd = null;
                $eur = null;
            }

            $dateNodes = $xpath->query('//span[@property="dc:date"]');
            if ($dateNodes !== false && $dateNodes->length > 0) {
                $dateNode = $dateNodes->item(0);
                if ($dateNode instanceof DOMElement) {
                    $valueDate = trim($dateNode->getAttribute('content')) ?: null;
                    $valueDateText = trim($dateNode->textContent) ?: null;
                }
            }
        }
    }

    if ($usd === null && preg_match('/id="dolar".*?<strong[^>]*class="[^"]*strong-tb[^"]*"[^>]*>\s*([0-9.,]+)\s*<\/strong>/si', $html, $match)) {
        $usd = parseVesNumber($match[1]);
    }
    if ($eur === null && preg_match('/id="euro".*?<strong[^>]*class="[^"]*strong-tb[^"]*"[^>]*>\s*([0-9.,]+)\s*<\/strong>/si', $html, $match)) {
        $eur = parseVesNumber($match[1]);
    }
    if ($valueDate === null && preg_match('/property="dc:date"[^>]*content="([^"]+)"/i', $html, $match)) {
        $valueDate = trim($match[1]) ?: null;
    }
    if ($valueDateText === null && preg_match('/<span[^>]*class="date-display-single"[^>]*>([^<]+)<\/span>/i', $html, $match)) {
        $valueDateText = trim($match[1]) ?: null;
    }

    if ($usd === null || $eur === null) {
        throw new RuntimeException('No se pudieron leer las tasas del BCV');
    }

    return [
        'rates'         => ['USD' => $usd, 'EUR' => $eur],
        'valueDate'     => $valueDate,
        'valueDateText' => $valueDateText,
    ];
}

function scrapeUsdt(): array
{
    $body = httpRequest(BINANCE_P2P_URL, 'POST', [
        'page'           => 1,
        'rows'           => 10,
        'payTypes'       => [],
        'countries'      => [],
        'publisherType'  => null,
        'asset'          => 'USDT',
        'fiat'           => 'VES',
        'tradeType'      => 'BUY',
    ]);

    $data = json_decode($body, true);
    if (
        !is_array($data)
        || ($data['code'] ?? '') !== '000000'
        || !isset($data['data'])
        || !is_array($data['data'])
    ) {
        throw new RuntimeException('Binance P2P devolvió una respuesta inválida');
    }

    $prices = [];
    foreach ($data['data'] as $item) {
        if (!is_array($item) || !isset($item['adv']) || !is_array($item['adv'])) {
            continue;
        }
        $price = $item['adv']['price'] ?? null;
        if ($price === null) {
            continue;
        }
        try {
            $prices[] = parseVesNumber((string) $price);
        } catch (RuntimeException $e) {
            continue;
        }
    }

    if ($prices === []) {
        throw new RuntimeException('No hay anuncios de USDT/VES en Binance P2P');
    }

    sort($prices, SORT_NUMERIC);
    $count = count($prices);
    $middle = intdiv($count, 2);
    $median = $count % 2 === 1
        ? $prices[$middle]
        : ($prices[$middle - 1] + $prices[$middle]) / 2;

    return [
        'rates'         => ['USDT' => round($median, 2)],
        'valueDate'     => null,
        'valueDateText' => null,
    ];
}

function getBcvData(): array
{
    $cached = readCache('bcv.json');
    $now = time();
    if (
        $cached !== null
        && isset($cached['fetchedAtUnix'])
        && ($now - (int) $cached['fetchedAtUnix']) < BCV_CACHE_TTL
    ) {
        return ['data' => $cached, 'stale' => false];
    }

    try {
        $fresh = scrapeBcv();
        $variations = ['USD' => null, 'EUR' => null];

        if ($cached !== null && isset($cached['rates']) && is_array($cached['rates'])) {
            $sameDate = isset($cached['valueDate'], $fresh['valueDate'])
                && $cached['valueDate'] !== null
                && $cached['valueDate'] === $fresh['valueDate'];

            if ($sameDate) {
                foreach (['USD', 'EUR'] as $key) {
                    if (isset($cached['variations'][$key]) && is_numeric($cached['variations'][$key])) {
                        $variations[$key] = (float) $cached['variations'][$key];
                    }
                }
            } else {
                foreach (['USD', 'EUR'] as $key) {
                    $previousRate = (float) ($cached['rates'][$key] ?? 0);
                    $currentRate = (float) ($fresh['rates'][$key] ?? 0);
                    if ($previousRate > 0 && $currentRate > 0) {
                        $variations[$key] = round((($currentRate - $previousRate) / $previousRate) * 100, 2);
                    }
                }
            }
        }

        $fresh['variations'] = $variations;
        $fresh['fetchedAt'] = nowIso();
        $fresh['fetchedAtUnix'] = $now;
        writeCache('bcv.json', $fresh);

        return ['data' => $fresh, 'stale' => false];
    } catch (Throwable $e) {
        if ($cached !== null) {
            return ['data' => $cached, 'stale' => true, 'error' => $e->getMessage()];
        }
        throw $e;
    }
}

function getUsdtData(): array
{
    $cached = readCache('usdt.json');
    $now = time();
    if (
        $cached !== null
        && isset($cached['fetchedAtUnix'])
        && ($now - (int) $cached['fetchedAtUnix']) < USDT_CACHE_TTL
    ) {
        return ['data' => $cached, 'stale' => false];
    }

    try {
        $fresh = scrapeUsdt();
        $today = nowCaracas()->format('Y-m-d');
        $rate = (float) ($fresh['rates']['USDT'] ?? 0);
        $previousRate = (float) ($cached['rates']['USDT'] ?? 0);
        $dayOpenRate = $rate;

        if ($cached !== null && ($cached['day'] ?? null) === $today) {
            $storedOpen = (float) ($cached['dayOpenRate'] ?? 0);
            $dayOpenRate = $storedOpen > 0 ? $storedOpen : ($previousRate > 0 ? $previousRate : $rate);
        } elseif ($previousRate > 0) {
            $dayOpenRate = $previousRate;
        }

        $variation = $dayOpenRate > 0
            ? round((($rate - $dayOpenRate) / $dayOpenRate) * 100, 2)
            : null;

        $fresh['variations'] = ['USDT' => $variation];
        $fresh['day'] = $today;
        $fresh['dayOpenRate'] = $dayOpenRate;
        $fresh['fetchedAt'] = nowIso();
        $fresh['fetchedAtUnix'] = $now;
        writeCache('usdt.json', $fresh);

        return ['data' => $fresh, 'stale' => false];
    } catch (Throwable $e) {
        if ($cached !== null) {
            return ['data' => $cached, 'stale' => true, 'error' => $e->getMessage()];
        }
        throw $e;
    }
}

$bcv = null;
$usdt = null;
$errors = [];

try {
    $bcv = getBcvData();
} catch (Throwable $e) {
    $errors['bcv'] = $e->getMessage();
}

try {
    $usdt = getUsdtData();
} catch (Throwable $e) {
    $errors['usdt'] = $e->getMessage();
}

if ($bcv === null && $usdt === null) {
    respond([
        'ok'      => false,
        'error'   => 'No se pudieron obtener las cotizaciones',
        'details' => $errors,
    ], 502);
}

$rates = [];
$variations = [];
$sources = [];
$stale = false;
$valueDate = null;
$valueDateText = null;

if ($bcv !== null) {
    $rates = array_merge($rates, $bcv['data']['rates'] ?? []);
    $variations = array_merge($variations, $bcv['data']['variations'] ?? []);
    $sources['USD'] = 'bcv';
    $sources['EUR'] = 'bcv';
    $stale = $stale || $bcv['stale'];
    $valueDate = $bcv['data']['valueDate'] ?? null;
    $valueDateText = $bcv['data']['valueDateText'] ?? null;
}

if ($usdt !== null) {
    $rates = array_merge($rates, $usdt['data']['rates'] ?? []);
    $variations = array_merge($variations, $usdt['data']['variations'] ?? []);
    $sources['USDT'] = 'binance-p2p';
    $stale = $stale || $usdt['stale'];
}

respond([
    'ok'            => true,
    'fetchedAt'     => nowIso(),
    'valueDate'     => $valueDate,
    'valueDateText' => $valueDateText,
    'rates'         => $rates,
    'variations'    => $variations,
    'sources'       => $sources,
    'stale'         => $stale,
    'errors'        => $errors ?: null,
]);
