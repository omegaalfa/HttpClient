# Omegaalfa HttpClient

Cliente HTTP assíncrono para PHP 8.4 baseado em Fibers e no `FiberEventLoop`.

O foco desta biblioteca é oferecer uma API fluente, previsível e extensível para I/O HTTP de alta performance sem depender de Amp, ReactPHP ou outro event loop externo.

## Visão geral

Esta biblioteca fornece:

- Requisições HTTP assíncronas com `Future`
- Configuração fluente e imutável no cliente
- JSON automático, query string, headers, cookies, auth, multipart e upload de arquivos
- Controle de timeout, retries, backoff e redirects
- Concorrência com `awaitAll()`, `awaitAny()`, `race()`, `awaitSettled()`, `parallel()` e `concurrent()`
- Proxy HTTP e túnel HTTPS via CONNECT
- Reuso básico de conexões com pool por origem, habilitado explicitamente com `withKeepAlive(true)`
- Transição de rede injetável (`TransportInterface`) e mock pronto (`MockTransport`) para testes sem rede

## Requisitos

- PHP >= 8.4
- Extensão `ext-sockets`
- Dependência `omegaalfa/fiber-event-loop`

## Instalação

```bash
composer require omegaalfa/http-client
```

> **Nota sobre estabilidade:** enquanto `omegaalfa/fiber-event-loop` estiver disponível apenas como
> `dev-main`, o projeto consumidor poderá precisar permitir dependências de desenvolvimento em sua configuração do Composer.

## Início rápido

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use function Omegaalfa\HttpClient\Http\await;

$client = new AsyncHttpClient();
$response = await($client->get('https://jsonplaceholder.typicode.com/todos/1'));

echo $response->status() . PHP_EOL;
print_r($response->json());
```

## Modelo mental

O pacote trabalha com estes blocos:

- `AsyncHttpClient`: interface pública principal
- `RequestOptions`: estado fluente e imutável da configuração
- `Request`: representação de uma requisição em execução
- `Response`: resposta materializada
- `Headers`: coleção case-insensitive multi-valor
- `CookieJar`: armazenamento e envio automático de cookies
- `MultipartBuilder`: construção de multipart/form-data
- `ConcurrentExecutor`: primitivas de concorrência
- `TransportInterface` + `SocketTransport`: fronteira de rede injetável (sockets TCP/TLS, proxy, redirects)
- `Testing\MockTransport`: transporte de teste para consumidores, sem rede
- `ConnectionPool`: reuso de sockets por origem
- `HttpException`, `TimeoutException`, `ConnectionException`: falhas do domínio

## Exemplo completo de uso

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\MultipartBuilder;
use function Omegaalfa\HttpClient\Http\await;

$client = (new AsyncHttpClient())
    ->withBaseUrl('https://jsonplaceholder.typicode.com')
    ->withUserAgent('MeuApp/1.0')
    ->withHeader('X-App-Env', 'production')
    ->withRetries(2)
    ->withRetryDelay(150)
    ->withFollowRedirects(5)
    ->withKeepAlive(true);

$response = await($client->get('/posts/1'));

echo $response->status() . PHP_EOL;

$multipart = MultipartBuilder::make()
    ->field('title', 'novo item')
    ->file('document', __DIR__ . '/README.md', 'README.md', 'text/markdown');

$upload = await($client->post(
    'https://httpbin.org/post',
    body: ['meta' => 'example'],
    multipart: $multipart
));

print_r($upload->json());
```

## API pública do AsyncHttpClient

### Métodos HTTP

```php
get(string $url, array $query = [], array $headers = []): Future
head(string $url, array $query = [], array $headers = []): Future
post(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
put(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
patch(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
delete(string $url, array $query = [], array $headers = []): Future
request(string $method, string $url, array $query = [], array $headers = [], mixed $body = null, array $files = [], ?MultipartBuilder $multipart = null): Future
streamGet(string $url, array $query = [], array $headers = []): Future
streamPost(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
streamPut(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
streamPatch(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
streamDelete(string $url, array $query = [], array $headers = []): Future
streamRequest(string $method, string $url, array $query = [], array $headers = [], mixed $body = null, array $files = [], ?MultipartBuilder $multipart = null): Future
streamSseGet(string $url, array $query = [], array $headers = [], bool $requireDone = false, ?callable $completionDetector = null): Future
streamSsePost(string $url, mixed $body = null, array $headers = [], bool $requireDone = false, ?callable $completionDetector = null): Future
streamSseRequest(string $method, string $url, array $query = [], array $headers = [], mixed $body = null, array $files = [], ?MultipartBuilder $multipart = null, bool $requireDone = false, ?callable $completionDetector = null): Future
```

> Requisições `HEAD` e respostas `204`, `205` e `304` nunca carregam corpo: o
> cliente retorna imediatamente com `body()` vazio (e stream completo), mesmo
> que o servidor envie `Content-Length` ou `Transfer-Encoding` descritivos.

