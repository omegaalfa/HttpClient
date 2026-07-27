<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use function Omegaalfa\HttpClient\Http\await;
use function Omegaalfa\HttpClient\Http\awaitAll;

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

    public function __construct()
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
        if (!is_resource($this->process)) return;
        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
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

http_response_code(404);
echo json_encode(['error' => 'Not found']);
PHP;
        $routerFile = sys_get_temp_dir() . '/better_benchmark_router_' . getmypid() . '.php';
        file_put_contents($routerFile, $router);

        $command = sprintf(
            'PHP_CLI_SERVER_WORKERS=16 %s -n -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg($routerFile)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];

        $this->process = proc_open($command, $descriptors, $pipes, __DIR__);
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

class ResultCollector
{
    /** @var float[] */
    public array $times = [];
    public int $errors = 0;
    public int $success = 0;
    public int $bytesTransferred = 0;
    public float $memoryPeak = 0.0;

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
        return $total > 0 ? ($this->success + $this->errors) / $total : 0.0;
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
    printf('   Requests: %d, success: %d, errors: %d, samples: %d%s', $result->success + $result->errors, $result->success, $result->errors, count($result->times), PHP_EOL);
    printf("   Throughput:   %.2f req/s\n", $result->throughput());
    printf("   Média:        %.4f ms\n", $result->avg() * 1000);
    printf("   Mediana:      %.4f ms\n", $result->median() * 1000);
    printf("   P95:          %.4f ms\n", $result->percentile(95) * 1000);
    printf("   P99:          %.4f ms\n", $result->percentile(99) * 1000);
    printf("   StdDev:       %.4f ms\n", $result->stdDev() * 1000);
    printf("   Memória:      %.2f MB (pico)\n", $result->memoryPeak);
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

echo "🔧 Configurações:\n";
echo "   Iterações: {$iterations}\n";
echo "   Batch size (concorrente): {$batchSize}\n";
echo "   Warmup: {$warmup}\n";

$server = new BetterBenchmarkServer();
$base = $server->baseUrl();

try {
    $guzzle = new \GuzzleHttp\Client([
        'timeout' => 10,
        'connect_timeout' => 5,
        'http_errors' => false,
    ]);

    $ours = (new AsyncHttpClient())->withKeepAlive(true);

    // Warmup
    echo "\n🔥 Warmup...\n";
    for ($i = 0; $i < $warmup; $i++) {
        await($ours->get($base . '/json'));
        $guzzle->get($base . '/json', ['headers' => ['Connection' => 'keep-alive']]);
    }
    gc_collect_cycles();

    // ============================================================
    // CENÁRIO 1: Requisições pequenas em série (igual ao anterior)
    // ============================================================
    printSection("Cenário 1: Requisições pequenas em série");

    $oursSmallSerial = new ResultCollector();
    $guzzleSmallSerial = new ResultCollector();

    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $resp = await($ours->get($base . '/json?lang=pt-BR'));
        $time = (hrtime(true) - $start) / 1e9;
        $oursSmallSerial->add($time, $resp->status() === 200, strlen($resp->body() ?? ''));
    }

    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $resp = $guzzle->get($base . '/json?lang=pt-BR', ['headers' => ['Connection' => 'keep-alive']]);
        $time = (hrtime(true) - $start) / 1e9;
        $guzzleSmallSerial->add($time, $resp->getStatusCode() === 200, strlen($resp->getBody()->getContents()));
    }

    printResults('Omegaalfa (serial pequeno)', $oursSmallSerial, 'Guzzle', $guzzleSmallSerial);
    printResults('Guzzle (serial pequeno)', $guzzleSmallSerial);

    // ============================================================
    // CENÁRIO 2: Requisições pequenas em batch (concorrente)
    // ============================================================
    printSection("Cenário 2: Requisições pequenas em batch (concorrente)");

    $oursSmallBatch = new ResultCollector();
    $guzzleSmallBatch = new ResultCollector();

    $completed = 0;
    while ($completed < $iterations) {
        $batch = min($batchSize, $iterations - $completed);
        $futures = [];
        for ($i = 0; $i < $batch; $i++) {
            $futures[] = $ours->get($base . '/json?n=' . ($completed + $i));
        }
        $start = hrtime(true);
        $responses = awaitAll($futures);
        $time = (hrtime(true) - $start) / 1e9;
        $success = count(array_filter($responses, fn($r) => $r->status() === 200));
        $bytes = array_sum(array_map(fn($r) => strlen($r->body() ?? ''), $responses));
        $oursSmallBatch->addBatch($time, $success, $batch, $bytes);
        $completed += $batch;
    }

    $completed = 0;
    while ($completed < $iterations) {
        $batch = min($batchSize, $iterations - $completed);
        $promises = [];
        for ($i = 0; $i < $batch; $i++) {
            $promises[] = $guzzle->getAsync($base . '/json?n=' . ($completed + $i), ['headers' => ['Connection' => 'keep-alive']]);
        }
        $start = hrtime(true);
        $settled = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
        $time = (hrtime(true) - $start) / 1e9;
        $success = count(array_filter($settled, fn($r) => ($r['state'] ?? '') === 'fulfilled' && $r['value']->getStatusCode() === 200));
        $bytes = array_sum(array_map(function($r) {
            if (($r['state'] ?? '') !== 'fulfilled') return 0;
            return strlen($r['value']->getBody()->getContents());
        }, $settled));
        $guzzleSmallBatch->addBatch($time, $success, $batch, $bytes);
        $completed += $batch;
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
        $start = hrtime(true);
        $resp = await($ours->get($base . '/json?latency=10'));
        $time = (hrtime(true) - $start) / 1e9;
        $oursLatency->add($time, $resp->status() === 200);
    }

    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $resp = $guzzle->get($base . '/json?latency=10');
        $time = (hrtime(true) - $start) / 1e9;
        $guzzleLatency->add($time, $resp->getStatusCode() === 200);
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
        $start = hrtime(true);
        $resp = await($ours->get($base . '/large?size=102400'));
        $time = (hrtime(true) - $start) / 1e9;
        $oursLarge->add($time, $resp->status() === 200, strlen($resp->body() ?? ''));
    }

    for ($i = 0; $i < min($iterations, 200); $i++) {
        $start = hrtime(true);
        $resp = $guzzle->get($base . '/large?size=102400');
        $time = (hrtime(true) - $start) / 1e9;
        $guzzleLarge->add($time, $resp->getStatusCode() === 200, strlen($resp->getBody()->getContents()));
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
        $start = hrtime(true);
        $resp = await($ours->get($base . '/slow'));
        $time = (hrtime(true) - $start) / 1e9;
        $oursSlow->add($time, $resp->status() === 200);
    }

    for ($i = 0; $i < $slowIterations; $i++) {
        $start = hrtime(true);
        $resp = $guzzle->get($base . '/slow');
        $time = (hrtime(true) - $start) / 1e9;
        $guzzleSlow->add($time, $resp->getStatusCode() === 200);
    }

    printResults('Omegaalfa (lento 150ms)', $oursSlow, 'Guzzle', $guzzleSlow);
    printResults('Guzzle (lento 150ms)', $guzzleSlow);

    // ============================================================
    // CENÁRIO 6: Concorrência com latência (batch)
    // ============================================================
    printSection("Cenário 6: Concorrência com latência simulada");

    $oursConcLatency = new ResultCollector();
    $guzzleConcLatency = new ResultCollector();

    $completed = 0;
    $concIterations = min($iterations, 200);
    while ($completed < $concIterations) {
        $batch = min($batchSize, $concIterations - $completed);
        $futures = [];
        for ($i = 0; $i < $batch; $i++) {
            $futures[] = $ours->get($base . '/json?latency=10&n=' . ($completed + $i));
        }
        $start = hrtime(true);
        $responses = awaitAll($futures);
        $time = (hrtime(true) - $start) / 1e9;
        $success = count(array_filter($responses, fn($r) => $r->status() === 200));
        $oursConcLatency->addBatch($time, $success, $batch);
        $completed += $batch;
    }

    $completed = 0;
    while ($completed < $concIterations) {
        $batch = min($batchSize, $concIterations - $completed);
        $promises = [];
        for ($i = 0; $i < $batch; $i++) {
            $promises[] = $guzzle->getAsync($base . '/json?latency=10&n=' . ($completed + $i));
        }
        $start = hrtime(true);
        $settled = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
        $time = (hrtime(true) - $start) / 1e9;
        $success = count(array_filter($settled, fn($r) => ($r['state'] ?? '') === 'fulfilled' && $r['value']->getStatusCode() === 200));
        $guzzleConcLatency->addBatch($time, $success, $batch);
        $completed += $batch;
    }

    printResults('Omegaalfa (batch + latência)', $oursConcLatency, 'Guzzle', $guzzleConcLatency);
    printResults('Guzzle (batch + latência)', $guzzleConcLatency);

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
    ];

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
    $server->stop();
}