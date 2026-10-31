# Relatório de Benchmark e Análise de Performance: HttpClient

> **Ferramenta:** `php benchmark.php` — 6 cenários comparando **Omegaalfa HttpClient**
> (streams + Fibers + `FiberEventLoop`) vs **Guzzle 7** (extensão cURL via `curl_multi`).
> Ambiente: PHP 8.5.4 (CLI), WSL2/Linux, servidor de teste `php -S` com
> `PHP_CLI_SERVER_WORKERS=16` em `127.0.0.1`. Iterações: 500 (batch de 25, warmup 50).
>
> **Nota metodológica importante:** o `HttpClient` **não utiliza cURL** — o transporte é
> feito com sockets non-blocking (`stream_socket_client` + `stream_select`) e pool próprio
> de conexões. Toda menção a cURL nesta análise refere-se ao baseline Guzzle do benchmark
> (que usa `CurlFactory`/`curl_multi`) e às oportunidades de configuração desse lado.

> **Atualização em 31/10/2026:** as seções 1–6 preservam análises e medições
> históricas. Recomendações antigas sobre polling e limitações de headers/SSE
> foram parcialmente superadas; consulte as seções 7–8 para a metodologia atual e
> a comparação mais recente. Resultados de execuções isoladas são indicativos, não
> uma garantia de ganho ou regressão.

## 1. Resumo Executivo

Em três execuções completas (44s, 44s e 43s de duração total), o Omegaalfa HttpClient
apresentou **latência média 15–24% menor em requisições seriais**, **11–20% menor em
payloads de 100KB** e **vantagem consistente de 31–42% em concorrência com latência
simulada** (cenário mais próximo de uso real com APIs externas). Nos cenários dominados
por latência de rede (10ms e 150ms), a diferença entre as bibliotecas cai para <3% — o
tempo de rede domina, como esperado.

O único cenário onde o Guzzle foi superior no baseline foi o **batch pequeno sem latência**
(lotes de 25 requisições instantâneas em localhost): −16,3% para o Omegaalfa. Porém, na
execução com configuração de produção (OPcache CLI + JIT, sem Xdebug), **essa diferença
desaparece e inverte** (Omegaalfa +0,8%): o overhead residual é de interpretação de PHP
puro, não de I/O — e desaparece com JIT ativo.

**Veredito:** a biblioteca é eficiente e competitiva com a implementação em C (cURL) do
Guzzle, vencendo em 5 de 6 cenários de fábrica e em 6 de 6 com configuração de produção.
O consumo de memória é estável (8 MB de pico mesmo com 25 lotes concorrentes de 100KB).
A principal margem de ganho está em **tornar o loop de espera de I/O orientado a eventos**
(em vez de polling cooperativo) e em micro-otimizações de parsing de headers.

## 2. Métricas Coletadas (`php benchmark.php`)

### 2.4 Drenagem de Fibers prontos — modo interpretado, sem JIT

Executado com `php benchmark.php` em PHP 8.5.4, sem flags de OPcache ou JIT. A execução final concluiu 1.500 requisições sem falhas e informou pico de memória de 8–10 MB. O loop agora drena todos os Fibers sinalizados por uma seleção antes de selecionar novamente. Se há trabalho pronto, o próximo select é não bloqueante; se só restam Fibers suspensos em sockets, o loop espera até a prontidão ou o próximo timer/deadline. O transporte tenta I/O não bloqueante diretamente antes de suspender o Fiber. Os conjuntos de descritores são indexados por ID de recurso; `stream_select` ainda exige arrays de entrada mutáveis.

“Antes” é a medição imediatamente anterior a esta correção; “Depois” é a execução final. Ambas usam `php benchmark.php` sem JIT. Há variação entre execuções, especialmente nos cenários curtos. Os batches reportam duração/P95 por lote.