### Concorrência

```php
concurrent(array $tasks): Future
parallel(array $futures): Future
awaitAny(array $futures): Future
race(array $futures): Future
settled(array $futures): Future
```

### Configuração fluente

```php
withBaseUrl(string $baseUrl)
withHeader(string $name, string $value)
withHeaders(array $headers)
withCookie(string $name, string $value, string $domain = '', string $path = '/')
withCookies(array $cookies, string $domain = '', string $path = '/')
withProxy(string $proxy)
withUserAgent(string $userAgent)
withJson(bool $enabled = true)
withCompression(bool $enabled = true)
withBearerToken(string $token)
withBasicAuth(string $username, string $password)
withRetries(int $retries)
withRetryDelay(int $milliseconds)
withExponentialBackoff(bool $enabled = true)
withFollowRedirects(int|bool $value)
withVerifySSL(bool $verify)
withKeepAlive(bool $enabled = true)
withTimeout(float $seconds)
connectTimeout(float $seconds)
readTimeout(float $seconds)
writeTimeout(float $seconds)
totalTimeout(float $seconds)
```

`withJson(false)` desativa apenas a inclusão automática dos headers JSON.
Bodies em array continuam sendo serializados como JSON, exceto quando o
`Content-Type` for `application/x-www-form-urlencoded`.

## Streaming real e SSE

Quando você quer começar a processar os dados antes de a resposta terminar, use
as novas APIs de streaming. O cliente continua aceitando as mesmas opções de
headers, auth, proxy, timeout e retry, mas a resposta volta como um objeto que
lê os bytes aos poucos.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use function Omegaalfa\HttpClient\Http\await;

$client = new AsyncHttpClient();
$stream = await($client->streamGet('https://example.com/stream'));

while (($chunk = $stream->readChunk()) !== null) {
        echo $chunk;
}
```

Para SSE, a biblioteca também faz o parsing linha por linha e entrega eventos já
estruturados:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use function Omegaalfa\HttpClient\Http\await;

$client = new AsyncHttpClient();
$sse = await($client->streamSseGet('https://example.com/events'));

while (($event = $sse->nextEvent()) !== null) {
        if ($event->done()) {
                break;
        }

        print_r($event->json());
}

// Em cancelamento antecipado, feche explicitamente para liberar o socket.
$sse = await($client->streamSsePost('https://example.com/events', body: ['prompt' => 'demo']));
try {
    foreach ($sse as $event) {
        echo $event->data() . PHP_EOL;
        break;
    }
} finally {
    $sse->close();
}
```

Alguns serviços de chat ou IA encerram a resposta com `data: [DONE]`. Para
reconhecer esse marcador e exigir que ele apareça antes do fim, passe
`requireDone: true` e `AsyncHttpClient::doneMarkerCompletionDetector()`. Por
padrão, `requireDone` é `false` e o fim normal do stream não lança erro. Se um
evento convertido com `json()` contiver JSON inválido, a exceção inclui um
pequeno trecho do conteúdo para ajudar no diagnóstico.

Resumo mental:

- `request()` e `get()` continuam sendo a escolha certa para respostas comuns
    e totalmente materializadas.
- `streamGet()` e `streamRequest()` são para ler bytes à medida que chegam.
- `streamSseGet()`, `streamSsePost()` e `streamSseRequest()` são para SSE e retornam eventos em vez
    de texto cru.

## Exemplos de uso por cenário

### 1. GET com query string

```php
$client = new AsyncHttpClient();

$response = await($client->get(
    'https://jsonplaceholder.typicode.com/todos',
    ['userId' => 1, 'completed' => false]
));

echo $response->status() . PHP_EOL;
```

### 2. Base URL + headers padrão

```php
$client = (new AsyncHttpClient())
    ->withBaseUrl('https://jsonplaceholder.typicode.com')
    ->withUserAgent('MeuSistema/2.3')
    ->withHeader('X-App-Env', 'production');

$response = await($client->get('/posts/1'));
```

### 3. POST JSON automático

Quando o body é um array e não há `Content-Type` especial, a biblioteca serializa para JSON.

```php
$client = new AsyncHttpClient();

$response = await($client->post('https://jsonplaceholder.typicode.com/posts', [
    'title' => 'Novo post',
    'body' => 'Conteudo do post',
    'userId' => 10,
]));

print_r($response->json());
```

### 4. PUT, PATCH e DELETE

```php
$client = new AsyncHttpClient();

$put = await($client->put('https://jsonplaceholder.typicode.com/posts/1', [
    'id' => 1,
    'title' => 'Titulo atualizado',
    'body' => 'Conteudo atualizado',
    'userId' => 1,
]));

$patch = await($client->patch('https://jsonplaceholder.typicode.com/posts/1', [
    'title' => 'Atualizacao parcial',
]));

$delete = await($client->delete('https://jsonplaceholder.typicode.com/posts/1'));

echo $put->status() . PHP_EOL;
echo $patch->status() . PHP_EOL;
echo $delete->status() . PHP_EOL;
```

