# Relatório de Benchmark e Análise de Performance: HttpClient

> **Ferramenta:** `php benchmark.php` — 8 cenários comparando **Omegaalfa HttpClient**
> (streams + Fibers + loop próprio) vs **Guzzle 7** (extensão cURL via `curl_multi`).
> Ambiente: PHP 8.5.4 (CLI), WSL2/Linux, servidores locais de teste com
> `PHP_CLI_SERVER_WORKERS=16` em `127.0.0.1`. Iterações: 500 (batch de 25, warmup 50).
>
> **Nota metodológica importante:** o `HttpClient` **não utiliza cURL** — o transporte é
> feito com sockets non-blocking (`stream_socket_client` + `stream_select`) e pool próprio
> de conexões. Toda menção a cURL nesta análise refere-se ao baseline Guzzle do benchmark
> (que usa `CurlFactory`/`curl_multi`) e às oportunidades de configuração desse lado.

> **Atualização em 31/10/2026:** as seções 2.1–2.4 e 6 preservam métricas históricas.
> As seções 1, 3–5 e 7–10 descrevem o código, as correções e a metodologia atuais;
> a seção 8 preserva a comparação antes/depois. Resultados de execuções isoladas são indicativos, não
> uma garantia de ganho ou regressão.

## 1. Resumo Executivo — estado atual

O benchmark compara seis cenários HTTP locais e acrescenta dois cenários TLS locais
(handshake direto e HTTPS através de proxy CONNECT). Também verifica uma matriz
representativa de comportamento HTTP. O cliente completou os cenários sem falhas, usando
10 MB de pico acumulado. A seção 10 registra a rodada posterior à integração da Public
Suffix List e do resolvedor RFC 3986; a seção 8 preserva a comparação histórica.

O código atual espera prontidão de sockets via `Omegaalfa\HttpClient\Loop\Loop`, faz o connect TCP e
handshake TLS sem bloquear a Fiber, limita headers HTTP/SSE e aplica as correções de
redirect, formulário, timeout e isolamento de cookies descritas na seção 9. Cada coluna
de comparação da seção 8 vem de uma execução;
variações pequenas não demonstram ganho nem regressão estatística.

**Conclusão:** o caminho comum sem `baseUrl` continua retornando sem parse adicional; a PSL
é carregada sob demanda apenas quando é necessário validar um atributo de domínio de cookie.
DNS continua sendo resolvido sistemicamente e pode bloquear.

## 2. Métricas Coletadas (`php benchmark.php`)

> As execuções 2.1–2.4 abaixo são registros de versões/metodologias anteriores. Para
> resultados do código e do script atuais, use a seção 8; os números antigos não devem
> ser tratados como baseline direto.

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

## 3. Leitura dos resultados atuais

A tabela de seis cenários HTTP na seção 8 compara uma execução anterior às correções com
uma execução do código final. Nesta rodada adicional, batch + latência ficou em 766,48
req/s, com P95 de lote 32,9036 ms. Os dois novos cenários TLS também aparecem na seção 8.
Como cada número representa uma única execução, não se deve atribuir diferenças pequenas
ao código sem repetir o teste e comparar medianas.

O benchmark padrão usa servidores locais; mede TLS direto e através de CONNECT, mas não
simula DNS remoto, perda de pacotes ou limites de um servidor real. O modo remoto opcional
é configurável por ambiente e mede esses fatores de ponta a ponta, sem isolar DNS. O
consumo reportado é o pico acumulado do processo e não uma medida de memória por cliente.

## 4. Correções incluídas nesta revisão

- O loop próprio `Omegaalfa\HttpClient\Loop\Loop` aguarda readiness de leitura/escrita
  sem consumir bytes antecipadamente; não há fallback de polling no transporte.
- Conexões HTTPS usam TCP não bloqueante e handshake TLS cooperativo com o event loop.
  O caminho foi validado por testes HTTPS locais, inclusive via proxy CONNECT.
- Headers HTTP têm limites de 64 KiB e 100 linhas; linhas SSE têm limite de 64 KiB.
- Redirects resolvem referências relativas, limpam a query separada da requisição original
  e preservam HEAD. Formulários aceitam parâmetros no media type; métodos POST/PUT/PATCH
  sem conteúdo enviam Content-Length: 0.
