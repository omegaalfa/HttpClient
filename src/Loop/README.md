# Omegaalfa Loop

Um event loop cooperativo para PHP. Ele executa várias tarefas no mesmo
processo usando Fibers e suspende cada tarefa quando ela espera por um timer,
por outra tarefa ou pela prontidão de um stream.

O objetivo é permitir que operações que passam parte do tempo esperando
compartilhem o processo sem criar uma thread por operação. O Loop não executa
PHP em paralelo: enquanto uma tarefa roda código PHP normal, as outras esperam
até que ela termine ou chegue a um ponto de suspensão.

> **Importante:** a biblioteca não transforma uma função bloqueante em
> assíncrona. `sleep()`, consultas síncronas, `file_get_contents()` ou qualquer
> função que bloqueie o processo também param o event loop durante sua execução.

## Índice

- [Instalação](#instalação)
- [Primeira execução](#primeira-execução)
- [Como pensar em tarefas e Fibers](#como-pensar-em-tarefas-e-fibers)
- [Criar tarefas e coletar resultados](#criar-tarefas-e-coletar-resultados)
- [Suspender e retomar tarefas](#suspender-e-retomar-tarefas)
- [Timers](#timers)
- [Esperar por I/O](#esperar-por-io)
- [Timeouts de I/O](#timeouts-de-io)
- [Limitar concorrência com Semaphore](#limitar-concorrência-com-semaphore)
- [Backpressure com BoundedChannel](#backpressure-com-boundedchannel)
- [Erros e falhas não aguardadas](#erros-e-falhas-não-aguardadas)
- [Cancelar uma tarefa](#cancelar-uma-tarefa)
- [Parar e encerrar o Loop](#parar-e-encerrar-o-loop)
- [Estados](#estados)
- [Callbacks e fairness](#callbacks-e-fairness)
- [Streams, drivers e portabilidade](#streams-drivers-e-portabilidade)
- [O que o Loop não faz](#o-que-o-loop-não-faz)
- [Problemas comuns](#problemas-comuns)
- [API de referência](#api-de-referência)
- [Testes e benchmarks](#testes-e-benchmarks)
- [Documentação complementar](#documentação-complementar)

## Instalação

O `HttpClient` requer PHP 8.4 ou superior. O Loop vem incluído no cliente e não
exige uma dependência Composer adicional de runtime.

This implementation is included in `omegaalfa/http-client`; installing that
package provides `Omegaalfa\HttpClient\Loop` without an additional loop package.

Em um checkout do repositório:

```sh
composer install
```

Todos os exemplos abaixo começam com o autoloader do Composer:

```php
<?php

require __DIR__ . '/vendor/autoload.php';
```

As funções auxiliares estão no namespace `Omegaalfa\HttpClient\Loop`. As classes públicas
principais são `Loop`, `Task`, `Semaphore` e `BoundedChannel`, também nesse
namespace.

## Primeira execução

Este programa cria duas tarefas. Cada uma espera por um timer assíncrono. A
espera de uma tarefa permite que a outra avance:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use function Omegaalfa\HttpClient\Loop\async;
use function Omegaalfa\HttpClient\Loop\awaitAll;
use function Omegaalfa\HttpClient\Loop\delay;
use function Omegaalfa\HttpClient\Loop\run;

$result = run(static function (): array {
    $first = async(static function (): string {
        delay(0.05);
        return 'primeira';
    });

    $second = async(static function (): string {
        delay(0.02);
        return 'segunda';
    });

    return awaitAll($first, $second);
});

var_export($result);
```

`run()` cria uma tarefa principal e aguarda seu resultado. Quando essa tarefa
aguarda outras tarefas, suas Fibers são suspensas e o Loop pode executar as
demais.

## Como pensar em tarefas e Fibers

Uma **Task** representa uma unidade de trabalho. Ao criar uma Task, ela é
colocada na fila do Loop. Ela não começa a executar dentro do construtor. O
Loop começa a tarefa em um ciclo posterior.

Uma **Fiber** é o recurso do PHP que permite suspender uma pilha de chamadas e
retomá-la depois no mesmo processo. Você normalmente não precisa criar ou
retomar Fibers diretamente: a classe `Task` e as funções auxiliares fazem isso.

Uma tarefa pode, por exemplo:

1. executar código PHP;
2. chamar `delay()`, `pause()`, `await()` ou uma espera de I/O;
3. suspender sua Fiber;
4. deixar o Loop executar outra tarefa;
5. continuar da linha seguinte quando a espera terminar.

Esse modelo é cooperativo. Se uma tarefa entrar em um laço infinito ou fizer
uma operação síncrona demorada sem suspender, o Loop não pode interrompê-la.

## Criar tarefas e coletar resultados

### `async()`

`async(callable $fn): Task` cria e agenda uma tarefa:

```php
use function Omegaalfa\HttpClient\Loop\async;
use function Omegaalfa\HttpClient\Loop\delay;

$task = async(static function (): int {
    delay(0.01);
    return 42;
});
```

O valor retornado pelo callback torna-se o resultado da tarefa.

Também é possível instanciar a classe:

```php
use Omegaalfa\HttpClient\Loop\Task;

$task = new Task(static fn(): string => 'pronto');
```

### `await()`

`await(Task $task): mixed` espera a tarefa terminar e devolve seu resultado.
Se a tarefa falhou, `await()` lança a exceção original:

```php
use function Omegaalfa\HttpClient\Loop\async;
use function Omegaalfa\HttpClient\Loop\await;

$task = async(static fn(): string => 'resultado');
$value = await($task);
```

No código principal, fora de uma Fiber, `await()` dirige o Loop até a tarefa
esperada terminar. Dentro de uma Task, ele suspende somente a Fiber atual.

Use `await()` dentro de uma tarefa quando precisar do resultado de outra:

```php
$child = async(static function (): string {
    delay(0.02);
    return 'resposta da tarefa filha';
});

$parent = async(static function () use ($child): string {
    $childResult = $child->await();
    return 'recebi: ' . $childResult;
});

$value = await($parent);
```

Não chame `await()` de dentro de um callback comum do Loop: callbacks não rodam
em uma Task/Fiber, e iniciar um `run()` aninhado não é suportado.

### `awaitAll()`

`awaitAll(Task ...$tasks): array` aguarda as tarefas e devolve seus resultados
na mesma ordem dos argumentos:

```php
$tasks = [];
foreach ([10, 20, 30] as $number) {
    $tasks[] = async(static function () use ($number): int {
        delay(0.01);
        return $number * 2;
    });
}

$values = awaitAll(...$tasks); // [20, 40, 60]
```

Se uma tarefa falhar, `awaitAll()` propaga a exceção do primeiro filho na ordem
dos argumentos. O grupo marca todos os filhos como observados, inclusive os que
não forem aguardados depois dessa falha, para que o Loop não reporte uma segunda
falha sem relação em uma execução futura. Os outros filhos continuam executando.

Cancelar a Task que chamou `awaitAll()` não cancela automaticamente as Tasks
filhas. Elas continuam no Loop; aguarde-as ou cancele-as explicitamente se quiser
controlar o trabalho restante. Para tratar todas as falhas individualmente,
use `try/catch` ao redor de cada `await()`.

### Inspecionar uma Task

```php
$task->getId();      // identificador numérico
$task->getState();   // estado atual
$task->isDone();     // true nos estados completed, failed ou cancelled
$task->getError();   // Throwable ou null
```

Esses métodos servem para diagnóstico. Para receber o resultado ou a exceção,
prefira `await()`.

## Suspender e retomar tarefas

As esperas abaixo só podem ser chamadas dentro de uma Task, pois precisam de
uma Fiber atual.

### `delay()` e `sleep()`

`delay(float $seconds): void` e `Loop::sleep(float $seconds): void` suspendem a
tarefa atual até o timer vencer. O processo não fica dormindo: o Loop pode
executar outra tarefa durante esse intervalo.

```php
use function Omegaalfa\HttpClient\Loop\delay;

$task = async(static function (): void {
    echo "antes\n";
    delay(0.25);
    echo "depois de aproximadamente 250 ms\n";
});
```

Os prazos são medidos com relógio monotônico (`hrtime()`), que não depende de
ajustes do relógio civil do sistema. O timer define quando a tarefa pode
continuar; carga do processo e do sistema podem atrasar a retomada.

### `pause()`

`pause(): void` suspende a tarefa e a coloca de volta no fluxo cooperativo do
Loop, sem esperar um intervalo de tempo:

```php
use function Omegaalfa\HttpClient\Loop\pause;

$task = async(static function (): void {
    for ($i = 0; $i < 100; $i++) {
        processarUmItem($i);
        pause(); // permite que outras tarefas avancem
    }
});
```

`pause()` não torna o processamento paralelo. Ela apenas cede a execução.

## Timers

`Loop::after(float $seconds, Closure $callback): void` agenda um callback para
uma execução futura:

```php
use Omegaalfa\HttpClient\Loop\Loop;

$loop = Loop::get();
$loop->after(1.0, static function (): void {
    echo "um segundo passou\n";
});
$loop->run();
```

O callback é executado pelo Loop, mas não dentro de uma Task. Portanto, ele não
pode chamar `delay()`, `pause()` nem `await()` diretamente. Se precisar de uma
operação suspensível, crie uma Task:

```php
$loop->after(1.0, static function (): void {
    async(static function (): void {
        delay(0.1);
        fazerTrabalho();
    });
});
$loop->run();
```

O timer de callback de `after()` não possui hoje um identificador público para
cancelamento individual. Timers usados por `sleep()` são associados à Task e
são removidos quando ela é cancelada. `shutdown()` limpa todos os timers
pendentes do Loop.

## Esperar por I/O

O driver padrão observa streams com `stream_select()`. As operações
`waitReadable()` e `waitWritable()` suspendem a Task até o stream indicar
prontidão:

```php
use Omegaalfa\HttpClient\Loop\Loop;

$task = async(static function () use ($socket): string {
    Loop::get()->waitReadable($socket);
    return (string) fread($socket, 4096);
});
```

Exemplo completo com um par de sockets locais:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\HttpClient\Loop\Loop;
use function Omegaalfa\HttpClient\Loop\async;
use function Omegaalfa\HttpClient\Loop\awaitAll;

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($pair === false) {
    throw new RuntimeException('Não foi possível criar o par de sockets.');
}
[$reader, $writer] = $pair;
stream_set_blocking($reader, false);
stream_set_blocking($writer, false);

$readTask = async(static function () use ($reader): string {
    Loop::get()->waitReadable($reader);
    $data = fread($reader, 1024);
    if ($data === false) {
        throw new RuntimeException('Falha na leitura.');
    }
    return $data;
});

$writeTask = async(static function () use ($writer): int {
    Loop::get()->waitWritable($writer);
    $written = fwrite($writer, 'mensagem');
    if ($written === false) {
        throw new RuntimeException('Falha na escrita.');
    }
    return $written;
});

[$message, $written] = awaitAll($readTask, $writeTask);
fclose($reader);
fclose($writer);
```

Esse exemplo usa `stream_socket_pair()` e requer que o PHP tenha suporte a
sockets e a pares locais no sistema operacional. A biblioteca em si não exige
a extensão `sockets` para Tasks e timers.

No uso real, prontidão não significa que uma leitura arbitrariamente grande
será concluída em uma chamada. Use streams não bloqueantes, verifique o valor
de retorno de `fread()`/`fwrite()` e repita a operação quando necessário.
Somente uma Task pode aguardar leitura do mesmo stream por vez; o mesmo vale
para a espera de escrita. A biblioteca não fecha streams fornecidos pela
aplicação.

Funções equivalentes estão disponíveis: `readable($stream)` e
`writable($stream)`. Elas não aceitam timeout.

## Timeouts de I/O

As chamadas do Loop aceitam um timeout opcional em segundos:

```php
use Omegaalfa\HttpClient\Loop\IoTimeoutException;
use Omegaalfa\HttpClient\Loop\Loop;

$task = async(static function () use ($socket): ?string {
    try {
        Loop::get()->waitReadable($socket, 0.5);
    } catch (IoTimeoutException) {
        return null; // nenhum dado chegou em meio segundo
    }
    return (string) fread($socket, 4096);
});
```

- Omitir o argumento ou passar `null` mantém a espera sem prazo.
- `0` é válido e significa que o timeout vence imediatamente se a espera não
  tiver sido concluída antes do processamento do timer.
- Valores negativos, `INF`, `-INF`, `NAN` e prazos fora do intervalo monotônico
  suportado lançam `InvalidArgumentException`.
- Quando o timeout vence, `IoTimeoutException` é lançada no ponto da espera.
  Capture-a na tarefa se for uma condição esperada; caso contrário, a Task
  termina como `failed`.

Prontidão, timeout e cancelamento disputam a mesma espera. Somente a primeira
conclusão processada é entregue. A espera vencida remove seu timer ou registro
de I/O correspondente; eventos atrasados não retomam a Fiber novamente.

## Limitar concorrência com Semaphore

`Semaphore` limita operações ativas. Quando não há uma vaga, a Task suspende em
uma fila FIFO sem bloquear o processo:

`buscarComClienteAssincrono()` abaixo representa uma função da sua aplicação:
ela precisa esperar cooperativamente pelo resultado da rede antes de retornar.

```php
use Omegaalfa\HttpClient\Loop\Semaphore;

$connections = new Semaphore(20); // até 20 operações dentro do callback
$tasks = [];

foreach ($urls as $url) {
    $tasks[] = async(static function () use ($connections, $url): string {
        return $connections->withPermit(static function () use ($url): string {
            return buscarComClienteAssincrono($url);
        });
    });
}

$responses = awaitAll(...$tasks);
```

O callback passado a `withPermit()` deve abranger a vida inteira da operação
limitada. Se ele iniciar trabalho que continue depois de retornar, a vaga será
liberada cedo demais para limitar esse trabalho.

O slot é liberado automaticamente em `finally` após retorno, exceção ou
cancelamento cooperativo. `close()` impede novas aquisições e faz tarefas que
estão aguardando receber `SemaphoreClosedException`. Operações que já possuem
uma vaga podem terminar ou limpar seus recursos. `close()` é idempotente.

```php
$semaphore->close();
```

O semáforo não limita quantas Tasks já foram criadas nem o tamanho da fila de
Tasks ou de aguardantes do próprio semáforo. Para limitar memória, controle
também a admissão de trabalho na aplicação, por exemplo criando novas Tasks
somente quando um resultado anterior for consumido.

Métricas simples estão disponíveis em `getActiveCount()`, `getWaitingCount()`
e `isClosed()`.

## Backpressure com BoundedChannel

`Semaphore` limita quantas operações entram em uma região crítica. Ele não
limita quantas Tasks a aplicação já criou. Para desacoplar quem produz valores
de um conjunto fixo de consumidores, use `BoundedChannel`:

```php
use Omegaalfa\HttpClient\Loop\BoundedChannel;
use Omegaalfa\HttpClient\Loop\ChannelClosedException;
use function Omegaalfa\HttpClient\Loop\async;
use function Omegaalfa\HttpClient\Loop\awaitAll;

$channel = new BoundedChannel(100);

$producer = async(static function () use ($channel): void {
    foreach ($items as $item) {
        $channel->send($item); // suspende se os 100 espaços estiverem ocupados
    }
    $channel->close();
});

$workers = [];
for ($worker = 0; $worker < 8; $worker++) {
    $workers[] = async(static function () use ($channel): void {
        try {
            while (true) {
                process($channel->receive());
            }
        } catch (ChannelClosedException) {
            // O produtor fechou o canal e todos os valores foram processados.
        }
    });
}

awaitAll($producer, ...$workers);
```

Esse padrão cria um número fixo de Tasks consumidoras em vez de criar uma Task
para cada item. A fila de mensagens nunca passa da capacidade. Se ela encher,
os produtores suspendem até um consumidor abrir espaço. Isso controla a fila de
mensagens, mas ainda cabe à aplicação limitar quantos produtores cria.

Mensagens aceitas antes de `close()` são preservadas e podem ser drenadas.
Envios que ainda aguardam capacidade falham com `ChannelClosedException`;
recebimentos falham com a mesma exceção somente depois que o canal fechado
fica vazio. Cancelar um consumidor após uma mensagem ter sido reservada devolve
essa mensagem ao início da fila. O contrato completo está em
[BOUNDED_CHANNEL.md](docs/BOUNDED_CHANNEL.md).

## Erros e falhas não aguardadas

Uma exceção não capturada pelo callback de uma Task é guardada nessa Task e seu
estado passa a `failed`. Ao chamar `await()`, a exceção original é lançada:

```php
$task = async(static function (): never {
    throw new DomainException('Não foi possível processar o item.');
});

try {
    await($task);
} catch (DomainException $error) {
    echo $error->getMessage();
}
```

Uma Task que falha e nunca é aguardada não é ignorada silenciosamente. Quando o
Loop chega a um ponto em que não há trabalho para executar, ele lança uma
`LogicException` de diagnóstico com a falha original como exceção anterior.
Por isso, mantenha referências às Tasks importantes e aguarde seus resultados.

Erros de `IoDriver`, como `StreamClosedException` ou `StreamSelectException`,
são entregues à Task que estava aguardando o stream. Ela pode capturar a
exceção ao redor de `waitReadable()`/`waitWritable()`.

## Cancelar uma tarefa

`Task::cancel(): bool` solicita cancelamento cooperativo:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\HttpClient\Loop\TaskCancelled;
use function Omegaalfa\HttpClient\Loop\async;
use function Omegaalfa\HttpClient\Loop\await;
use function Omegaalfa\HttpClient\Loop\delay;

$task = async(static function (): void {
    try {
        while (true) {
            // Substitua pela próxima etapa curta do seu trabalho.
            echo "processando...\n";
            delay(1.0);
        }
    } finally {
        // Libere aqui os recursos que esta tarefa abriu.
        echo "limpeza executada\n";
    }
});

$canceller = async(static function () use ($task): void {
    delay(0.01);
    $task->cancel();
});

try {
    await($task);
} catch (TaskCancelled) {
    echo "tarefa cancelada\n";
}
await($canceller);
```

O método retorna `true` quando aceita uma solicitação e `false` se a tarefa já
terminou ou já tinha um cancelamento solicitado. Se a tarefa estiver suspensa
em uma espera do Loop, o registro é removido e `TaskCancelled` é entregue à
Fiber. Isso permite que blocos `finally` executem. Se estiver executando PHP
síncrono, o cancelamento só é observado no próximo ponto cooperativo; o Loop
não interrompe uma função bloqueante.

Uma tarefa pode capturar `TaskCancelled`; se fizer isso, pode continuar e até
terminar como `completed`. Se a exceção sair do callback, o estado final será
`cancelled`. `await()` em uma tarefa cancelada lança `TaskCancelled`.

Cancelar uma tarefa não cancela automaticamente outras tarefas que ela esteja
aguardando. Esperas obsoletas de timer, I/O, semáforo ou outra Task são
invalidadas para impedir retomadas duplicadas.

## Parar e encerrar o Loop

O Loop é um singleton por processo, acessado por `Loop::get()`.

### `stop()` é temporário

`stop()` faz a chamada atual a `run()` retornar. O Loop permanece ativo e pode
ser executado novamente. Tarefas, timers e callbacks pendentes são preservados:

```php
use Omegaalfa\HttpClient\Loop\Loop;

$loop = Loop::get();
$loop->defer(static fn() => $loop->stop());
$loop->defer(static fn() => print "continua pendente\n");

$loop->run(); // interrompe após o callback que solicitou stop
$loop->run(); // continua o trabalho pendente
```

### `shutdown()` é definitivo

`shutdown()` inicia encerramento terminal: descarta callbacks e timers
pendentes, remove observadores de I/O e solicita cancelamento cooperativo das
Tasks ativas. As Tasks podem executar `finally` e suspender para limpeza
assíncrona. Depois que todas terminarem, o Loop passa a `shut_down` e não pode
ser executado novamente.

```php
$loop = Loop::get();
$loop->shutdown();
```

Chamadas repetidas a `shutdown()` não têm efeito adicional. Chamadas posteriores
a `run()`, criação de Tasks, `defer()` ou agendamento público com `after()` são
rejeitadas. O Loop não fecha streams externos.

Se `shutdown()` for chamado de dentro de uma Task, callback ou timer, ele inicia
o encerramento e a execução atual retorna normalmente ao Loop, que conduz a
limpeza. Não há encerramento forçado nem prazo máximo: código síncrono bloqueante,
uma Task que captura cancelamento ou uma limpeza que nunca termina pode atrasar
ou impedir o encerramento.

O estado pode ser consultado com `Loop::getState()`: `active`, `shutting_down`
ou `shut_down`.

Não há dependência obrigatória de `pcntl`. Integração com sinais do sistema
operacional não faz parte do núcleo; use controle programático do ciclo de vida.

## Estados

### Estados da Task

| Estado | Significado |
|---|---|
| `queued` | criada e aguardando início |
| `running` | executando em sua Fiber |
| `suspended` | aguardando timer, I/O, outra Task, semáforo ou `pause()` |
| `completed` | callback terminou normalmente |
| `failed` | callback terminou com exceção não capturada |
| `cancelled` | `TaskCancelled` saiu do callback |

Os três últimos estados são terminais. Uma tarefa que captura `TaskCancelled`
pode terminar normalmente e ficar `completed`.

### Estados do Loop

```text
active --shutdown()--> shutting_down --limpeza concluída--> shut_down
  |
  +--stop()--> active (run retorna; uma chamada futura a run() pode continuar)
```

## Callbacks e fairness

Callbacks comuns podem ser adicionados com `Loop::defer(Closure $callback)`.
Eles são executados em FIFO e não rodam automaticamente dentro de Fibers. Use
Tasks para trabalho que precise suspender.

```php
use Omegaalfa\HttpClient\Loop\Loop;

$loop = Loop::get();
$loop->defer(static fn() => print "executado pelo Loop\n");
$loop->run();
```

Para evitar que uma fila permanentemente ocupada impeça o progresso de timers
ou I/O, o escalonador processa orçamentos por ciclo: no máximo 256 callbacks,
256 timers e 256 eventos de I/O em cada etapa correspondente. Esses orçamentos
são parte da política interna de fairness e não configuráveis pela API pública.
Eles não limitam a quantidade total que a aplicação pode enfileirar.

Fairness também não é backpressure. Para limitar operações ativas, use
`Semaphore`; para limitar memória, controle quantas Tasks sua aplicação cria.

## Streams, drivers e portabilidade

O driver padrão é `StreamSelectDriver`, baseado em `stream_select()`. Streams
devem ser recursos válidos e normalmente configurados como não bloqueantes.
Streams fechados durante uma espera geram `StreamClosedException`. Falhas reais
do seletor geram `StreamSelectException`; interrupções reconhecidas fazem o
Loop recalcular o próximo prazo.

O Loop remove seus registros, mas não fecha streams que pertencem à aplicação.
Cabe ao código que abriu o stream fechá-lo em um bloco `finally` ou após o uso.

`stream_select()` tem limites que dependem da versão/build do PHP e do sistema
operacional. Em POSIX, descritores podem estar sujeitos a `FD_SETSIZE`; no
Windows há diferenças em pipes de `proc_open()` e `STDIN`. Nem todo wrapper de
stream pode ser selecionado. O driver padrão é adequado a cargas moderadas e
não oferece as mesmas características de escala de `epoll`/`kqueue`.

O contrato `IoDriver` isola a multiplexação. Um driver pode ser fornecido na
primeira inicialização do singleton:

```php
use Omegaalfa\HttpClient\Loop\IoDriver;
use Omegaalfa\HttpClient\Loop\Loop;

// $driver deve implementar o contrato IoDriver.
$loop = Loop::get($driver);
```

Depois que o singleton foi criado, não é possível trocar seu driver. Tarefas e
seus contratos não dependem do driver escolhido.

## O que o Loop não faz

- Não executa código PHP em paralelo ou em múltiplas CPUs.
- Não interrompe código síncrono bloqueante.
- Não torna automaticamente streams ou funções de arquivo não bloqueantes.
- Não oferece servidor TCP de alto nível, cliente HTTP, repetição de timers ou
  watchers de arquivos nesta API.
- Não impõe limite global às filas de callbacks, Tasks ou operações pendentes.
- Não fecha recursos externos durante cancelamento ou shutdown.
- Não instala handlers de sinais nem depende de `pcntl`.

## Problemas comuns

### “A tarefa foi criada, mas nada aconteceu”

Criar uma Task apenas a agenda. O programa precisa executar o Loop: aguarde a
Task com `await()`/`awaitAll()`, use a função `run()`, ou chame
`Loop::get()->run()` diretamente.

### “Recebi `Fiber::suspend()` fora de uma Fiber”

`delay()`, `pause()`, `await()` de outra Task e as esperas de stream precisam
ser executados dentro de uma Task. Um callback de `defer()`/`after()` não é
uma Task. Crie uma Task a partir dele se precisar suspender:

```php
$loop->defer(static function (): void {
    async(static function (): void {
        delay(0.1);
        fazerUmaEtapa();
    });
});
```

### “O Loop parou depois de `stop()`”

Isso é esperado: `stop()` interrompe somente a chamada atual a `run()`. Chame
`run()` novamente para processar o que ficou pendente. Para encerramento
terminal, use `shutdown()`.

### “Meu timeout venceu mais tarde do que o número configurado”

O timer marca o instante mais cedo em que a Task pode ser retomada. O Loop e o
sistema operacional ainda precisam processar essa retomada. Carga alta ou
código síncrono longo pode atrasá-la; timeout não é garantia de execução em
tempo real.

### “O stream está pronto, mas minha leitura não recebeu todos os bytes”

Prontidão quer dizer que uma operação pode progredir, não que toda a mensagem
chegou. Use streams não bloqueantes, mantenha um buffer e continue esperando
quando ainda faltarem bytes. Para protocolos de rede, trate EOF e erros também.

### “A aplicação falhou com uma LogicException no fim do Loop”

Uma Task provavelmente falhou sem que alguém chamasse `await()` nela. Mantenha
as referências às Tasks importantes e aguarde seus resultados, capturando as
exceções esperadas. A `LogicException` de diagnóstico preserva a falha
original como `getPrevious()`.

### “O shutdown não termina”

O encerramento é cooperativo. Uma Task pode capturar `TaskCancelled`, bloquear
em código síncrono ou ficar esperando indefinidamente durante o `finally`.
Revise as rotinas de limpeza; não existe interrupção forçada nem prazo máximo.

## API de referência

### Funções auxiliares (`Omegaalfa\HttpClient\Loop`)

| Função | O que faz |
|---|---|
| `async(callable $fn): Task` | cria e agenda uma Task |
| `await(Task $task): mixed` | espera uma Task e devolve o resultado |
| `awaitAll(Task ...$tasks): array` | espera várias Tasks na ordem recebida |
| `run(callable $main): mixed` | cria a Task principal e devolve seu resultado |
| `delay(float $seconds): void` | espera um timer dentro de uma Task |
| `pause(): void` | cede a execução da Task atual |
| `readable($stream): void` | espera leitura sem timeout dentro de uma Task |
| `writable($stream): void` | espera escrita sem timeout dentro de uma Task |

### `Loop`

| Método | O que faz |
|---|---|
| `Loop::get(?IoDriver $driver = null): Loop` | obtém o singleton; driver só na primeira chamada |
| `defer(Closure $callback): void` | agenda callback FIFO |
| `after(float $seconds, Closure $callback): void` | agenda callback uma vez |
| `pause(): void` | suspende a Task atual até ser escalonada novamente |
| `sleep(float $seconds): void` | suspende a Task atual por timer |
| `waitReadable($stream, float|int|null $timeout = null): void` | espera prontidão de leitura |
| `waitWritable($stream, float|int|null $timeout = null): void` | espera prontidão de escrita |
| `run(?Closure $stop = null): void` | executa até parar, terminar ou a condição retornar `true` |
| `stop(): void` | interrompe a execução atual, preservando o Loop |
| `shutdown(): void` | inicia encerramento terminal cooperativo |
| `getState(): string` | retorna o estado do Loop |

### `Task`

| Método | O que faz |
|---|---|
| `new Task(callable $fn)` | cria e agenda uma Task |
| `await(): mixed` | obtém resultado ou lança a exceção/cancelamento |
| `cancel(): bool` | solicita cancelamento cooperativo |
| `getId(): int` | retorna o identificador |
| `getState(): string` | retorna o estado |
| `isDone(): bool` | informa se está em estado terminal |
| `getError(): ?Throwable` | consulta a exceção terminal, se houver |

### `Semaphore`

| Método/propriedade | O que faz |
|---|---|
| `new Semaphore(int $capacity)` | cria limitador com capacidade positiva |
| `withPermit(callable $operation): mixed` | executa operação dentro de uma vaga |
| `close(): void` | fecha e falha aguardantes |
| `getActiveCount(): int` | consulta operações com vaga |
| `getWaitingCount(): int` | consulta aguardantes |
| `isClosed(): bool` | informa se foi fechado |
| `capacity: int` | capacidade configurada, somente leitura |

### `BoundedChannel`

| Método/propriedade | O que faz |
|---|---|
| `new BoundedChannel(int $capacity)` | cria um buffer FIFO de capacidade positiva |
| `send(mixed $value): void` | envia, suspendendo o produtor se o buffer estiver cheio |
| `receive(): mixed` | recebe a mensagem mais antiga, suspendendo se estiver vazio |
| `close(): void` | rejeita envios futuros e permite drenar mensagens aceitas |
| `getQueueDepth(): int` | consulta mensagens atualmente no buffer |
| `getPeakQueueDepth(): int` | consulta a maior profundidade do buffer observada |
| `getWaitingSenders(): int` | consulta produtores bloqueados |
| `getWaitingReceivers(): int` | consulta consumidores bloqueados |
| `isClosed(): bool` | informa se o canal foi fechado |
| `capacity: int` | capacidade configurada, somente leitura |

## Testes e benchmarks

Execute a regressão com:

```sh
composer test
```

Os benchmarks ficam em [`benchmarks/README.md`](benchmarks/README.md). Eles
executam processos isolados, verificam o total concluído e registram resultados
brutos e relatórios Markdown. Medições dependem do PHP, do sistema operacional,
do hardware e da carga; não trate números de outra máquina como garantia de
desempenho.

## Documentação complementar

- [Cancelamento cooperativo](docs/CANCELLATION.md)
- [Shutdown](docs/SHUTDOWN.md)
- [Timeouts de I/O](docs/IO_TIMEOUTS.md)
- [Contrato e limites de drivers de I/O](docs/IO_DRIVERS.md)
- [Fairness do escalonador](docs/SCHEDULING.md)
- [Semáforo e backpressure](docs/SEMAPHORE.md)
- [Canal limitado e backpressure real](docs/BOUNDED_CHANNEL.md)