### 5. Headers por requisição

```php
$client = new AsyncHttpClient();

$response = await($client->get(
    'https://httpbin.org/headers',
    headers: ['X-Trace-Id' => 'abc-123']
));
```

### 6. Cookies

```php
$client = (new AsyncHttpClient())
    ->withCookie('session_id', 'abc123', 'httpbin.org');

$response = await($client->get('https://httpbin.org/cookies'));
print_r($response->json());
```

O envio de cookies respeita o domínio em boundary de ponto (cookie de `evil.com`
não vai para `notevil.com`), o caminho, a expiração e a flag `Secure`. Cookies
recebidos sem `Domain` ficam limitados ao host de origem. O atributo `Domain`
precisa corresponder ao host de origem ou ser um domínio pai válido.

### 7. Bearer token

```php
$client = (new AsyncHttpClient())
    ->withBearerToken('seu-token-aqui');

$response = await($client->get('https://httpbin.org/bearer'));
echo $response->status() . PHP_EOL;
```

### 8. Basic auth

```php
$client = (new AsyncHttpClient())
    ->withBasicAuth('usuario', 'senha');

$response = await($client->get('https://httpbin.org/basic-auth/usuario/senha'));
echo $response->status() . PHP_EOL;
```

### 9. Multipart com `MultipartBuilder`

```php
use Omegaalfa\HttpClient\Http\MultipartBuilder;

$client = new AsyncHttpClient();

$multipart = MultipartBuilder::make()
    ->field('description', 'arquivo de teste')
    ->file('document', __DIR__ . '/README.md', 'README.md', 'text/markdown');

$response = await($client->post(
    'https://httpbin.org/post',
    body: ['origin' => 'omegaalfa-http-client'],
    multipart: $multipart
));

echo $response->status() . PHP_EOL;
```

Uploads multipart são construídos por partes em um buffer `php://temp`
(memória até 2MB, resto em disco) e enviados por segmentos direto no socket —
arquivos grandes não são materializados em memória pelo cliente.

### 10. Upload com `files`

```php
$client = new AsyncHttpClient();

$response = await($client->post(
    'https://httpbin.org/post',
    body: ['type' => 'avatar'],
    files: ['file' => __DIR__ . '/avatar.png']
));
```

### 11. `awaitAll()`

```php
use function Omegaalfa\HttpClient\Http\awaitAll;

$client = new AsyncHttpClient();

$futures = [
    'todo-1' => $client->get('https://jsonplaceholder.typicode.com/todos/1'),
    'todo-2' => $client->get('https://jsonplaceholder.typicode.com/todos/2'),
    'todo-3' => $client->get('https://jsonplaceholder.typicode.com/todos/3'),
];

$responses = awaitAll($futures);

foreach ($responses as $key => $response) {
    echo $key . ': ' . $response->status() . PHP_EOL;
}
```

### 12. `awaitAny()` e `race()`

```php
use function Omegaalfa\HttpClient\Http\awaitAny;
use function Omegaalfa\HttpClient\Http\race;

$client = new AsyncHttpClient();

$winner = awaitAny([
    'fast' => $client->get('https://jsonplaceholder.typicode.com/todos/1'),
    'slow' => $client->get('https://jsonplaceholder.typicode.com/todos/2'),
]);

echo $winner['key'] . PHP_EOL;
echo $winner['value']->status() . PHP_EOL;

$firstValue = race([
    $client->get('https://jsonplaceholder.typicode.com/todos/3'),
    $client->get('https://jsonplaceholder.typicode.com/todos/4'),
]);

echo $firstValue->status() . PHP_EOL;
```

O vencedor é sempre o primeiro futuro a assentar de fato — nunca o primeiro da
lista —, mesmo que os demais levem mais tempo. Se o primeiro a assentar tiver
rejeitado, a exceção é propagada (use `settled()` para coletar todos os
resultados sem lançar).

### 13. `awaitSettled()`

```php
use function Omegaalfa\HttpClient\Http\awaitSettled;

$results = awaitSettled([
    'ok' => $client->get('https://jsonplaceholder.typicode.com/todos/1'),
    'fail' => $client->get('http://invalid-host.invalid'),
]);

foreach ($results as $key => $result) {
    if ($result['state'] === 'fulfilled') {
        echo $key . ' => ' . $result['value']->status() . PHP_EOL;
        continue;
    }

    echo $key . ' => ' . $result['reason']->getMessage() . PHP_EOL;
}
```

### 14. Concorrência nomeada com `concurrent()`

