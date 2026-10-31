<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/tests/Support/LocalHttpsServer.php';
require_once __DIR__ . '/tests/Support/LocalConnectProxyServer.php';

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use function Omegaalfa\HttpClient\Http\await;
use function Omegaalfa\HttpClient\Http\awaitAll;
use function Omegaalfa\HttpClient\Http\awaitSettled;

// Usage: php benchmark.php [iterations] [batchSize] [warmup] [serverWorkers] [batchSamples].
// Optional external scenarios: set HTTPCLIENT_BENCHMARK_URL and optionally HTTPCLIENT_BENCHMARK_PROXY.
// P95 for concurrent scenarios is measured per batch, not per individual request.
// Run the script at least three times and compare medians for stable conclusions.

/**
 * Benchmark mais rigoroso e realista para comparar Omegaalfa HttpClient vs Guzzle.
 *
 * Problemas do benchmark anterior que este corrige:
 * - Apenas 200 requisições (amostra pequena)
 * - Apenas localhost (sem latência de rede real)
 * - Apenas JSON pequeno (não testa payloads grandes)
 * - Não testa cenários de erro (timeout, 500, retry)
 * - Não mede consumo de memória
 * - Não mostra percentis (p95, p99) — média esconde outliers
 * - Não testa connection pooling real
 */

final class BetterBenchmarkServer
{
    /** @var resource|false|null */
    private $process = null;
    private int $port;
    private string $routerFile;

    public function __construct(private readonly int $workers = 16)
    {
        $this->port = $this->reservePort();
        $this->start();
    }

    public function baseUrl(): string
    {
        return sprintf('http://127.0.0.1:%d', $this->port);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            $pid = (int)($status['pid'] ?? 0);
            if ($pid > 0 && function_exists('posix_kill')) {
                @posix_kill(-$pid, SIGTERM);
            } else {
                @proc_terminate($this->process);
            }

            $deadline = microtime(true) + 1.0;
            do {
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);

            if (($status['running'] ?? false) && $pid > 0 && function_exists('posix_kill')) {
                @posix_kill(-$pid, SIGKILL);
            }
            @proc_close($this->process);
            $this->process = null;
        }

        if (isset($this->routerFile) && is_file($this->routerFile)) {
            unlink($this->routerFile);
        }
    }

    private function start(): void
    {
        $router = <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = [];
parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $query);

header('Content-Type: application/json');

// Simula latência de rede (10-50ms)
if (isset($query['latency'])) {
    usleep((int)$query['latency'] * 1000);
}

// Endpoint que retorna erro 500 aleatório
if (isset($query['chaos']) && random_int(1, 100) <= (int)$query['chaos']) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'id' => uniqid()]);
    exit;
}

// Endpoint com payload grande
if ($path === '/large') {
    $size = (int)($query['size'] ?? 1024);
    $data = str_repeat('x', $size);
    echo json_encode(['size' => $size, 'data' => $data, 'hash' => md5($data)]);
    exit;
}

// Endpoint com payload pequeno (padrão)
if ($path === '/json') {
    echo json_encode([
        'status' => 'ok',
        'timestamp' => time(),
        'lang' => $query['lang'] ?? 'en',
        'id' => uniqid(),
    ]);
    exit;
}

// Endpoint lento (simula API externa lenta)
if ($path === '/slow') {
    usleep(150000); // 150ms
    echo json_encode(['status' => 'slow', 'delay_ms' => 150]);
    exit;
}

if ($path === '/redirect') {
    http_response_code(302);
    header('Location: /json?redirected=1');
    exit;
}

if ($path === '/empty') {
    http_response_code(204);
    exit;
}

if ($path === '/chunked') {
    header_remove('Content-Length');
    header('Transfer-Encoding: chunked');
    echo "5\r\nhello\r\n0\r\n\r\n";
    exit;
}

if ($path === '/form') {
    parse_str(file_get_contents('php://input'), $form);
    echo json_encode(['form' => $form]);
    exit;
}

if ($path === '/cookie/set') {
    setcookie('bench', '1', ['path' => '/']);
    echo json_encode(['set' => true]);
    exit;
}

if ($path === '/cookie/check') {
    echo json_encode(['cookie' => $_COOKIE['bench'] ?? null]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
PHP;
        $this->routerFile = sys_get_temp_dir() . '/better_benchmark_router_' . getmypid() . '.php';
        file_put_contents($this->routerFile, $router);
        $command = ['setsid', PHP_BINARY, '-n', '-S', sprintf('127.0.0.1:%d', $this->port), $this->routerFile];
        $environment = getenv();
        $environment['PHP_CLI_SERVER_WORKERS'] = (string)$this->workers;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];

        $this->process = proc_open($command, $descriptors, $pipes, __DIR__, $environment);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Unable to start server');
        }
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client(sprintf('tcp://127.0.0.1:%d', $this->port), $errno, $errstr, 0.1);
            if (is_resource($socket)) { fclose($socket); return; }
            usleep(20_000);
        }
        throw new RuntimeException('Server did not start');
    }

    private function reservePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) throw new RuntimeException('Cannot reserve port');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }
}

final class BenchmarkCertificate
{
    private string $directory;

