<?php

declare(strict_types=1);

namespace Omegaalfa\HttpClient\Http\Exceptions;

use Omegaalfa\HttpClient\Http\Request;
use Omegaalfa\HttpClient\Http\Response;
use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        protected ?Request $request = null,
        protected ?Response $response = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function response(): ?Response
    {
        return $this->response;
    }
}