```php
$client = new AsyncHttpClient();

$future = $client->concurrent([
    'user' => fn() => $client->get('https://jsonplaceholder.typicode.com/users/1'),
    'posts' => fn() => $client->get('https://jsonplaceholder.typicode.com/posts', ['userId' => 1]),
    'todos' => fn() => $client->get('https://jsonplaceholder.typicode.com/todos', ['userId' => 1]),
]);

$results = await($future);

echo $results['user']->status() . PHP_EOL;
```

### 15. Paralelismo com `parallel()`

```php
$client = new AsyncHttpClient();

$parallel = $client->parallel([
    'one' => $client->get('https://jsonplaceholder.typicode.com/todos/1'),
    'two' => $client->get('https://jsonplaceholder.typicode.com/todos/2'),
]);

$responses = await($parallel);
```

### 16. Timeouts

```php
$client = (new AsyncHttpClient())
    ->connectTimeout(2.0)
    ->readTimeout(5.0)
    ->writeTimeout(5.0)
    ->totalTimeout(10.0);

$response = await($client->get('https://jsonplaceholder.typicode.com/todos/1'));
```

`withRetries(3)` permite até três novas tentativas depois da chamada inicial,
totalizando no máximo quatro chamadas. O cliente só repete GET, HEAD, OPTIONS,
PUT e DELETE após falhas de conexão, timeout ou respostas 5xx. POST e PATCH não
são repetidos automaticamente.

### 17. Retry e backoff exponencial

```php
$client = (new AsyncHttpClient())
    ->withRetries(3)
    ->withRetryDelay(150)
    ->withExponentialBackoff(true);

$response = await($client->get('https://jsonplaceholder.typicode.com/todos/1'));
```

### 18. Redirect automático

```php
$client = (new AsyncHttpClient())
    ->withFollowRedirects(5); // true usa o padrão 3

$response = await($client->get('http://httpbin.org/redirect/1'));

echo $response->status() . PHP_EOL;
echo $response->redirected() ? 'teve redirect' : 'sem redirect';
```

Redirects entre origens removem `Authorization`, `Proxy-Authorization` e
`Cookie`, além das credenciais bearer/basic configuradas. Use
`withSensitiveHeaders()` para marcar headers próprios que também precisam ser
removidos. Redirects de HTTPS para HTTP são recusados por padrão;
`withAllowInsecureRedirects()` permite esse downgrade explicitamente.

### 19. Salvar resposta em arquivo

```php
$client = new AsyncHttpClient();

$response = await($client->get('https://jsonplaceholder.typicode.com/posts/1'));
$response->save(__DIR__ . '/tmp/post-1.json');
```

### 20. Trabalhando com Response

```php
$response = await($client->get('https://jsonplaceholder.typicode.com/todos/1'));

if ($response->successful()) {
    echo $response->text();
}

$headers = $response->headers()->toArray();
$cookies = $response->cookies();
```

### 21. Form URL Encoded

```php
$response = await($client->post(
    'https://httpbin.org/post',
    body: ['grant_type' => 'client_credentials', 'scope' => 'read'],
    headers: ['Content-Type' => 'application/x-www-form-urlencoded']
));
```

### 22. Proxy HTTP

```php
$client = (new AsyncHttpClient())
    ->withProxy('127.0.0.1:8080');

$response = await($client->get('http://example.com'));
```

### 23. Proxy HTTPS via CONNECT

```php
$client = (new AsyncHttpClient())
    ->withProxy('http://127.0.0.1:8080')
    ->withVerifySSL(true);

$response = await($client->get('https://example.com'));
```

> **Segurança:** mantenha `withVerifySSL(true)` em produção. Usar
> `withVerifySSL(false)` desativa a validação do certificado e do hostname e
> deve ser restrito a ambientes locais controlados.

### 24. Compressão gzip

```php
$client = (new AsyncHttpClient())
    ->withCompression(); // envia Accept-Encoding: gzip

$response = await($client->get('https://httpbin.org/gzip'));

print_r($response->json()); // corpo já decodificado
```

Respostas com `Content-Encoding: gzip` são decodificadas automaticamente no
caminho materializado (`Response`), mesmo sem `withCompression()` — a opção
controla apenas a negociação via `Accept-Encoding`. Codificações não suportadas
lançam `ConnectionException`. O caminho de streaming (`streamGet()` etc.)
entrega os bytes como chegam, sem decodificação de `Content-Encoding`.

### 25. Testes sem rede com `MockTransport`

O transporte de rede é injetável via `TransportInterface`. Para testar código
consumidor sem rede, use o mock pronto da biblioteca:

```php
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Headers;
use Omegaalfa\HttpClient\Http\Response;
use Omegaalfa\HttpClient\Http\Testing\MockTransport;
use function Omegaalfa\HttpClient\Http\await;

$transport = new MockTransport([
    // Fila de respostas: Response, StreamResponse, Throwable ou callable.
    new Response(200, new Headers(['Content-Type' => 'application/json']), '{"ok":true}'),
]);

$client = new AsyncHttpClient(transport: $transport);

// O código sob teste usa o cliente normalmente — sem rede.
$response = await($client->get('https://api.example.com/status'));
assert($response->json() === ['ok' => true]);

// As requisições recebidas ficam registradas para asserções.
assert($transport->lastRequest()?->method === 'GET');
```