| Cenário | Antes: RPS | Depois: RPS | Δ RPS | Antes: P95 | Depois: P95 | Δ P95 |
|---|---:|---:|---:|---:|---:|---:|
| Serial pequeno | 1.457,03 | 1.229,93 | −15,6% | 0,7437 ms | 1,2495 ms | +68,0% |
| Batch pequeno | 2.194,21 | 1.877,26 | −14,4% | 11,9708 ms | 14,9618 ms | +25,0% |
| Latência 10 ms | 91,41 | 91,50 | +0,1% | 11,0545 ms | 11,2218 ms | +1,5% |
| Payload 100 KB | 1.109,52 | 1.252,40 | +12,9% | 1,0628 ms | 0,8660 ms | −18,5% |
| Lento 150 ms | 6,62 | 6,61 | −0,2% | 151,2766 ms | 151,5570 ms | +0,2% |
| Batch + latência | 456,24 | 770,19 | +68,8% | 60,2408 ms | 32,7089 ms | −45,7% |

O batch com latência recuperou de 456 para 770 req/s e P95 de 60,24 para 32,71 ms, próximo do histórico de 776 req/s. A meta estrita de throughput acima de 776 req/s não foi atingida nesta execução; o resultado fica 0,8% abaixo dela. O ganho de throughput do batch pequeno (+13,5%) também não se reproduziu nesta execução, então os números curtos devem ser lidos considerando a variação observada.

### 2.1 Execução de referência — Run 1 (baseline: Xdebug carregado, OPcache CLI off)

| Cenário | Cliente | Throughput | Média | P95 | P99 | Pico de memória |
|---|---|---|---|---|---|---|
| Serial pequeno (500 req) | **Omegaalfa** | **1.654 req/s** | **0,605 ms** | **0,666 ms** | 1,044 ms | 8,00 MB |
| | Guzzle | 1.269 req/s | 0,788 ms | 0,851 ms | 0,916 ms | 8,00 MB |
| Batch pequeno (20 × 25 req) | Omegaalfa | 2.077 req/s | 12,04 ms/lote | 12,86 ms | 13,61 ms | 8,00 MB |
| | **Guzzle** | **2.416 req/s** | **10,35 ms/lote** | 11,57 ms | 13,20 ms | 8,00 MB |
| Latência 10ms (500 req) | **Omegaalfa** | **93,6 req/s** | **10,68 ms** | **10,78 ms** | **11,15 ms** | 8,00 MB |
| | Guzzle | 90,9 req/s | 11,00 ms | 11,14 ms | 11,25 ms | 8,00 MB |
| Payload 100KB (200 req) | **Omegaalfa** | **1.340 req/s** | **0,746 ms** | **0,823 ms** | **0,920 ms** | 8,00 MB |
| | Guzzle | 1.074 req/s | 0,931 ms | 1,009 ms | 1,106 ms | 8,00 MB |
| Lento 150ms (100 req) | **Omegaalfa** | **6,63 req/s** | **150,89 ms** | **150,96 ms** | **151,23 ms** | 8,00 MB |
| | Guzzle | 6,61 req/s | 151,33 ms | 151,50 ms | 151,57 ms | 8,00 MB |
| Batch + latência (8 × 25 req) | **Omegaalfa** | **776 req/s** | **32,20 ms/lote** | **32,53 ms** | **32,53 ms** | 8,00 MB |
| | Guzzle | 532 req/s | 47,03 ms/lote | 67,35 ms | 67,35 ms | 8,00 MB |

- **Tempo total de execução:** 44 s (runs 1 e 2); 43 s (run 3).
- **Pico de memória:** 8,00 MB (real, `memory_get_peak_usage(true)`) em **todos** os
  cenários e clientes — inclusive no lote concorrente de 100KB (≈ 2,5 MB em voo).
- **Transferência:** 37 KB (serial), 20,5 MB (cenário de payload grande), sem erros em
  nenhuma execução (1.500 requisições bem-sucedidas por run, 0 falhas).