- Clientes derivados isolam o CookieJar; timeouts inválidos são rejeitados. O parser SSE
  descarta eventos incompletos no EOF e não despacha blocos sem campo data.
- O CI executa lint PHP em arquivos, PHPStan e CS Fixer; o Composer declara ext-openssl.

## 5. Limitações e recomendações para próximas medições

- A resolução DNS do PHP ainda pode bloquear antes do connect TCP.
- A Public Suffix List estática precisa ser atualizada em releases; a lista não é baixada
  durante as requisições. Em ambientes multi-tenant, também isole os cookie jars.
- O loop de I/O é parte da biblioteca e expõe `Task`; a dependência externa
  `omegaalfa/fiber-event-loop` foi removida. O loop é obtido por `Loop::get()`.
- Para conclusões de performance, rode o benchmark pelo menos três vezes em uma máquina
  ociosa e compare medianas. TLS padrão usa loopback; para medir DNS e rede externa,
  configure o modo remoto. Esses resultados dependem do ambiente e não são previsão direta
  de latência em produção.

## 6. Métricas de uma revisão anterior — histórico (PHP sem JIT)

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
alternam a ordem e iniciam o cronômetro antes de criar Tasks/promises. O padrão
é coletar 100 amostras de lote. P95/P99 e média dos cenários concorrentes são
tempos por lote, não por request. Throughput conta apenas respostas bem-sucedidas;
qualquer falha invalida a execução. O valor de memória continua sendo o pico
acumulado do processo e não deve ser interpretado como pico isolado por cliente.

Uso: `php benchmark.php [iterations] [batchSize] [warmup] [serverWorkers] [batchSamples]`.
Execute ao menos três vezes e compare medianas. As tabelas anteriores foram
produzidas por versões anteriores da metodologia, exceto a seção 8, que usa o script
atual. Não compare diretamente as tabelas históricas 2.1–2.4 e 6 com a seção 8.

## 8. Correções de compatibilidade, segurança e TLS — comparação pontual

O benchmark padrão reporta oito medições de performance: os seis cenários HTTP abaixo,
mais **TLS direto local** e **TLS via proxy CONNECT** (cada requisição estabelece uma
conexão nova). O resumo do processo inclui as oito e falha se qualquer uma tiver erros.
Separadamente, a matriz verifica redirect relativo, HEAD sem corpo, 204, framing chunked,
form com charset e persistência de `Set-Cookie`; esses são checks funcionais, não métricas
de throughput.

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

Todas as respostas dos oito cenários de performance foram bem-sucedidas e o pico acumulado
do processo foi 10 MB. O caminho serial pequeno variou mais que os outros cenários;
como a mudança não reescreveu o event loop ou o caminho comum de leitura, repita
ao menos três vezes em máquina ociosa antes de atribuir essa diferença ao patch.
Os seis cenários HTTP acima vêm da comparação anterior. Na execução desta atualização
(PHP 8.5.4, sem OPcache CLI/JIT, 500 iterações e 100 amostras batch), os cenários TLS
foram executados com 25 requests por cliente, conexão nova por request e certificado
local autoassinado. Os tempos incluem handshake e, no segundo caso, proxy CONNECT.

| Cenário TLS local | Cliente | RPS | Latência P95 |
|---|---|---:|---:|
| TLS direto | HttpClient | 473.58 | 2.2352 ms |
| TLS direto | Guzzle | 431.18 | 2.5217 ms |
| TLS via CONNECT | HttpClient | 380.35 | 2.7910 ms |
| TLS via CONNECT | Guzzle | 311.44 | 3.2113 ms |

Todas as requisições TLS desta rodada tiveram sucesso. A amostra de 25 é adequada como
verificação e referência local, mas é pequena para conclusões robustas; repita antes de
publicar comparações de desempenho. A resolução DNS permanece síncrona.

### Endpoint remoto opcional