    public function __construct()
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('TLS benchmark scenarios require ext-openssl');
        }

        $this->directory = sys_get_temp_dir() . '/httpclient-benchmark-tls-' . bin2hex(random_bytes(6));
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create temporary TLS certificate directory');
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new RuntimeException('Unable to generate benchmark TLS key');
        }

        $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
        $certificate = $request === false ? false : openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        $certificatePem = '';
        $keyPem = '';
        if ($certificate === false
            || !openssl_x509_export($certificate, $certificatePem)
            || !openssl_pkey_export($key, $keyPem)) {
            throw new RuntimeException('Unable to create benchmark TLS certificate');
        }

        $path = $this->combinedPemPath();
        if (file_put_contents($path, $certificatePem . $keyPem) === false) {
            throw new RuntimeException('Unable to write benchmark TLS certificate');
        }
    }

    public function combinedPemPath(): string
    {
        return $this->directory . '/combined.pem';
    }

    public function cleanup(): void
    {
        $path = $this->combinedPemPath();
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}

class ResultCollector
{
    /** @var float[] */
    public array $times = [];
    public int $errors = 0;
    public int $success = 0;
    public int $bytesTransferred = 0;
    public float $memoryPeak = 0.0;

    public bool $batchSamples = false;

    public function add(float $time, bool $success, int $bytes = 0): void
    {
        $this->times[] = $time;
        if ($success) {
            $this->success++;
            $this->bytesTransferred += $bytes;
        } else {
            $this->errors++;
        }
        $this->memoryPeak = max($this->memoryPeak, memory_get_peak_usage(true) / 1024 / 1024);
    }

    public function addBatch(float $time, int $successes, int $attempts, int $bytes = 0): void
    {
        $this->batchSamples = true;
        $this->times[] = $time;
        $this->success += $successes;
        $this->errors += $attempts - $successes;
        $this->bytesTransferred += $bytes;
        $this->memoryPeak = max($this->memoryPeak, memory_get_peak_usage(true) / 1024 / 1024);
    }

    public function avg(): float
    {
        return count($this->times) > 0 ? array_sum($this->times) / count($this->times) : 0.0;
    }

    public function median(): float
    {
        $sorted = $this->times;
        sort($sorted);
        $count = count($sorted);
        if ($count === 0) return 0.0;
        if ($count % 2 === 0) {
            return ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2;
        }
        return $sorted[(int) floor($count / 2)];
    }

    public function percentile(float $p): float
    {
        $sorted = $this->times;
        sort($sorted);
        $count = count($sorted);
        if ($count === 0) return 0.0;
        $index = (int) ceil($p / 100 * $count) - 1;
        return $sorted[max(0, $index)];
    }

    public function throughput(): float
    {
        $total = array_sum($this->times);
        return $total > 0 ? $this->success / $total : 0.0;
    }

    public function stdDev(): float
    {
        $count = count($this->times);
        if ($count < 2) return 0.0;
        $mean = $this->avg();
        $variance = array_sum(array_map(fn($x) => ($x - $mean) ** 2, $this->times)) / $count;
        return sqrt($variance);
    }
}

function printSection(string $title): void
{
    echo "\n" . str_repeat('=', 78) . "\n";
    echo "  " . strtoupper($title) . "\n";
    echo str_repeat('=', 78) . "\n";
}

function printResults(string $label, ResultCollector $result, string $compareLabel = '', ResultCollector $compare = null): void
{
    printf("\n📊 %s\n", $label);
    printf('   Requests: %d, success: %d, errors: %d, samples: %d (%s)%s', $result->success + $result->errors, $result->success, $result->errors, count($result->times), $result->batchSamples ? 'batches' : 'requests', PHP_EOL);
    printf("   Throughput:   %.2f req/s\n", $result->throughput());
    printf("   Média:        %.4f ms\n", $result->avg() * 1000);
    printf("   Mediana:      %.4f ms\n", $result->median() * 1000);
    $sampleUnit = $result->batchSamples ? 'lote' : 'request';
    printf("   P95 (%s): %.4f ms\n", $sampleUnit, $result->percentile(95) * 1000);
    printf("   P99 (%s): %.4f ms\n", $sampleUnit, $result->percentile(99) * 1000);
    printf("   StdDev (%s):  %.4f ms\n", $sampleUnit, $result->stdDev() * 1000);
    printf("   Memória:      %.2f MB (pico acumulado do processo)\n", $result->memoryPeak);
    printf("   Bytes:        %s transferidos\n", number_format($result->bytesTransferred));

    if ($compare !== null && $compare->avg() > 0) {
        $ratio = $result->avg() / $compare->avg();
        $faster = $ratio < 1;
        printf("   vs %s: %.2fx %s\n", $compareLabel, $ratio, $faster ? 'mais rápido' : 'mais lento');
    }
}

// ============================================================
// CONFIGURAÇÕES
// ============================================================
$iterations = (int) ($argv[1] ?? 500);
$batchSize  = (int) ($argv[2] ?? 25);
$warmup     = (int) ($argv[3] ?? 50);
$serverWorkers = max(1, (int) ($argv[4] ?? 16));
$batchSampleTarget = max(2, (int) ($argv[5] ?? 100));
$batchIterations = max($iterations, $batchSize * $batchSampleTarget);

if ($iterations < 1 || $batchSize < 1 || $warmup < 0) {
    throw new InvalidArgumentException('iterations and batchSize must be positive; warmup cannot be negative');
}
if (!extension_loaded('curl')) {
    throw new RuntimeException('The benchmark requires ext-curl so Guzzle uses its cURL handler');
}

