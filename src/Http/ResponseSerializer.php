<?php

declare(strict_types=1);

namespace AmrAchraf\LaravelIdempotencyLedger\Http;

use AmrAchraf\LaravelIdempotencyLedger\Domain\Operation;
use AmrAchraf\LaravelIdempotencyLedger\Domain\StoredResponse;
use Illuminate\Contracts\Encryption\Encrypter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ResponseSerializer
{
    /** @var list<string> */
    private const DEFAULT_HEADERS = [
        'content-type',
        'content-language',
        'location',
        'etag',
        'cache-control',
        'vary',
    ];

    /** @var list<string> */
    private const FORBIDDEN_HEADERS = [
        'authorization',
        'proxy-authorization',
        'www-authenticate',
        'proxy-authenticate',
        'cookie',
        'set-cookie',
        'connection',
        'keep-alive',
        'proxy-connection',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'content-length',
        'date',
        'server',
        'retry-after',
    ];

    public function __construct(private readonly Encrypter $encrypter) {}

    public function capture(Response $response): ?StoredResponse
    {
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 500
            || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }

        $body = $response->getContent();

        if (! is_string($body) || preg_match('//u', $body) !== 1 || strlen($body) > (int) config('idempotency.replay.max_response_bytes', 65_536)) {
            return null;
        }

        $encrypted = (bool) config('idempotency.replay.encrypt_body', true);
        $storedBody = $encrypted ? $this->encrypter->encrypt($body, false) : $body;

        return new StoredResponse(
            status: $response->getStatusCode(),
            contentType: $response->headers->get('Content-Type'),
            headers: $this->filteredHeaders($response),
            body: $storedBody,
            bodySize: strlen($body),
            bodyEncrypted: $encrypted,
        );
    }

    public function replay(Operation $operation): ?Response
    {
        if (! $operation->replayable || $operation->responseStatus === null || $operation->responseBody === null) {
            return null;
        }

        try {
            $body = $operation->responseBodyEncrypted
                ? $this->encrypter->decrypt($operation->responseBody, false)
                : $operation->responseBody;
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($body)) {
            return null;
        }

        $response = new Response($body, $operation->responseStatus);

        foreach ($operation->responseHeaders ?? [] as $name => $values) {
            $response->headers->set($name, $values);
        }

        if ($operation->responseContentType !== null) {
            $response->headers->set('Content-Type', $operation->responseContentType);
        }

        $response->headers->set('Idempotency-Replayed', 'true');
        $response->headers->set('Idempotency-Operation-Id', $operation->id);

        return $response;
    }

    /** @return array<string, list<string>> */
    private function filteredHeaders(Response $response): array
    {
        $allowed = array_unique(array_merge(self::DEFAULT_HEADERS, array_map(
            static fn (mixed $header): string => strtolower((string) $header),
            (array) config('idempotency.replay.additional_headers', []),
        )));
        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            $normalized = strtolower($name);

            if (! in_array($normalized, $allowed, true) || in_array($normalized, self::FORBIDDEN_HEADERS, true)) {
                continue;
            }

            $headers[$name] = array_map(static fn (mixed $value): string => (string) $value, $values);
        }

        return $headers;
    }
}
