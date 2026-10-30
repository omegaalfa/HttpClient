<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http;

/**
 * Fronteira de rede do cliente: executa uma requisição HTTP e devolve a resposta.
 *
 * É a costura de injeção do AsyncHttpClient — implemente esta interface (ou
 * use Testing\MockTransport) para testar código consumidor sem rede.
 */
interface TransportInterface
{
    /**
     * Envia a requisição, seguindo os redirects configurados, materializando
     * a resposta completa.
     */
    public function send(Request $request, RequestOptions $options): Response;

    /**
     * Envia a requisição, seguindo os redirects configurados, com o corpo da
     * resposta em streaming.
     */
    public function sendStream(Request $request, RequestOptions $options): StreamResponse;
}
