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
post(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
put(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
patch(string $url, mixed $body = null, array $headers = [], array $files = [], ?MultipartBuilder $multipart = null): Future
delete(string $url, array $query = [], array $headers = []): Future
request(string $method, string $url, array $query = [], array $headers = [], mixed $body = null, array $files = [], ?MultipartBuilder $multipart = null): Future
```

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
totalizando no máximo quatro chamadas. Falhas de conexão, timeouts e respostas
5xx também podem repetir métodos não idempotentes, como `POST`; habilite retries
nesses casos somente quando a operação puder ser executada novamente com segurança.

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

## Referência rápida por classe

### AsyncHttpClient

#### Requisições

- `get()`
- `post()`
- `put()`
- `patch()`
- `delete()`
- `request()`

#### Concorrência

- `concurrent()`
- `parallel()`
- `awaitAny()`
- `race()`
- `settled()`

#### Configuração

- `withBaseUrl()`
- `withHeader()`
- `withHeaders()`
- `withCookie()`
- `withCookies()`
- `withProxy()`
- `withUserAgent()`
- `withJson()`
- `withBearerToken()`
- `withBasicAuth()`
- `withRetries()`
- `withRetryDelay()`
- `withExponentialBackoff()`
- `withFollowRedirects()`
- `withVerifySSL()`
- `withKeepAlive()`
- `withTimeout()`
- `connectTimeout()`
- `readTimeout()`
- `writeTimeout()`
- `totalTimeout()`

### Response

- `status()`
- `headers()`
- `body()`
- `text()`
- `json()`
- `successful()`
- `failed()`
- `redirected()`
- `cookies()`
- `save()`

### Headers

- `set()`
- `add()`
- `has()`
- `get()`
- `values()`
- `remove()`
- `merge()`
- `toArray()`
- `toLines()`
- `from()`
- `fromRaw()`

### CookieJar

- `set()`
- `setMany()`
- `storeFromHeaders()`
- `headerFor()`
- `all()`

### MultipartBuilder

- `make()`
- `field()`
- `file()`
- `build()`

### ConcurrentExecutor

- `all()`
- `named()`
- `any()`
- `race()`
- `settled()`

### ConnectionPool

- `acquire()`
- `release()`

### Funções auxiliares

- `await()`
- `awaitAll()`
- `awaitAny()`
- `race()`
- `awaitSettled()`

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
- Para enviar `application/x-www-form-urlencoded`, defina o header explicitamente.
- Cookies recebidos por `Set-Cookie` são persistidos no `CookieJar` e filtrados por domínio, path, expiração e flag `Secure`.
- Redirects 301, 302 e 303 podem converter o método para GET, seguindo o comportamento implementado.
- `withRetries(N)` configura até `N` novas tentativas além da chamada inicial.
- Retries por timeout, falha de conexão ou resposta 5xx podem repetir inclusive métodos não idempotentes.
- `withProxy()` suporta HTTP direto e CONNECT para HTTPS.
- O keep-alive é desabilitado por padrão; use `withKeepAlive(true)` para habilitar o pool.

## Limitações atuais

- O pool é básico e ainda não possui TTL, health-check ativo ou métricas por origem.
- O `CookieJar` usa correspondência simples por sufixo de domínio e ainda não distingue cookies host-only.
- Retries não implementam uma política automática de segurança/idempotência por método.
- Não há streaming de resposta/upload para zero-copy em payloads grandes.
- Não há HTTP/2, HTTP/3, WebSocket ou SSE nativos ainda.

## Testes

```bash
composer test
```

Outros scripts:

```bash
composer test-coverage
composer test-verbose
composer test-filter -- NomeDoTeste
composer test-stop-on-failure
```

## Licença

MIT