Para rotas de streaming, monte a resposta com `MockTransport::stream()`:

```php
$transport = new MockTransport([
    MockTransport::stream('dados em chunks', 200, ['Content-Type' => 'text/plain']),
]);

$stream = await((new AsyncHttpClient(transport: $transport))->streamGet('https://api.example.com/s'));
assert($stream->consume() === 'dados em chunks');
```

Também é possível injetar sua própria implementação de `TransportInterface`
(ex.: gravando em sistema de arquivos, ou fakes específicos de domínio).

## Referência da API

Os exemplos usam `await()` para obter o resultado de um `Future`. Isso executa o
event loop até a operação terminar. Você pode iniciar várias requisições antes
de aguardar seus resultados para que elas avancem em conjunto.

### AsyncHttpClient

Normalmente, crie o cliente sem argumentos: `new AsyncHttpClient()`. O
construtor também aceita `loop`, `options`, `connectionPool` e `transport` para
integrar um event loop, opções, pool ou transporte próprios. Na maioria dos
aplicativos, as opções fluentes são suficientes; `transport` é útil para testes
e integrações especiais.

#### Requisições com resposta completa

Todos os métodos abaixo devolvem `Future<Response>`. `Response` contém o corpo
completo em memória. Status como 404 e 500 são devolvidos normalmente; eles não
lançam exceções automaticamente.

- `get($url, $query = [], $headers = [])`: envia GET; `$query` acrescenta
  parâmetros à URL.
- `head($url, $query = [], $headers = [])`: envia HEAD; a resposta não tem corpo.
- `post($url, $body = null, $headers = [], $files = [], $multipart = null)`: envia
  dados para criar um recurso. Use `$files` ou `MultipartBuilder` para arquivos.
- `put(...)`: envia ou substitui um recurso; aceita os argumentos de `post()`.
- `patch(...)`: envia alterações parciais; aceita os argumentos de `post()`.
- `delete($url, $query = [], $headers = [])`: solicita a remoção de um recurso.
- `request($method, $url, $query = [], $headers = [], $body = null, $files = [], $multipart = null)`:
  método genérico para qualquer verbo HTTP, inclusive OPTIONS.

Corpos em array são JSON por padrão. Para enviar um formulário URL encoded,
defina `Content-Type: application/x-www-form-urlencoded`.

#### Respostas em streaming

`streamGet`, `streamPost`, `streamPut`, `streamPatch`, `streamDelete` e
`streamRequest` aceitam os mesmos tipos de argumentos dos métodos completos
correspondentes, mas devolvem `Future<StreamResponse>`. Use `readChunk()` para
receber partes do corpo ou `consume()` para juntá-las em uma string. Feche a
resposta se parar de ler antes do fim. `consume()` guarda o corpo todo em memória;
streaming não descomprime automaticamente `Content-Encoding: gzip`.

- `streamGet($url, $query = [], $headers = [])`: GET lido aos poucos.
- `streamPost($url, $body = null, $headers = [], $files = [], $multipart = null)`:
  POST com resposta lida aos poucos.
- `streamPut(...)` e `streamPatch(...)`: versões em streaming de PUT e PATCH.
- `streamDelete($url, $query = [], $headers = [])`: versão em streaming de DELETE.
- `streamRequest($method, $url, $query = [], $headers = [], $body = null, $files = [], $multipart = null)`:
  versão genérica em streaming.

#### Server-Sent Events (SSE)

- `streamSseGet($url, $query = [], $headers = [], $requireDone = false, $completionDetector = null)`:
  abre um fluxo SSE via GET.
- `streamSsePost($url, $body = null, $headers = [], $requireDone = false, $completionDetector = null)`:
  abre um fluxo SSE via POST.
- `streamSseRequest($method, $url, $query = [], $headers = [], $body = null, $files = [], $multipart = null, $requireDone = false, $completionDetector = null)`:
  versão genérica para SSE.
- `doneMarkerCompletionDetector($marker = '[DONE]')`: cria um detector para
  reconhecer um evento cuja mensagem seja igual ao marcador.

Por padrão, o fim do stream é aceito sem um evento especial. Para exigir um
marcador, use `requireDone: true` e forneça um `completionDetector`. Leia os
eventos com `nextEvent()` ou com `foreach`. Cada `SseEvent` oferece `data()`,
`event()`, `id()`, `retry()`, `done()` e `json()`. Feche o stream se sair antes
do fim.

```php
$sse = await($client->streamSseGet(
    'https://example.com/events',
    requireDone: true,
    completionDetector: AsyncHttpClient::doneMarkerCompletionDetector(),
));

try {
    foreach ($sse as $event) {
        echo $event->data() . PHP_EOL;
        if ($event->done()) {
            break;
        }
    }
} finally {
    $sse->close();
}
```