### 2.2 Consistência estatística (Run 1 vs Run 2, mesma configuração)

| Cenário (média Omegaalfa) | Run 1 | Run 2 | Δ |
|---|---|---|---|
| Serial pequeno | 0,605 ms | 0,598 ms | 1,2% |
| Batch pequeno (por lote) | 12,04 ms | 12,01 ms | 0,2% |
| Latência 10ms | 10,68 ms | 10,67 ms | 0,1% |
| Payload 100KB | 0,746 ms | 0,758 ms | 1,6% |
| Lento 150ms | 150,89 ms | 150,87 ms | 0,01% |
| Batch + latência (por lote) | 32,20 ms | 32,49 ms | 0,9% |

As medições do Omegaalfa variaram **≤1,6% entre execuções** — excelente reprodutibilidade.
O Guzzle variou mais no batch puro (10,35 vs 11,24 ms/lote, Δ 8,6%), refletindo o
escalonamento do `curl_multi` sob lotes curtos.

### 2.3 Impacto da configuração PHP (Run 3: Xdebug off + `opcache.enable_cli=1` + JIT tracing)

| Cenário (Omegaalfa) | Baseline | Config. produção | Ganho |
|---|---|---|---|
| Serial pequeno | 0,605 ms (1.654 req/s) | 0,552 ms (1.811 req/s) | **+9,5%** |
| Batch pequeno (por lote) | 12,04 ms (2.077 req/s) | 9,41 ms (2.657 req/s) | **+28%** |
| Payload 100KB | 0,746 ms (1.340 req/s) | 0,683 ms (1.465 req/s) | **+9,3%** |
| Latência 10ms / Lento 150ms | 10,68 / 150,89 ms | 10,67 / 150,82 ms | ~0% (I/O-bound) |

Com a configuração de produção, o Omegaalfa passa a vencer **todos os 6 cenários**
(batch pequeno inverte de −16,3% para +0,8%). O efeito é assimétrico: como o Omegaalfa é
100% PHP (vs caminho em C do cURL), ele se beneficia **mais** de OPcache/JIT do que o
Guzzle — a comparação justa entre as libs é a do run 3.

## 3. Gargalos Identificados

### 3.1 I/O e handles de transporte (cURL no baseline; sockets no Omegaalfa)

- **Polling cooperativo no aguardo de I/O** (`SocketTransport::waitForSocket()`,
  `src/Http/SocketTransport.php:747` e `StreamResponse::waitForReadable()`,
  `src/Http/StreamResponse.php:254`): o loop faz `stream_select(..., 0, 0)` (timeout zero)
  seguido de `$this->loop->next()` **a cada iteração**. Com N requisições concorrentes,
  cada passo do event loop executa N `stream_select` + N trocas de Fiber. É o maior
  consumidor de CPU no cenário de batch — e explica a derrota parcial no batch puro do
  baseline. O `curl_multi` do Guzzle resolve isso em C com um único poll por iteração.
- **Reuso de conexões:** efetivo — o `ConnectionPool` (habilitado com `withKeepAlive(true)`
  no benchmark) sustenta 0,6 ms/requisição serial em localhost (sem custo de handshake por
  request). Porém, `ConnectionPool::isReusable()` (`src/Http/ConnectionPool.php:115`)
  executa `feof()` + `stream_select` em cada `acquire()` — 2 syscalls extras por requisição.
- **cURL Share Handles (lado Guzzle):** o `CurlFactory` do Guzzle 7 suporta share handles
  (`TransportSharing`/`CurlShareHandle`, para compartilhar caches de DNS/SSL entre handles),
  mas o padrão é `TransportSharing::NONE` e o benchmark não o habilita. O pool de easy
  handles em si é eficiente (`curl_reset` + reuso; até 50 handles no `CurlMultiHandler`).
  Nenhuma dessas otimizações se aplica ao Omegaalfa, que não usa cURL.