Para incluir uma medição real de ponta a ponta, configure `HTTPCLIENT_BENCHMARK_URL` com
uma URL HTTP(S). Opcionalmente use `HTTPCLIENT_BENCHMARK_PROXY` para HTTP proxy ou HTTPS
CONNECT, `HTTPCLIENT_BENCHMARK_REMOTE_REQUESTS` para o total de requests por cliente e
`HTTPCLIENT_BENCHMARK_REMOTE_CONCURRENCY` para o tamanho do lote. O script reporta modos
serial e batch separadamente. Essa execução engloba DNS, conexão, TLS (quando HTTPS),
proxy e servidor remoto; a consulta DNS diagnóstica é separada e pode estar em cache, logo
não isola nem representa o tempo real do resolver. Por padrão não se acessa a rede externa,
para manter as medições repetíveis. Os seis checks da matriz são representativos, não uma
validação exaustiva de todas as extensões e casos-limite dos protocolos.

## 9. Estado técnico e pendências após as correções

### Aplicado

- O loop próprio `Omegaalfa\HttpClient\Loop\Loop` registra waits de leitura/escrita e
  usa `stream_select` bloqueante quando não há tarefas prontas. O caminho padrão não faz
  polling cooperativo nem depende de alterações em `vendor/`.
- A API assíncrona usa `Task` (`await`, `awaitAll`, `awaitAny`, `race` e `awaitSettled`);
  chamadas que aguardam I/O fora de uma Fiber são conduzidas pelo loop incluído.
- Conexões HTTPS são abertas como TCP não bloqueante; o handshake TLS aguarda leitura do
  socket antes de tentar novamente. Os testes locais cobrem HTTPS direto e via CONNECT.
- Respostas são rejeitadas acima de 64 KiB/100 linhas de headers; linhas SSE acima de
  64 KiB também são rejeitadas. Eventos SSE sem terminador em branco no EOF são descartados.
- Redirects resolvem referências relativas, não reaplicam `query` da requisição original
  e preservam o método `HEAD`. Formulários aceitam parâmetros no media type e POST/PUT/PATCH
  vazios enviam `Content-Length: 0`.
- Cookies recebidos com `Domain` são validados contra a Public Suffix List distribuída,
  incluindo regras privadas e curingas. `withBaseUrl()` resolve referências conforme RFC 3986.
- Clientes derivados isolam o `CookieJar`; timeouts de API precisam ser positivos e finitos.
  `composer.json` declara `ext-openssl`, e o CI executa lint PHP real, PHPStan e CS Fixer.

### Limitações restantes

- A resolução DNS do PHP ainda pode bloquear antes da conexão TCP.
- A Public Suffix List distribuída é um snapshot e não se atualiza sozinha; atualize o arquivo
  em releases periódicos. Aplicações multi-tenant também devem isolar seus cookie jars.
- `Loop` é singleton obtido com `Loop::get()` e pode ser injetado em `AsyncHttpClient`;
  a dependência `omegaalfa/fiber-event-loop` não é mais necessária.
- Os números de performance são de uma rodada por estado. Para decisões de release
  baseadas em diferença pequena, rode pelo menos três vezes em máquina ociosa e compare
  medianas; DNS remoto e proxies reais dependem do ambiente e só são medidos quando
  configurados por variáveis de ambiente.

## 10. Verificação de performance após PSL e resolução RFC 3986

Executado com `php benchmark.php` em PHP 8.5.4, sem OPcache CLI ou JIT, 500 iterações,
100 lotes e 50 warmups. Todas as respostas dos oito cenários foram bem-sucedidas; o pico
acumulado reportado foi 10 MB. Esta rodada é uma checagem após as alterações, não uma
comparação controlada antes/depois: os resultados podem variar com a carga local.

| Cenário | HttpClient RPS | HttpClient P95 |
|---|---:|---:|
| Serial pequeno | 1,460.52 | 0.7690 ms |
| Batch pequeno | 1,941.89 | 14.6116 ms por lote |
| Latência 10 ms | 91.71 | 11.0926 ms |
| Payload 100 KB | 1,222.69 | 0.9725 ms |
| Endpoint lento 150 ms | 6.61 | 151.4904 ms |
| Batch + latência | 767.66 | 32.9323 ms por lote |
| TLS direto local | 461.36 | 2.4054 ms |
| TLS via CONNECT | 364.23 | 3.2265 ms |