#### Concorrência

- `concurrent($tasks)`: agenda callbacks identificados por chave e devolve os
  resultados com as mesmas chaves.
- `parallel($futures)`: aguarda todos os `Future` fornecidos e preserva as chaves.
- `awaitAny($futures)`: devolve `['key' => ..., 'value' => ...]` do primeiro
  futuro concluído. Os outros futuros não são cancelados automaticamente.
- `race($futures)`: como `awaitAny()`, mas devolve somente o valor vencedor.
- `settled($futures)`: aguarda todos e devolve, por chave, `state` como
  `fulfilled` ou `rejected`, além de `value` ou `reason`.

Os helpers `awaitAll()`, `awaitAny()`, `awaitSettled()` e `race()` fazem essas
operações sem chamar os métodos de concorrência do cliente.

#### Configuração fluente

Cada método devolve um novo `AsyncHttpClient`, permitindo encadear opções sem
alterar a configuração do objeto original. Tempos são informados em segundos;
delay de retry é informado em milissegundos.

- `withBaseUrl($url)`: URI base para resolver referências conforme RFC 3986. Caminhos
  iniciados por `/` são relativos à raiz do host; use `withBaseUrl('https://api.example/v1/')`
  com `get('users')` para manter o caminho `/v1/`. Referências como `../users`, `?page=2`
  e `#section` seguem as regras de resolução URI.
- `withHeader($name, $value)` / `withHeaders($headers)`: headers padrão; headers
  da chamada podem substituir os padrões com o mesmo nome.
- `withCookie($name, $value, $domain = '', $path = '/')` /
  `withCookies($cookies, $domain = '', $path = '/')`: cookies manuais. Sem domínio,
  o cookie fica associado ao primeiro host que usar esse cliente.
- `withProxy($proxy)`: endereço de proxy HTTP; HTTPS usa túnel CONNECT.
- `withUserAgent($value)`: define `User-Agent`.
- `withJson($enabled = true)`: controla os headers JSON automáticos. Arrays de
  corpo ainda viram JSON por padrão, exceto para URL encoded.
- `withCompression($enabled = true)`: anuncia suporte a gzip. Respostas completas
  gzip são descomprimidas; streaming entrega os bytes codificados.
- `withBearerToken($token)` / `withBasicAuth($user, $password)`: configura
  autenticação `Authorization`.
- `withRetries($count)`: número de tentativas adicionais. Só repete GET, HEAD,
  OPTIONS, PUT e DELETE em falhas de conexão, timeout ou resposta 5xx.
- `withRetryDelay($milliseconds)`: espera inicial entre tentativas.
- `withExponentialBackoff($enabled = true)`: dobra a espera a cada tentativa.
- `withFollowRedirects($countOrEnabled)`: limite de redirecionamentos; `false`
  desativa, `true` usa o padrão 3 e um inteiro define o limite.
- `withVerifySSL($verify)`: controla validação do certificado e do nome do
  servidor; mantenha ativo em produção.
- `withKeepAlive($enabled = true)`: permite reutilizar conexões ociosas.
- `withTimeout($seconds)`: atalho para configurar o tempo total.
- `connectTimeout($seconds)`, `readTimeout($seconds)`, `writeTimeout($seconds)`:
  limites de conexão, leitura e escrita.
- `totalTimeout($seconds)`: limite monotônico da operação inteira, incluindo
  redirects, retries e esperas entre tentativas.

Valores padrão: sem retries; espera de 100 ms entre tentativas; backoff
exponencial desligado; até 3 redirecionamentos; validação TLS ligada; keep-alive
e compressão desligados; timeout de conexão de 10 s, leitura de 30 s, escrita
de 30 s e total de 60 s.

### Segurança em redirects e timeout

Opções de segurança e timeout:

- `withAllowInsecureRedirects($allow = true)` permite explicitamente redirects
  de HTTPS para HTTP. Fica desativado por padrão.
- `withSensitiveHeaders($names)` indica headers próprios a remover em redirects
  entre origens. `Authorization`, `Proxy-Authorization` e `Cookie` são removidos
  automaticamente.
- `totalTimeout($seconds)` limita a operação inteira, incluindo redirects,
  retries e esperas entre tentativas.

### Response

- `status()`: código HTTP, por exemplo 200 ou 404.
- `headers()`: cópia dos headers da resposta.
- `body()` / `text()`: corpo completo como string.
- `json($assoc = true)`: decodifica JSON; com `true`, objetos viram arrays.
  JSON inválido lança `JsonException`.
- `successful()` / `failed()`: informa se o status está ou não entre 200 e 299.
- `redirected()`: informa se a resposta veio após um redirecionamento.
- `cookies()`: cookies extraídos dos headers.
- `save($path)`: grava o corpo no arquivo indicado; falhas lançam `RuntimeException`.