- **DNS:** conexões reutilizadas não resolvem; novas conexões usam `getaddrinfo` (cache do
  SO). Não há cache de DNS dedicado na biblioteca — irrelevante em localhost/IP, moderado
  para hosts reais sem keep-alive.

### 3.2 CPU / processamento

- **Hotspot primário:** o polling descrito acima (cada iteração: syscall `stream_select` +
  `Fiber::suspend`/`resume` + cálculo de deadlines com `hrtime`).
- **Parsing de headers** (`SocketTransport::parseStatus()` `:842`, `Headers::fromRaw()`
  `src/Http/Headers.php:39`): `preg_match` por status line e `preg_split` com regex de 3
  ramos por bloco de headers, em **toda** resposta — caminho quente.
- **`writePayload()`** (`SocketTransport.php:513`): chunking duplo (`substr` de 8KB no
  payload e de novo no chunk) — overhead extra em uploads de corpo grande.
- **Clones de `Headers` por requisição:** `request()` clona os headers de opções e faz
  `merge()`; `prepareRequest()` (`SocketTransport.php:151`) clona de novo — até 3 cópias
  do mapa de headers por request.
- **`Set-Cookie` parseado duas vezes** por resposta (uma em `extractCookies()`, outra em
  `CookieJar::storeFromHeaders()`).
- Overhead estrutural de Fiber/Future por requisição (~3 objetos + 1 Fiber) — baixo e
  amortizado; o custo real está nas retomadas por iteração de polling, não na criação.

### 3.3 Alocação de memória

- **Pico estável de 8 MB** em todos os cenários — sem vazamentos e com folga para lotes de
  25 × 100KB. O upload multipart já usa buffer `php://temp` com spill em disco (não
  materializa arquivos grandes).
- Cópias evitáveis por requisição de download: `substr($body, 0, $contentLength)` de
  recorte (`readResponse`), cópia do `ChunkedDecoder` (apenas em chunked) e `gzdecode`
  (apenas com `Content-Encoding: gzip`).
- `Response::headers()` devolve `clone` a cada chamada — quem acessar headers em loop paga
  uma cópia por acesso (não exercitado no benchmark).
- `gc_collect_cycles()` no benchmark é uma única chamada pós-warmup; o GC cíclico do PHP
  opera por contagem de raízes (1.000) e não apareceu como gargalo nos números.

## 4. Recomendações de Otimização

### 1. [ALTO] Espera de I/O orientada a eventos (elimina o polling)

**Impacto estimado:** +15–30% de throughput em cenários concorrentes (batch); reduz CPU ociosa.

**Antes** (`SocketTransport::waitForSocket`, `src/Http/SocketTransport.php:747`):

```php
while (true) {
    $this->ensureTotalTimeout($totalDeadlineNs);
    if (hrtime(true) >= $deadlineNs) {
        throw new TimeoutException($message);
    }

    $readStreams = $read ? [$socket] : [];
    $writeStreams = $write ? [$socket] : [];
    $ready = @stream_select($readStreams, $writeStreams, $except, 0, 0); // syscall por iteração

    if ($ready > 0) {
        return;
    }

    $this->loop->next(); // retoma imediatamente — 1 syscall + 1 Fiber switch por volta
}
```

**Depois** (registrando o socket no event loop, zero polling):

```php
private function waitForSocket($socket, bool $read, bool $write, float $timeoutSeconds, string $message, int $totalDeadlineNs): void
{
    $deadlineNs = hrtime(true) + (int)($timeoutSeconds * 1_000_000_000);
    $result = null;

    $watcher = $read
        ? $this->loop->onReadable($socket, function () use (&$result): void { $result = 'ready'; })
        : $this->loop->onWritable($socket, function () use (&$result): void { $result = 'ready'; });

    try {
        while ($result === null) {
            $this->ensureTotalTimeout($totalDeadlineNs);
            if (hrtime(true) >= $deadlineNs) {
                throw new TimeoutException($message);
            }
            $this->loop->next(); // sem syscall: só retoma quando o watcher dispara
        }
    } finally {
        $this->loop->cancel($watcher);
    }
}
```

