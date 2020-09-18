<?php

/**
 * Copyright (c) 2016 - 2020 Itspire.
 * This software is licensed under the BSD-3-Clause license. (see LICENSE.md for full license)
 * All Right Reserved.
 */

declare(strict_types=1);

namespace Itspire\MonologLoki\Handler;

use Itspire\MonologLoki\Formatter\LokiFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LokiHandler extends AbstractProcessingHandler
{
    /** the scheme, hostname and port to the Loki system */
    protected ?string $entrypoint;

    /** the identifiers for Basic Authentication to the Loki system */
    protected array $basicAuth;

    /** the name of the system (hostname) sending log messages to Loki */
    protected ?string $systemName;

    /** the list of default context variables to be sent to the Loki system */
    protected array $globalContext = [];

    /** the list of default labels to be sent to the Loki system */
    protected array $globalLabels = [];

    protected HttpClientInterface $client;
    protected \SplObjectStorage $responses;


    public function __construct(array $apiConfig, int $level = Logger::DEBUG, bool $bubble = true, HttpClientInterface $client = null)
    {
        if (!function_exists('json_encode')) {
            throw new \RuntimeException(sprintf('The %s handler needs the PHP json extension.', __CLASS__));
        }

        if (!interface_exists('\Symfony\Contracts\HttpClient\HttpClientInterface')) {
            throw new \LogicException(sprintf('The %s handler needs an HTTP client. Try running "composer require symfony/http-client".', __CLASS__));
        }

        parent::__construct($level, $bubble);

        $this->entrypoint = $this->getEntrypoint($apiConfig['entrypoint']);
        $this->globalContext = $apiConfig['context'] ?? [];
        $this->globalLabels = $apiConfig['labels'] ?? [];
        $this->systemName = $apiConfig['client_name'] ?? null;
        if (isset($apiConfig['auth']['basic'])) {
            $this->basicAuth = (2 === count($apiConfig['auth']['basic'])) ? $apiConfig['auth']['basic'] : null;
        }
        $this->client = $client ?: HttpClient::create(['timeout' => 1]);
        $this->responses = new \SplObjectStorage();
    }

    public function __destruct()
    {
        $this->wait(true);
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LokiFormatter($this->globalLabels, $this->globalContext, $this->systemName);
    }

    private function getEntrypoint(string $entrypoint): string
    {
        if ('/' !== substr($entrypoint, -1)) {
            return $entrypoint;
        }

        return substr($entrypoint, 0, -1);
    }

    /** @throws \JsonException */
    public function handleBatch(array $records): void
    {
        $records = array_filter($records, [$this, 'isHandling']);
        $rows = [];

        foreach ($records as $record) {
            $record = $this->processRecord($record);
            $rows[] = $this->getFormatter()->format($record);
        }

        $this->sendPacket(['streams' => $rows]);
    }

    /** @throws \JsonException */
    private function sendPacket(array $packet): void
    {
        $payload = json_encode($packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $url = sprintf('%s/loki/api/v1/push', $this->entrypoint);
        $response = $this->client->request('POST', $url, [
            'auth_basic' => $this->basicAuth,
            'body' => $payload,
            'headers' => [
                'Content-Type' => 'application/json',
                'Content-Length' => strlen($payload)
            ]
        ]);

        $this->responses->attach($response);

        $this->wait(false);
    }

    /** @throws \JsonException */
    protected function write(array $record): void
    {
        $this->sendPacket(['streams' => [$record['formatted']]]);
    }

    private function wait(bool $blocking)
    {
        foreach ($this->client->stream($this->responses, $blocking ? null : 0.0) as $response => $chunk) {
            try {
                if ($chunk->isTimeout() && !$blocking) {
                    continue;
                }
                if (!$chunk->isFirst() && !$chunk->isLast()) {
                    continue;
                }
                if ($chunk->isLast()) {
                    $this->responses->detach($response);
                }
            } catch (ExceptionInterface $e) {
                $this->responses->detach($response);
                error_log(sprintf("Could not push logs to Loki:\n%s", (string) $e));
            }
        }
    }
}