### StreamResponse e SseStream

`StreamResponse` oferece `status()`, `headers()`, `redirected()`, `cookies()`,
`isComplete()`, `readChunk($length = 65536)`, `consume()`, `close()` e `socket()`.
`readChunk()` devolve uma string ou `null` no fim; `$length` precisa ser maior
que zero. A conexão só pode ser reutilizada se a resposta for lida até o fim.

`SseStream` oferece `nextEvent()`, `close()`, `stream()` e iteração com `foreach`.
`SseEvent` oferece `data()`, `event()`, `id()`, `retry()`, `done()` e `json()`.
`done()` só será verdadeiro quando o detector configurado reconhecer um evento
de conclusão.

### Headers

Coleção case-insensitive: nomes não diferenciam maiúsculas de minúsculas e um
header pode ter vários valores. Nomes seguem a gramática de token HTTP; valores
rejeitam caracteres de controle, exceto tab horizontal.

- `new Headers($headers)` / `Headers::from($headers)`: cria uma coleção.
- `set($name, $value)`: substitui os valores do header.
- `add($name, $value)`: acrescenta um valor.
- `has($name)`, `get($name, $default = null)`, `values($name)`: consulta presença,
  valores combinados ou valores individuais.
- `remove($name)`: apaga um header.
- `merge($headers)`: devolve uma cópia com os headers informados aplicados.
- `toArray()`, `toLines()`: converte para array ou linhas HTTP.
- `fromRaw($text)`: interpreta um bloco de headers recebido da rede.

### MultipartBuilder

- `MultipartBuilder::make()`: inicia um formulário multipart.
- `field($name, $value)`: adiciona um campo simples.
- `file($name, $path, $filename = null, $contentType = null)`: adiciona um arquivo.
- `build()`: monta todo o conteúdo em memória.
- `buildToStream()`: prepara stream temporário, tipo de conteúdo e tamanho para
  o transporte enviar por blocos. Os arquivos são copiados primeiro para esse
  stream temporário, que usa memória até 2 MiB e depois disco; não é streaming
  direto do arquivo de origem para o socket.

### Transporte e testes

`TransportInterface` define `send()` e `sendStream()`. Injete uma implementação
no construtor de `AsyncHttpClient` para substituir a rede. `SocketTransport` é o
transporte padrão.

`Request` representa uma chamada já preparada e expõe `method`, `url`, `headers`,
`body`, `query`, `files`, `multipart` e `options`. `withUrl($url)` cria uma nova
requisição com outra URL; esses tipos são mais úteis ao implementar um transporte
ou inspecionar chamadas em testes do que no uso normal do cliente.

`Testing\\MockTransport` permite testar sem rede. Seu construtor recebe uma fila
de `Response`, `StreamResponse`, `Throwable` ou callbacks com `Request` e
`RequestOptions`. `push($result)` acrescenta um resultado. `requests()`,
`lastRequest()` e `pendingCount()` ajudam a inspecionar as chamadas.
`MockTransport::stream($body, $status = 200, $headers = [])` cria uma resposta
de streaming para testes.

`ConnectionPool` gerencia conexões ociosas. `acquire()` retira uma conexão
reutilizável e `release($socket, $keepAlive = false)` devolve ou fecha o socket;
normalmente o cliente faz isso automaticamente.

### Cookies e erros

`CookieJar` oferece `set()` e `setMany()` para guardar cookies, `headerFor()`
para obter os cookies que combinam com host, caminho e HTTPS, `storeFromHeaders()`
para guardar os cookies de uma resposta e `all()` para inspecionar o conteúdo.
Na maioria dos casos, use `withCookie()` ou `withCookies()` no cliente.

`HttpException` fornece `request()` e `response()` quando esses objetos estão
disponíveis. `ConnectionException` representa falhas de conexão ou protocolo;
`TimeoutException` representa o vencimento de um limite de tempo. Erros HTTP
4xx e 5xx continuam sendo respostas normais, a menos que uma política de retry
esteja avaliando uma resposta 5xx.

## Tratamento de erros

```php
use Omegaalfa\HttpClient\Http\AsyncHttpClient;
use Omegaalfa\HttpClient\Http\Exceptions\ConnectionException;
use Omegaalfa\HttpClient\Http\Exceptions\TimeoutException;
use function Omegaalfa\HttpClient\Http\await;

$client = (new AsyncHttpClient())->totalTimeout(3.0);

try {
    $response = await($client->get('https://jsonplaceholder.typicode.com/todos/1'));
    echo $response->status() . PHP_EOL;
} catch (TimeoutException $e) {
    echo 'Timeout: ' . $e->getMessage() . PHP_EOL;
} catch (ConnectionException $e) {
    echo 'Conexao: ' . $e->getMessage() . PHP_EOL;
} catch (\Throwable $e) {
    echo 'Erro inesperado: ' . $e->getMessage() . PHP_EOL;
}
```