> Nota: `FiberEventLoop` já expõe `onReadable()`/`onWritable()`/`cancel()` — a primitiva
> existe; basta trocar o mecanismo de espera. O mesmo vale para
> `StreamResponse::waitForReadable()` (`src/Http/StreamResponse.php:254`).

### 2. [MÉDIO] Fast-path de parsing de headers e status line

**Impacto estimado:** −3 a −6% de CPU por requisição.

**Antes** (`Headers::fromRaw` + `parseStatus`):

```php
$lines = preg_split('/\r\n|\n|\r/', trim($rawHeaders)) ?: [];
// ...
$statusLine = strtok($headerBlock, "\r\n") ?: '';
if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $matches) !== 1) { ... }
```

**Depois** (o framing já garante CRLF; status ocupa as posições fixas 9–11):

```php
$lines = explode("\r\n", $rawHeaders);
// ...
$status = (int) substr($headerBlock, 9, 3); // "HTTP/1.1 200 OK"
if ($status < 100 || $status > 599) {
    throw new ConnectionException('Invalid HTTP response status line');
}
```

### 3. [MÉDIO] Eliminar clones redundantes de `Headers` por requisição

**Impacto estimado:** −2 a −4% de CPU/alocação por requisição.

**Antes** (`AsyncHttpClient::request()` + `SocketTransport::prepareRequest()`):

```php
$request = new Request(..., Headers::from($this->options->headers)->merge($headers), ...);
// ...
$headers = Headers::from($request->headers); // terceira cópia do mapa
```

**Depois** (construir o cabeçalho de fio uma única vez; copiar só quando houver o que
adicionar):

```php
$requestHeaders = $headers === []
    ? Headers::from($this->options->headers)          // 1 cópia
    : Headers::from($this->options->headers)->merge($headers);

// em prepareRequest: mutar uma cópia só quando o request herdar headers de redirect
```

### 4. [MÉDIO] Escrita em camada única com blocos maiores (uploads)

**Impacto estimado:** −10–20% de tempo de upload de corpos grandes (1MB+).

**Antes** (`writePayload`, `src/Http/SocketTransport.php:513`):

```php
$chunk = substr($payload, $offset, 8192);       // fatia 8KB
// ...
$written = @fwrite($socket, substr($chunk, $chunkOffset, 8192)); // fatia de novo
```

**Depois** (uma fatia por escrita, blocos de 64KB):

```php
while ($offset < $payloadLength) {
    // ...
    $written = @fwrite($socket, substr($payload, $offset, 65536));
    $offset += $written ?: 0;
    // (mantendo a lógica de retry em $written === 0)
}
```

### 5. [BAIXO] Passagem única de `Set-Cookie`

**Impacto estimado:** −1–2% de CPU em APIs com múltiplos cookies.

**Antes:** `extractCookies()` e `CookieJar::storeFromHeaders()` percorrem
`Set-Cookie` separadamente.

**Depois:** o `CookieJar` devolve os pares nome=valor extraídos durante o armazenamento e
`sendWithRedirects` os reaproveita ao montar `Response`.

### 6. [BAIXO] Estado de reuso do pool registrado na liberação

**Impacto estimado:** elimina 2 syscalls por requisição reutilizada.

**Antes:** `ConnectionPool::isReusable()` (`src/Http/ConnectionPool.php:115`) faz
`feof()` + `stream_select` a cada `acquire()`.

**Depois:** ao `release()`, já sabemos se o socket ficou limpo (consumimos exatamente
`Content-Length`/framing); marcar o socket como "limpo" e verificar apenas sockets
parados há mais de N segundos (ou pares que enviam dados espontâneos).