echo "🔧 Configurações:\n";
echo "   Iterações: {$iterations}\n";
echo "   Batch size (concorrente): {$batchSize}\n";
echo "   Amostras-alvo por batch: {$batchSampleTarget}\n";
echo "   Requests nos cenários batch: {$batchIterations}\n";
echo "   Warmup: {$warmup}\n";
echo "   PHP runtime: " . PHP_VERSION . ' / ' . PHP_SAPI . PHP_EOL;
echo "   OS: " . PHP_OS_FAMILY . ' (' . PHP_OS . ')' . PHP_EOL;
echo "   OPcache CLI: " . (ini_get('opcache.enable_cli') ?: 'Off') . PHP_EOL;
echo "   JIT: " . (ini_get('opcache.jit') ?: 'disable') . PHP_EOL;
echo "   cURL: " . (curl_version()['version'] ?? 'unknown') . PHP_EOL;
echo "   Server workers: {$serverWorkers}\n";

$server = new BetterBenchmarkServer($serverWorkers);
$base = $server->baseUrl();
$benchmarkCertificate = null;
$httpsServer = null;
$connectProxy = null;

try {
    $guzzle = new \GuzzleHttp\Client([
        'timeout' => 10,
        'connect_timeout' => 5,
        'http_errors' => false,
        'allow_redirects' => ['max' => 3],
        'decode_content' => false,
    ]);

    $ours = (new AsyncHttpClient())
        ->withTimeout(10)
        ->connectTimeout(5)
        ->readTimeout(10)
        ->writeTimeout(10)
        ->withKeepAlive(true);

    $guzzleOptions = ['headers' => ['Connection' => 'keep-alive']];
    $measureOurs = static function (string $url) use ($ours): array {
        $start = hrtime(true);
        $response = await($ours->get($url));
        $body = $response->body();
        return [(hrtime(true) - $start) / 1e9, $response->status() === 200 && $body !== '', strlen($body)];
    };
    $measureGuzzle = static function (string $url) use ($guzzle, $guzzleOptions): array {
        $start = hrtime(true);
        $response = $guzzle->get($url, $guzzleOptions);
        $body = $response->getBody()->getContents();
        return [(hrtime(true) - $start) / 1e9, $response->getStatusCode() === 200 && $body !== '', strlen($body)];
    };

    // Warmup
    echo "\n🔥 Warmup...\n";
    for ($i = 0; $i < $warmup; $i++) {
        await($ours->get($base . '/json'));
        $guzzle->get($base . '/json', $guzzleOptions);
    }

    // Warm each client's connection pool with the same concurrent wave.
    $warmBatch = min($batchSize, $warmup);
    if ($warmBatch > 1) {
        $warmFutures = [];
        $warmPromises = [];
        for ($i = 0; $i < $warmBatch; $i++) {
            $warmFutures[] = $ours->get($base . '/json?warm=' . $i);
            $warmPromises[] = $guzzle->getAsync($base . '/json?warm=' . $i, $guzzleOptions);
        }
        awaitAll($warmFutures);
        \GuzzleHttp\Promise\Utils::settle($warmPromises)->wait();
    }
    gc_collect_cycles();

    // ============================================================
    // CENÁRIO 1: Requisições pequenas em série (igual ao anterior)
    // ============================================================
    printSection("Cenário 1: Requisições pequenas em série");

    $oursSmallSerial = new ResultCollector();
    $guzzleSmallSerial = new ResultCollector();

    for ($i = 0; $i < $iterations; $i++) {
        if (($i % 2) === 0) {
            [$time, $success, $bytes] = $measureOurs($base . '/json?lang=pt-BR');
            $oursSmallSerial->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureGuzzle($base . '/json?lang=pt-BR');
            $guzzleSmallSerial->add($time, $success, $bytes);
        } else {
            [$time, $success, $bytes] = $measureGuzzle($base . '/json?lang=pt-BR');
            $guzzleSmallSerial->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureOurs($base . '/json?lang=pt-BR');
            $oursSmallSerial->add($time, $success, $bytes);
        }
    }

    printResults('Omegaalfa (serial pequeno)', $oursSmallSerial, 'Guzzle', $guzzleSmallSerial);
    printResults('Guzzle (serial pequeno)', $guzzleSmallSerial);

    // ============================================================
    // CENÁRIO 2: Requisições pequenas em batch (concorrente)
    // ============================================================
    printSection("Cenário 2: Requisições pequenas em batch (concorrente)");

    $oursSmallBatch = new ResultCollector();
    $guzzleSmallBatch = new ResultCollector();

    $measureOursBatch = static function (int $offset, int $batch) use ($ours, $base): array {
        $start = hrtime(true);
        $futures = [];
        for ($i = 0; $i < $batch; $i++) {
            $futures[] = $ours->get($base . '/json?n=' . ($offset + $i));
        }
        $responses = awaitAll($futures);
        $time = (hrtime(true) - $start) / 1e9;
        $success = count(array_filter($responses, static fn ($response): bool => $response->status() === 200 && $response->body() !== ''));
        $bytes = array_sum(array_map(static fn ($response): int => strlen($response->body()), $responses));
        return [$time, $success, $bytes];
    };
    $measureGuzzleBatch = static function (int $offset, int $batch) use ($guzzle, $base, $guzzleOptions): array {
        $start = hrtime(true);
        $promises = [];
        for ($i = 0; $i < $batch; $i++) {
            $promises[] = $guzzle->getAsync($base . '/json?n=' . ($offset + $i), $guzzleOptions);
        }
        $settled = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
        $time = (hrtime(true) - $start) / 1e9;
        $success = count(array_filter($settled, static fn ($result): bool => ($result['state'] ?? '') === 'fulfilled'
            && $result['value']->getStatusCode() === 200
            && $result['value']->getBody()->getSize() > 0));
        $bytes = array_sum(array_map(static function ($result): int {
            return ($result['state'] ?? '') === 'fulfilled' ? (int) $result['value']->getBody()->getSize() : 0;
        }, $settled));
        return [$time, $success, $bytes];
    };

    $completed = 0;
    $batchNumber = 0;
    while ($completed < $batchIterations) {
        $batch = min($batchSize, $batchIterations - $completed);
        if (($batchNumber % 2) === 0) {
            [$time, $success, $bytes] = $measureOursBatch($completed, $batch);
            $oursSmallBatch->addBatch($time, $success, $batch, $bytes);
            [$time, $success, $bytes] = $measureGuzzleBatch($completed, $batch);
            $guzzleSmallBatch->addBatch($time, $success, $batch, $bytes);
        } else {
            [$time, $success, $bytes] = $measureGuzzleBatch($completed, $batch);
            $guzzleSmallBatch->addBatch($time, $success, $batch, $bytes);
            [$time, $success, $bytes] = $measureOursBatch($completed, $batch);
            $oursSmallBatch->addBatch($time, $success, $batch, $bytes);
        }
        $completed += $batch;
        $batchNumber++;
    }

    printResults('Omegaalfa (batch pequeno)', $oursSmallBatch, 'Guzzle', $guzzleSmallBatch);
    printResults('Guzzle (batch pequeno)', $guzzleSmallBatch);

    // ============================================================
    // CENÁRIO 3: Latência simulada (10ms) — mais próximo da realidade
    // ============================================================
    printSection("Cenário 3: Com latência simulada de 10ms (mais realista)");

    $oursLatency = new ResultCollector();
    $guzzleLatency = new ResultCollector();

    for ($i = 0; $i < $iterations; $i++) {
        if (($i % 2) === 0) {
            [$time, $success, $bytes] = $measureOurs($base . '/json?latency=10');
            $oursLatency->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureGuzzle($base . '/json?latency=10');
            $guzzleLatency->add($time, $success, $bytes);
        } else {
            [$time, $success, $bytes] = $measureGuzzle($base . '/json?latency=10');
            $guzzleLatency->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureOurs($base . '/json?latency=10');
            $oursLatency->add($time, $success, $bytes);
        }
    }

    printResults('Omegaalfa (latência 10ms)', $oursLatency, 'Guzzle', $guzzleLatency);
    printResults('Guzzle (latência 10ms)', $guzzleLatency);

    // ============================================================
    // CENÁRIO 4: Payload grande (100KB)
    // ============================================================
    printSection("Cenário 4: Payload grande (100KB)");

    $oursLarge = new ResultCollector();
    $guzzleLarge = new ResultCollector();

    for ($i = 0; $i < min($iterations, 200); $i++) {
        if (($i % 2) === 0) {
            [$time, $success, $bytes] = $measureOurs($base . '/large?size=102400');
            $oursLarge->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureGuzzle($base . '/large?size=102400');
            $guzzleLarge->add($time, $success, $bytes);
        } else {
            [$time, $success, $bytes] = $measureGuzzle($base . '/large?size=102400');
            $guzzleLarge->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureOurs($base . '/large?size=102400');
            $oursLarge->add($time, $success, $bytes);
        }
    }

    printResults('Omegaalfa (payload 100KB)', $oursLarge, 'Guzzle', $guzzleLarge);
    printResults('Guzzle (payload 100KB)', $guzzleLarge);

    // ============================================================
    // CENÁRIO 5: Endpoint lento (150ms) — simula API externa
    // ============================================================
    printSection("Cenário 5: Endpoint lento (150ms) — simula API externa");

    $oursSlow = new ResultCollector();
    $guzzleSlow = new ResultCollector();

    $slowIterations = min($iterations, 100);
    for ($i = 0; $i < $slowIterations; $i++) {
        if (($i % 2) === 0) {
            [$time, $success, $bytes] = $measureOurs($base . '/slow');
            $oursSlow->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureGuzzle($base . '/slow');
            $guzzleSlow->add($time, $success, $bytes);
        } else {
            [$time, $success, $bytes] = $measureGuzzle($base . '/slow');
            $guzzleSlow->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureOurs($base . '/slow');
            $oursSlow->add($time, $success, $bytes);
        }
    }

    printResults('Omegaalfa (lento 150ms)', $oursSlow, 'Guzzle', $guzzleSlow);
    printResults('Guzzle (lento 150ms)', $guzzleSlow);

    // ============================================================
    // CENÁRIO 6: Concorrência com latência (batch)
    // ============================================================
    printSection("Cenário 6: Concorrência com latência simulada");

    $oursConcLatency = new ResultCollector();
    $guzzleConcLatency = new ResultCollector();

    $measureOursLatencyBatch = static function (int $offset, int $batch) use ($ours, $base): array {
        $start = hrtime(true);
        $futures = [];
        for ($i = 0; $i < $batch; $i++) {
            $futures[] = $ours->get($base . '/json?latency=10&n=' . ($offset + $i));
        }
        $responses = awaitAll($futures);
        $time = (hrtime(true) - $start) / 1e9;
        $success = count(array_filter($responses, static fn ($response): bool => $response->status() === 200 && $response->body() !== ''));
        $bytes = array_sum(array_map(static fn ($response): int => strlen($response->body()), $responses));
        return [$time, $success, $bytes];
    };
    $measureGuzzleLatencyBatch = static function (int $offset, int $batch) use ($guzzle, $base, $guzzleOptions): array {
        $start = hrtime(true);
        $promises = [];
        for ($i = 0; $i < $batch; $i++) {
            $promises[] = $guzzle->getAsync($base . '/json?latency=10&n=' . ($offset + $i), $guzzleOptions);
        }
        $settled = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
        $time = (hrtime(true) - $start) / 1e9;
        $success = count(array_filter($settled, static fn ($result): bool => ($result['state'] ?? '') === 'fulfilled'
            && $result['value']->getStatusCode() === 200
            && $result['value']->getBody()->getSize() > 0));
        $bytes = array_sum(array_map(static function ($result): int {
            return ($result['state'] ?? '') === 'fulfilled' ? (int) $result['value']->getBody()->getSize() : 0;
        }, $settled));
        return [$time, $success, $bytes];
    };

    $completed = 0;
    $batchNumber = 0;
    $concIterations = $batchIterations;
    while ($completed < $concIterations) {
        $batch = min($batchSize, $concIterations - $completed);
        if (($batchNumber % 2) === 0) {
            [$time, $success, $bytes] = $measureOursLatencyBatch($completed, $batch);
            $oursConcLatency->addBatch($time, $success, $batch, $bytes);
            [$time, $success, $bytes] = $measureGuzzleLatencyBatch($completed, $batch);
            $guzzleConcLatency->addBatch($time, $success, $batch, $bytes);
        } else {
            [$time, $success, $bytes] = $measureGuzzleLatencyBatch($completed, $batch);
            $guzzleConcLatency->addBatch($time, $success, $batch, $bytes);
            [$time, $success, $bytes] = $measureOursLatencyBatch($completed, $batch);
            $oursConcLatency->addBatch($time, $success, $batch, $bytes);
        }
        $completed += $batch;
        $batchNumber++;
    }

    printResults('Omegaalfa (batch + latência)', $oursConcLatency, 'Guzzle', $guzzleConcLatency);
    printResults('Guzzle (batch + latência)', $guzzleConcLatency);

    // ============================================================
    // CENÁRIOS 7–8: HTTPS local direto e HTTPS por proxy CONNECT
    // ============================================================
    $tlsIterations = min($iterations, 25);
    $benchmarkCertificate = new BenchmarkCertificate();
    $httpsServer = new \Tests\Omegaalfa\HttpClient\Support\LocalHttpsServer($benchmarkCertificate->combinedPemPath());
    $tlsUrl = sprintf('https://127.0.0.1:%d/json', $httpsServer->port());
    $tlsOurs = (new AsyncHttpClient())
        ->withTimeout(10)
        ->connectTimeout(5)
        ->readTimeout(10)
        ->writeTimeout(10)
        ->withVerifySSL(false)
        ->withKeepAlive(false);
    $tlsGuzzle = new \GuzzleHttp\Client([
        'timeout' => 10,
        'connect_timeout' => 5,
        'verify' => false,
        'http_errors' => false,
        'allow_redirects' => ['max' => 3],
        'headers' => ['Connection' => 'close'],
    ]);
    // Warm process startup, while each measured request still makes a fresh TLS connection.
    await($tlsOurs->get($tlsUrl));
    $tlsGuzzle->get($tlsUrl);

    printSection('Cenário 7: TLS direto local (handshake por requisição)');
    $oursTls = new ResultCollector();
    $guzzleTls = new ResultCollector();
    $measureTlsOurs = static function () use ($tlsOurs, $tlsUrl): array {
        $start = hrtime(true);
        $response = await($tlsOurs->get($tlsUrl));
        $body = $response->body();
        return [(hrtime(true) - $start) / 1e9, $response->status() === 200 && $body !== '', strlen($body)];
    };
    $measureTlsGuzzle = static function () use ($tlsGuzzle, $tlsUrl): array {
        $start = hrtime(true);
        $response = $tlsGuzzle->get($tlsUrl);
        $body = $response->getBody()->getContents();
        return [(hrtime(true) - $start) / 1e9, $response->getStatusCode() === 200 && $body !== '', strlen($body)];
    };
    for ($i = 0; $i < $tlsIterations; $i++) {
        if (($i % 2) === 0) {
            [$time, $success, $bytes] = $measureTlsOurs();
            $oursTls->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureTlsGuzzle();
            $guzzleTls->add($time, $success, $bytes);
        } else {
            [$time, $success, $bytes] = $measureTlsGuzzle();
            $guzzleTls->add($time, $success, $bytes);
            [$time, $success, $bytes] = $measureTlsOurs();
            $oursTls->add($time, $success, $bytes);
        }
    }
    printResults('Omegaalfa (TLS direto)', $oursTls, 'Guzzle', $guzzleTls);
    printResults('Guzzle (TLS direto)', $guzzleTls);

    $connectProxy = new \Tests\Omegaalfa\HttpClient\Support\LocalConnectProxyServer('127.0.0.1', $httpsServer->port());
    $tlsProxyUrl = $connectProxy->baseUrl();
    $proxyOurs = $tlsOurs->withProxy($tlsProxyUrl);
    $proxyGuzzle = new \GuzzleHttp\Client([
        'timeout' => 10,
        'connect_timeout' => 5,
        'verify' => false,
        'http_errors' => false,
        'allow_redirects' => ['max' => 3],
        'proxy' => $tlsProxyUrl,
        'headers' => ['Connection' => 'close'],
    ]);

    printSection('Cenário 8: HTTPS local por proxy CONNECT');
    $oursProxyTls = new ResultCollector();
    $guzzleProxyTls = new ResultCollector();
    for ($i = 0; $i < $tlsIterations; $i++) {
        if (($i % 2) === 0) {
            $start = hrtime(true);
            $response = await($proxyOurs->get($tlsUrl));
            $body = $response->body();
            $oursProxyTls->add((hrtime(true) - $start) / 1e9, $response->status() === 200 && $body !== '', strlen($body));

            $start = hrtime(true);
            $response = $proxyGuzzle->get($tlsUrl);
            $body = $response->getBody()->getContents();
            $guzzleProxyTls->add((hrtime(true) - $start) / 1e9, $response->getStatusCode() === 200 && $body !== '', strlen($body));
        } else {
            $start = hrtime(true);
            $response = $proxyGuzzle->get($tlsUrl);
            $body = $response->getBody()->getContents();
            $guzzleProxyTls->add((hrtime(true) - $start) / 1e9, $response->getStatusCode() === 200 && $body !== '', strlen($body));

            $start = hrtime(true);
            $response = await($proxyOurs->get($tlsUrl));
            $body = $response->body();
            $oursProxyTls->add((hrtime(true) - $start) / 1e9, $response->status() === 200 && $body !== '', strlen($body));
        }
    }
    printResults('Omegaalfa (TLS via CONNECT)', $oursProxyTls, 'Guzzle', $guzzleProxyTls);
    printResults('Guzzle (TLS via CONNECT)', $guzzleProxyTls);

    // Optional network test against a user-selected endpoint. Network, DNS and
    // TLS are intentionally measured end-to-end; this does not isolate resolver time.
    $remoteUrl = trim((string)(getenv('HTTPCLIENT_BENCHMARK_URL') ?: ''));
    if ($remoteUrl !== '') {
        $remoteParts = parse_url($remoteUrl);
        if ($remoteParts === false || !isset($remoteParts['host']) || !in_array(strtolower((string)($remoteParts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new InvalidArgumentException('HTTPCLIENT_BENCHMARK_URL must be an absolute HTTP or HTTPS URL');
        }
        $remoteProxy = trim((string)(getenv('HTTPCLIENT_BENCHMARK_PROXY') ?: ''));
        $remoteIterations = max(1, (int)(getenv('HTTPCLIENT_BENCHMARK_REMOTE_REQUESTS') ?: min($iterations, 30)));
        $remoteBatchSize = max(1, (int)(getenv('HTTPCLIENT_BENCHMARK_REMOTE_CONCURRENCY') ?: min($batchSize, 10)));
        $remoteOurs = (new AsyncHttpClient())
            ->withTimeout(30)
            ->connectTimeout(10)
            ->readTimeout(30)
            ->writeTimeout(30)
            ->withKeepAlive(true);
        if ($remoteProxy !== '') {
            $remoteOurs = $remoteOurs->withProxy($remoteProxy);
        }
        $remoteGuzzleConfig = [
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => ['max' => 3],
            'decode_content' => false,
            'headers' => ['Connection' => 'keep-alive'],
        ];
        if ($remoteProxy !== '') {
            $remoteGuzzleConfig['proxy'] = $remoteProxy;
        }
        $remoteGuzzle = new \GuzzleHttp\Client($remoteGuzzleConfig);

        printSection('Cenário opcional: endpoint remoto real (DNS + rede + TLS)');
        printf("URL: %s\nProxy: %s\nRequests por cliente: %d; concorrência: %d\n", $remoteUrl, $remoteProxy !== '' ? $remoteProxy : '(none)', $remoteIterations, $remoteBatchSize);
        echo "Nota: métricas end-to-end incluem DNS, connect, TLS e servidor remoto; não isolam DNS.\n";

        try {
            await($remoteOurs->get($remoteUrl));
        } catch (Throwable $exception) {
            printf("Warmup HttpClient falhou: %s\n", $exception->getMessage());
        }
        try {
            $remoteGuzzle->get($remoteUrl);
        } catch (Throwable $exception) {
            printf("Warmup Guzzle falhou: %s\n", $exception->getMessage());
        }

        $remoteOursSerial = new ResultCollector();
        $remoteGuzzleSerial = new ResultCollector();
        for ($i = 0; $i < $remoteIterations; $i++) {
            foreach (($i % 2) === 0 ? ['ours', 'guzzle'] : ['guzzle', 'ours'] as $clientName) {
                $start = hrtime(true);
                try {
                    if ($clientName === 'ours') {
                        $response = await($remoteOurs->get($remoteUrl));
                        $body = $response->body();
                        $success = $response->status() >= 200 && $response->status() < 400;
                    } else {
                        $response = $remoteGuzzle->get($remoteUrl);
                        $body = $response->getBody()->getContents();
                        $success = $response->getStatusCode() >= 200 && $response->getStatusCode() < 400;
                    }
                    $elapsed = (hrtime(true) - $start) / 1e9;
                    $collector = $clientName === 'ours' ? $remoteOursSerial : $remoteGuzzleSerial;
                    $collector->add($elapsed, $success, strlen($body));
                } catch (Throwable) {
                    $elapsed = (hrtime(true) - $start) / 1e9;
                    $collector = $clientName === 'ours' ? $remoteOursSerial : $remoteGuzzleSerial;
                    $collector->add($elapsed, false);
                }
            }
        }
        printResults('HttpClient (remoto serial)', $remoteOursSerial, 'Guzzle', $remoteGuzzleSerial);
        printResults('Guzzle (remoto serial)', $remoteGuzzleSerial);

        $remoteOursBatch = new ResultCollector();
        $remoteGuzzleBatch = new ResultCollector();
        $remoteCompleted = 0;
        $remoteBatchNumber = 0;
        while ($remoteCompleted < $remoteIterations) {
            $count = min($remoteBatchSize, $remoteIterations - $remoteCompleted);
            $runOursRemoteBatch = static function () use ($remoteOurs, $remoteUrl, $count): array {
                $start = hrtime(true);
                $futures = [];
                for ($j = 0; $j < $count; $j++) {
                    $futures[] = $remoteOurs->get($remoteUrl);
                }
                $settled = awaitSettled($futures);
                $successes = 0;
                $bytes = 0;
                foreach ($settled as $item) {
                    if (($item['state'] ?? '') === 'fulfilled' && $item['value']->status() >= 200 && $item['value']->status() < 400) {
                        $successes++;
                        $bytes += strlen($item['value']->body());
                    }
                }
                return [(hrtime(true) - $start) / 1e9, $successes, $bytes];
            };
            $runGuzzleRemoteBatch = static function () use ($remoteGuzzle, $remoteUrl, $count): array {
                $start = hrtime(true);
                $promises = [];
                for ($j = 0; $j < $count; $j++) {
                    $promises[] = $remoteGuzzle->getAsync($remoteUrl);
                }
                $settled = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
                $successes = 0;
                $bytes = 0;
                foreach ($settled as $item) {
                    if (($item['state'] ?? '') === 'fulfilled' && $item['value']->getStatusCode() >= 200 && $item['value']->getStatusCode() < 400) {
                        $successes++;
                        $bytes += strlen((string)$item['value']->getBody());
                    }
                }
                return [(hrtime(true) - $start) / 1e9, $successes, $bytes];
            };

            foreach (($remoteBatchNumber % 2) === 0 ? ['ours', 'guzzle'] : ['guzzle', 'ours'] as $clientName) {
                [$elapsed, $successes, $bytes] = $clientName === 'ours' ? $runOursRemoteBatch() : $runGuzzleRemoteBatch();
                if ($clientName === 'ours') {
                    $remoteOursBatch->addBatch($elapsed, $successes, $count, $bytes);
                } else {
                    $remoteGuzzleBatch->addBatch($elapsed, $successes, $count, $bytes);
                }
            }
            $remoteCompleted += $count;
            $remoteBatchNumber++;
        }
        printResults('HttpClient (remoto batch)', $remoteOursBatch, 'Guzzle', $remoteGuzzleBatch);
        printResults('Guzzle (remoto batch)', $remoteGuzzleBatch);

        $dnsStart = hrtime(true);
        $resolvedAddress = gethostbyname((string)$remoteParts['host']);
        printf("Resolver PHP (diagnóstico separado, possivelmente cacheado): %.3f ms; endereço: %s\n", (hrtime(true) - $dnsStart) / 1e6, $resolvedAddress);
        if ($remoteOursSerial->errors + $remoteGuzzleSerial->errors + $remoteOursBatch->errors + $remoteGuzzleBatch->errors > 0) {
            echo "Aviso: houve falhas no endpoint externo; interprete esse cenário separadamente.\n";
        }
    }

    // ============================================================
    // PROTOCOL CORRECTNESS MATRIX (representative, not exhaustive)
    // ============================================================
    printSection('Matriz de protocolo HTTP (correção, não throughput)');
    $protocolOurs = (new AsyncHttpClient())->withKeepAlive(true);
    $protocolGuzzle = new \GuzzleHttp\Client([
        'timeout' => 10,
        'http_errors' => false,
        'cookies' => true,
        'allow_redirects' => ['max' => 3],
    ]);
    $formHeaders = ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'];
    $protocolCases = [
        'redirect relativo' => [
            static fn (): bool => (await($protocolOurs->get($base . '/redirect')))->status() === 200,
            static fn (): bool => $protocolGuzzle->get($base . '/redirect')->getStatusCode() === 200,
        ],
        'método HEAD sem corpo' => [
            static function () use ($protocolOurs, $base): bool {
                $response = await($protocolOurs->head($base . '/json'));
                return $response->status() === 200 && $response->body() === '';
            },
            static function () use ($protocolGuzzle, $base): bool {
                $response = $protocolGuzzle->head($base . '/json');
                return $response->getStatusCode() === 200 && (string)$response->getBody() === '';
            },
        ],
        'resposta 204' => [
            static fn (): bool => (await($protocolOurs->get($base . '/empty')))->status() === 204,
            static fn (): bool => $protocolGuzzle->get($base . '/empty')->getStatusCode() === 204,
        ],
        'resposta chunked' => [
            static fn (): bool => (await($protocolOurs->get($base . '/chunked')))->body() === 'hello',
            static fn (): bool => (string)$protocolGuzzle->get($base . '/chunked')->getBody() === 'hello',
        ],
        'form com charset' => [
            static function () use ($protocolOurs, $base, $formHeaders): bool {
                $response = await($protocolOurs->post($base . '/form', ['scope' => 'read write'], $formHeaders));
                return $response->status() === 200 && ($response->json()['form']['scope'] ?? null) === 'read write';
            },
            static function () use ($protocolGuzzle, $base, $formHeaders): bool {
                $response = $protocolGuzzle->post($base . '/form', [
                    'headers' => $formHeaders,
                    'body' => http_build_query(['scope' => 'read write']),
                ]);
                return $response->getStatusCode() === 200 && json_decode((string)$response->getBody(), true)['form']['scope'] === 'read write';
            },
        ],
        'persistência de Set-Cookie' => [
            static function () use ($protocolOurs, $base): bool {
                await($protocolOurs->get($base . '/cookie/set'));
                $response = await($protocolOurs->get($base . '/cookie/check'));
                return ($response->json()['cookie'] ?? null) === '1';
            },
            static function () use ($protocolGuzzle, $base): bool {
                $protocolGuzzle->get($base . '/cookie/set');
                $response = $protocolGuzzle->get($base . '/cookie/check');
                return json_decode((string)$response->getBody(), true)['cookie'] === '1';
            },
        ],
    ];
    $protocolFailures = 0;
    printf("\n%-30s | %-10s | %-10s\n", 'Caso', 'Omegaalfa', 'Guzzle');
    echo str_repeat('-', 58) . "\n";
    foreach ($protocolCases as $case => [$oursCheck, $guzzleCheck]) {
        try {
            $oursPass = $oursCheck();
        } catch (Throwable) {
            $oursPass = false;
        }
        try {
            $guzzlePass = $guzzleCheck();
        } catch (Throwable) {
            $guzzlePass = false;
        }
        $protocolFailures += (int)!$oursPass + (int)!$guzzlePass;
        printf("%-30s | %-10s | %-10s\n", $case, $oursPass ? 'PASS' : 'FAIL', $guzzlePass ? 'PASS' : 'FAIL');
    }
    if ($protocolFailures > 0) {
        throw new RuntimeException(sprintf('Protocol matrix failed %d client checks', $protocolFailures));
    }

    // ============================================================
    // RESUMO FINAL
    // ============================================================
    printSection("Resumo Comparativo");

    $scenarios = [
        ['Serial pequeno', $oursSmallSerial, $guzzleSmallSerial],
        ['Batch pequeno', $oursSmallBatch, $guzzleSmallBatch],
        ['Latência 10ms', $oursLatency, $guzzleLatency],
        ['Payload 100KB', $oursLarge, $guzzleLarge],
        ['Lento 150ms', $oursSlow, $guzzleSlow],
        ['Batch + latência', $oursConcLatency, $guzzleConcLatency],
        ['TLS direto local', $oursTls, $guzzleTls],
        ['TLS via CONNECT', $oursProxyTls, $guzzleProxyTls],
    ];

    foreach ($scenarios as [$name, $oursResult, $guzzleResult]) {
        if ($oursResult->errors > 0 || $guzzleResult->errors > 0) {
            throw new RuntimeException(sprintf(
                'Benchmark run invalid: scenario "%s" had %d HttpClient and %d Guzzle failures',
                $name,
                $oursResult->errors,
                $guzzleResult->errors
            ));
        }
    }

    printf("\n%-25s | %-12s | %-12s | %-10s | %-10s\n", 'Cenário', 'Omegaalfa', 'Guzzle', 'Diferença', 'Vencedor');
    echo str_repeat('-', 80) . "\n";

    foreach ($scenarios as [$name, $o, $g]) {
        $oAvg = $o->avg() * 1000;
        $gAvg = $g->avg() * 1000;
        $diff = $gAvg > 0 ? (($gAvg - $oAvg) / $gAvg) * 100 : 0;
        $winner = $oAvg < $gAvg ? 'Omegaalfa' : ($gAvg < $oAvg ? 'Guzzle' : 'Empate');
        printf("%-25s | %8.3f ms | %8.3f ms | %8.1f%% | %s\n", $name, $oAvg, $gAvg, $diff, $winner);
    }

    echo "\n💡 Nota: Diferenças abaixo de 10% podem ser ruído estatístico.\n";
    echo "   Em cenários com latência real de rede (>50ms), a diferença entre libs\n";
    echo "   tende a ser insignificante comparada ao tempo de rede.\n";

} finally {
    $connectProxy?->stop();
    $httpsServer?->stop();
    $benchmarkCertificate?->cleanup();
    $server->stop();
}