## Comportamentos importantes

- Body em array vira JSON por padrão, mesmo com `withJson(false)`; essa opção controla a inclusão automática dos headers JSON.
- Para enviar `application/x-www-form-urlencoded`, defina o header explicitamente; parâmetros como `charset=UTF-8` são aceitos.
- Clientes derivados com `with*()` recebem uma cópia independente do `CookieJar`; cookies recebidos por cada cliente são filtrados por domínio, path, expiração e flag `Secure`.
- Cookies com `Domain` igual a qualquer sufixo da Public Suffix List são rejeitados, incluindo regras privadas (por exemplo `github.io`) e curingas. A lista distribuída é atualizada a partir de `publicsuffix.org`; veja `resources/public_suffix_list.dat` para a versão incluída.
- Redirects 301, 302 e 303 convertem métodos diferentes de GET/HEAD para GET; parâmetros passados separadamente em `query` não são reaplicados ao destino. Ao mudar de origem, credenciais são removidas.
- Respostas com mais de 64 KiB de headers ou mais de 100 linhas são rejeitadas. Linhas SSE são limitadas a 64 KiB; eventos SSE incompletos no EOF são descartados conforme o protocolo.
- `withRetries(N)` configura até `N` novas tentativas além da chamada inicial.
- Os timeouts devem ser números finitos maiores que zero; zero, `INF` e `NAN` lançam `InvalidArgumentException`.
- Retries por timeout, falha de conexão ou resposta 5xx ocorrem apenas para métodos idempotentes: GET, HEAD, OPTIONS, PUT e DELETE. POST e PATCH não são repetidos automaticamente.
- `withProxy()` suporta HTTP direto e CONNECT para HTTPS.
- O TCP e o handshake TLS usam streams não bloqueantes; o event loop suspende a Fiber até a prontidão do socket.
- O keep-alive é desabilitado por padrão; use `withKeepAlive(true)` para habilitar o pool.
- Conexões recusadas/inacessíveis são reportadas como `Connection failed: ...`, não como falha de escrita.

## Limitações atuais

- O framing das respostas HTTP é validado estritamente: valores conflitantes
  ou malformados de `Content-Length`, a presença simultânea de
  `Content-Length` e `Transfer-Encoding` e codificações diferentes de
  `chunked` são rejeitados.
- Respostas informativas (100–199) são ignoradas até a resposta final;
  upgrade de protocolo (`101`) não é suportado.
- `send()` materializa a resposta completa em memória e não tem limite de body;
  use `streamGet()`/`readChunk()` para controlar a leitura de respostas grandes.
- O cliente não bloqueia destinos privados nem implementa política anti-SSRF.
  Aplicações que buscam URLs fornecidas por usuários devem validar o destino e
  cada redirect na camada da aplicação.

- O pool é básico e ainda não possui TTL, health-check ativo ou métricas por origem.
- O handshake TLS é multiplexado depois que o nome do servidor é resolvido; a consulta DNS em si não é assíncrona e pode bloquear durante uma consulta lenta.
- A lista de sufixos públicos é um snapshot distribuído com a biblioteca e não se atualiza sozinha. Atualize `resources/public_suffix_list.dat` em releases periódicos; em ambientes multi-tenant, também isole os cookie jars. Cookies manuais sem domínio ficam associados ao primeiro host que os utiliza.
- O corpo puro em stream (arquivo sem multipart) não é suportado; uploads multipart usam buffer com spill em disco.
- Não há HTTP/2, HTTP/3 ou WebSocket nativos ainda.

## Testes

```bash
composer test
```

O arquivo `resources/public_suffix_list.dat` é publicado sob a Mozilla Public License 2.0;
consulte o cabeçalho do arquivo e https://mozilla.org/MPL/2.0/.

Para testar código consumidor sem rede, injete `Testing\MockTransport` no
`AsyncHttpClient` (ver seção 25).

Outros scripts:

```bash
composer test-coverage
composer test-verbose
composer test-filter -- NomeDoTeste
composer test-stop-on-failure
```

## Qualidade (mutation testing)

```bash
composer quality:mutation
```

Executa o [Infection](https://infection.github.io/): gera mutantes do código em
`src/`, roda para cada um apenas os testes que o cobrem e **falha se o MSI**
(Mutation Score Indicator) ficar abaixo de `60%` (`--min-msi=60`). É o quality
gate da suíte — testes que não matam mutantes aparecem no relatório para
reforço.

- Configuração: `infection.json5` (fontes, mutadores padrão, timeout de 10s)
- Relatório: `build/infection.log` (com breakdown por mutador)
- Requer driver de cobertura (`pcov` ou `xdebug`)

O CI (`.github/workflows/ci.yaml`) roda o mesmo gate (`composer quality:mutation`)
em cada push/PR para `main`, publicando o relatório do Infection como artefato
da execução.

## Licença

MIT