### 7. [BAIXO] Configuração de produção para workers CLI

**Impacto estimado:** +9–28% conforme medição do Run 3 (seção 2.3).

Recomendação para execução em produção (não é mudança de código):

```ini
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=64M
opcache.validate_timestamps=0
xdebug.mode=off   ; ou não carregar o Xdebug em produção
```

### 8. [BAIXO] Cache de DNS estático (somente para hosts reais)

**Impacto estimado:** eliminável apenas quando keep-alive está desabilitado e há muitos
hosts distintos. Guardar `host => ip` com TTL curto (30–60s) no `ConnectionPool`.

## 5. Próximos Passos

Ordenado por **ganho de performance ÷ esforço**:

1. **Espera orientada a eventos** (Recomendação 1) — maior ganho; usa primitivas já
   existentes no `FiberEventLoop`; coberto pelos testes atuais de timeout/streaming.
2. **Configuração de produção** (Recomendação 7) — ganho imediato e sem código; documentar
   no README e usar `opcache`/`JIT` nos workers CLI e no CI de performance.
3. **Fast-path de parsing** (Recomendação 2) — mudança pequena e mensurável; adicionar
   teste de regressão de parsing (casos-limite de status line) junto.
4. **Remoção de clones de `Headers`** (Recomendação 3) — cuidado com o caminho de redirect
   (que clona para strip de credenciais); os 152 testes atuais dão rede de segurança.
5. **Escrita em blocos de 64KB** (Recomendação 4) — relevante para uploads; validar com
   cenário de payload grande em POST (o benchmark atual mede GET).
6. **Passagem única de `Set-Cookie` + estado do pool** (Recomendações 5–6) — micro-ganhos,
   fazer junto com outra mudança para amortizar revisão.
7. **Cache de DNS** (Recomendação 8) — só se perfis de produção com muitos hosts sem
   keep-alive justificarem; hoje não há evidência de ganho.

**Sugestão de governança:** adicionar o `benchmark.php` como job de CI não-bloqueante
(comparando contra os números-base deste relatório) e re-rodar este benchmark após cada
otimização acima, sempre na configuração do Run 3 (Xdebug off + OPcache + JIT) — a única
em que a comparação entre as bibliotecas é justa.

## 6. Regressão de segurança e validação de performance (PHP sem JIT)

Após as correções de framing HTTP, isolamento de conexões TLS no pool, redirects,
timeouts totais e headers, o benchmark foi executado com `php benchmark.php`
(PHP 8.5.4; `opcache.enable_cli=Off`, `opcache.jit=disable`). “Antes” é uma execução completa feita antes das
alterações; “Depois” é a mediana de três execuções completas posteriores. Os
cenários curtos variam com a carga do servidor local, então os percentuais
pequenos não demonstram regressão ou ganho por si só.

| Cenário | Antes RPS | Depois RPS | Δ RPS | Antes P95 (ms) | Depois P95 (ms) | Δ P95 |
|---|---:|---:|---:|---:|---:|---:|
| Serial pequeno | 1,405.42 | 1,519.39 | +8.1% | 1.1280 | 0.7347 | -34.9% |
| Batch pequeno | 2,194.49 | 1,946.19 | -11.3% | 15.1850 | 14.1653 | -6.7% |
| Latência 10 ms | 91.14 | 91.70 | +0.6% | 11.5988 | 11.0790 | -4.5% |
| Payload 100 KB | 1,202.52 | 1,232.27 | +2.5% | 0.9669 | 0.9319 | -3.6% |
| Endpoint lento 150 ms | 6.58 | 6.61 | +0.5% | 157.6369 | 151.4719 | -3.9% |
| Batch + latência | 765.87 | 769.50 | +0.5% | 34.5434 | 32.9312 | -4.7% |