O resolvedor retorna imediatamente quando não há `baseUrl`; parsing de URI só ocorre
quando essa opção está configurada. A PSL é carregada sob demanda e reutilizada no
processo, portanto a primeira resposta com um atributo `Domain` paga o custo de inicialização.
O benchmark padrão não mede isoladamente esse caminho frio nem cookies cross-domain.


## 11. Migração para o loop incluído

A partir desta revisão, o cliente usa `Omegaalfa\HttpClient\Loop\Loop` e `Task`,
implementados em `src/Loop`. A dependência `omegaalfa/fiber-event-loop` foi removida
do `composer.json`; nenhuma alteração em `vendor/` é necessária. A API assíncrona
retorna `Task` em vez de `Future`, e consumidores devem atualizar os tipos/imports
correspondentes. Esta mudança de tipo público pode exigir uma versão major.

### Medição sem JIT após a migração

Executado com `php -dxdebug.mode=off benchmark.php`, PHP 8.5.4, OPcache CLI desligado,
JIT desativado, 500 iterações, 100 amostras de lote, 16 workers e 50 warmups. O baseline
“Antes” é a seção 10 (código imediatamente anterior à migração); “Depois” é esta execução.
As execuções foram únicas e em datas/condições de carga diferentes, portanto a comparação
é indicativa, não uma conclusão estatística. Todos os cenários completaram sem erros;
foram 6.300 respostas HTTP de performance bem-sucedidas no HttpClient e pico de memória acumulado de
8–10 MB.

| Cenário | Antes RPS | Depois RPS | Δ RPS | Antes P95 (ms) | Depois P95 (ms) | Δ P95 |
|---|---:|---:|---:|---:|---:|---:|
| Serial pequeno | 1.460,52 | 1.449,01 | −0,8% | 0,7690 | 0,8100 | +5,3% |
| Batch pequeno | 1.941,89 | 2.428,96 | +25,1% | 14,6116 | 11,4506 | −21,6% |
| Latência 10 ms | 91,71 | 92,31 | +0,7% | 11,0926 | 10,9775 | −1,0% |
| Payload 100 KB | 1.222,69 | 1.292,17 | +5,7% | 0,9725 | 0,8688 | −10,7% |
| Endpoint lento 150 ms | 6,61 | 6,61 | 0,0% | 151,4904 | 151,4090 | −0,1% |
| Batch + latência | 767,66 | 483,83 | −37,0% | 32,9323 | 69,0427 | +109,7% |

O resultado confirma que o loop incluído passa os cenários funcionais e preserva ou melhora
cinco dos seis throughputs nesta rodada, mas também revela regressão importante em
“Batch + latência”. O ganho do batch pequeno não compensa esse caso de uso. A migração do
loop não deve ser divulgada como sem perda de performance até que esse cenário seja
investigado e medido em múltiplas execuções controladas.

### Investigação da regressão de concorrência

Em novas execuções sem Xdebug, o cenário “Batch + latência” ficou entre 480 e 485 req/s,
com P95 entre 69 e 70 ms, enquanto a execução pré-migração registrada na seção 10 obteve
768 req/s e P95 de 32,93 ms. O batch pequeno variou mais entre rodadas, mas não mostrou a
mesma queda consistente. Desativar Xdebug e OPcache/JIT não removeu a diferença.

Foram testadas duas hipóteses: agregar as esperas de `awaitAll()` em uma única Task e
reutilizar arrays de streams no `StreamSelectDriver`. Os resultados do lote com latência
permaneceram em aproximadamente 485 e 481 req/s, respectivamente, sem melhora mensurável;
as duas alterações experimentais foram revertidas. Isso aponta para custo/ordem de
agendamento do novo Loop, e não para reconstrução dos arrays ou múltiplas entradas em
`Loop::run()` como causa principal. A confirmação exige perfil por fase (início das Tasks,
connect, readiness e leitura) comparando os dois schedulers sob o mesmo servidor e a mesma
carga; até lá, esta regressão permanece aberta.