O throughput do batch pequeno ficou 11.3% abaixo desta execução de baseline,
enquanto o P95 melhorou 6.7%. Esse cenário apresentou grande variação antes e
depois: a primeira execução de baseline mediu 1,854 req/s e as três posteriores
ficaram entre 1,915 e 2,016 req/s. Portanto, os dados não isolam uma regressão
causada pelo código, mas também não permitem afirmar paridade exata nesse caso;
é recomendável repetir em máquina dedicada antes de usar esse cenário como
critério de release. Nos outros cinco cenários o throughput ficou praticamente
estável ou melhorou. O pico de memória observado foi 8 MB antes e 8–10 MB depois,
com variação do alocador PHP nos cenários de payload.

## 7. Metodologia atual do script

O `benchmark.php` foi ajustado para validar a presença de `ext-curl`, exibir a
versão do PHP, OPcache/JIT, cURL e quantidade de workers do servidor. Os dois
clientes usam os mesmos timeouts, keep-alive, limite de redirects e negociação
sem compressão. O aquecimento inclui chamadas seriais e uma onda concorrente.

As medições seriais alternam qual cliente roda primeiro; os lotes também
alternam a ordem e iniciam o cronômetro antes de criar Futures/promises. O padrão
é coletar 100 amostras de lote. P95/P99 e média dos cenários concorrentes são
tempos por lote, não por request. Throughput conta apenas respostas bem-sucedidas;
qualquer falha invalida a execução. O valor de memória continua sendo o pico
acumulado do processo e não deve ser interpretado como pico isolado por cliente.

Uso: `php benchmark.php [iterations] [batchSize] [warmup] [serverWorkers] [batchSamples]`.
Execute ao menos três vezes e compare medianas. As tabelas anteriores foram
produzidas pela metodologia antiga e não devem ser comparadas diretamente com
resultados futuros deste script.

## 8. Correções de compatibilidade, segurança e TLS — comparação pontual

Executado em 31/10/2026 com `php benchmark.php`, PHP 8.5.4, OPcache CLI desligado,
JIT desativado, 500 iterações, 100 amostras de batch, 16 workers e 50 warmups.
“Antes” foi medido antes das correções desta revisão; “Depois” após elas, com os
mesmos parâmetros. Cada coluna vem de uma única execução. Os tempos seriais e os
percentis de batch são sensíveis à carga local, então esta tabela serve como
regressão de fumaça de performance, não como conclusão estatística.

| Cenário | Antes RPS | Depois RPS | Δ RPS | Antes P95 (ms) | Depois P95 (ms) | Δ P95 |
|---|---:|---:|---:|---:|---:|---:|
| Serial pequeno | 1,532.01 | 1,484.47 | −3.1% | 0.7195 | 0.7645 | +6.3% |
| Batch pequeno | 1,953.03 | 1,863.27 | −4.6% | 15.0849 | 15.8142 | +4.8% |
| Latência 10 ms | 91.90 | 91.76 | −0.2% | 11.0189 | 11.0182 | 0.0% |
| Payload 100 KB | 1,183.89 | 1,222.87 | +3.3% | 0.9999 | 0.9057 | −9.4% |
| Endpoint lento 150 ms | 6.61 | 6.61 | 0.0% | 151.4587 | 151.4378 | 0.0% |
| Batch + latência | 765.75 | 767.62 | +0.2% | 33.0180 | 32.8286 | −0.6% |

Todas as respostas foram bem-sucedidas e o pico acumulado do processo permaneceu
entre 8 e 10 MB. O caminho serial pequeno variou mais que os outros cenários;
como a mudança não reescreveu o event loop ou o caminho comum de leitura, repita
ao menos três vezes em máquina ociosa antes de atribuir essa diferença ao patch.
O benchmark usa HTTP em localhost e não mede o handshake TLS; a mudança TLS foi
validada pela integração HTTPS local. A resolução DNS permanece síncrona.
