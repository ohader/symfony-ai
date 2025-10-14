<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\OpenAi\Gpt;

use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ChoiceResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\Component\HttpClient\Chunk\ServerSentEvent;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface as HttpResponse;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Denis Zunke <denis.zunke@gmail.com>
 */
final class ResultConverter implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return $model instanceof Gpt;
    }

    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ResultInterface
    {
        $response = $result->getObject();

        if (401 === $response->getStatusCode()) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'];
            throw new AuthenticationException($errorMessage);
        }

        if (400 === $response->getStatusCode()) {
            $errorMessage = json_decode($response->getContent(false), true)['error']['message'] ?? 'Bad Request';
            throw new BadRequestException($errorMessage);
        }

        if (429 === $response->getStatusCode()) {
            $headers = $response->getHeaders(false);
            $resetTime = $headers['x-ratelimit-reset-requests'][0]
                ?? $headers['x-ratelimit-reset-tokens'][0]
                ?? null;

            throw new RateLimitExceededException($resetTime ? self::parseResetTime($resetTime) : null);
        }

        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($response));
        }

        $data = $result->getData();
        $objectType = $data['object'] ?? null;

        if ($objectType === 'chat.completion') {
            return $this->convertChatCompletionType($data);
        }

        if ($objectType === 'response') {
            return $this->convertResponseType($data);
        }

        throw new RuntimeException(\sprintf('Unexpected object type "%s".', $objectType ?? 'null'));
    }

    private function convertChatCompletionType(array $data): ResultInterface
    {
        if (isset($data['error']['code']) && 'content_filter' === $data['error']['code']) {
            throw new ContentFilterException($data['error']['message']);
        }

        if (isset($data['error'])) {
            throw new RuntimeException(\sprintf('Error "%s"-%s (%s): "%s".', $data['error']['code'], $data['error']['type'], $data['error']['param'], $data['error']['message']));
        }

        if (!isset($data['choices'])) {
            throw new RuntimeException('Response does not contain choices.');
        }

        $choices = array_map($this->convertChoice(...), $data['choices']);

        return 1 === \count($choices) ? $choices[0] : new ChoiceResult(...$choices);
    }

    private function convertResponseType(array $data): ResultInterface
    {
        if (isset($data['error']['code']) && 'content_filter' === $data['error']['code']) {
            throw new ContentFilterException($data['error']['message']);
        }

        if (isset($data['error'])) {
            throw new RuntimeException(\sprintf('Error "%s"-%s (%s): "%s".', $data['error']['code'], $data['error']['type'], $data['error']['param'], $data['error']['message']));
        }

        if (!isset($data['output'])) {
            throw new RuntimeException('Response does not contain output.');
        }

        return $this->convertOutput($data['output']);
    }

    private function convertStream(HttpResponse $result): \Generator
    {
        $toolCalls = [];
        foreach ((new EventSourceHttpClient())->stream($result) as $chunk) {
            if (!$chunk instanceof ServerSentEvent || '[DONE]' === $chunk->getData()) {
                continue;
            }

            $data = $chunk->getArrayData();

            if ($this->streamIsToolCall($data)) {
                $toolCalls = $this->convertStreamToToolCalls($toolCalls, $data);
            }

            if ([] !== $toolCalls && $this->isToolCallsStreamFinished($data)) {
                yield new ToolCallResult(...array_map($this->convertToolCall(...), $toolCalls));
            }

            if (!isset($data['choices'][0]['delta']['content'])) {
                continue;
            }

            yield $data['choices'][0]['delta']['content'];
        }
    }

    /**
     * @param array<string, mixed> $toolCalls
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function convertStreamToToolCalls(array $toolCalls, array $data): array
    {
        if (!isset($data['choices'][0]['delta']['tool_calls'])) {
            return $toolCalls;
        }

        foreach ($data['choices'][0]['delta']['tool_calls'] as $i => $toolCall) {
            if (isset($toolCall['id'])) {
                // initialize tool call
                $toolCalls[$i] = [
                    'id' => $toolCall['id'],
                    'function' => $toolCall['function'],
                ];
                continue;
            }

            // add arguments delta to tool call
            $toolCalls[$i]['function']['arguments'] .= $toolCall['function']['arguments'];
        }

        return $toolCalls;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function streamIsToolCall(array $data): bool
    {
        return isset($data['choices'][0]['delta']['tool_calls']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isToolCallsStreamFinished(array $data): bool
    {
        return isset($data['choices'][0]['finish_reason']) && 'tool_calls' === $data['choices'][0]['finish_reason'];
    }

    /**
     * @param array{
     *     index: int,
     *     message: array{
     *         role: 'assistant',
     *         content: ?string,
     *         tool_calls: array{
     *             id: string,
     *             type: 'function',
     *             function: array{
     *                 name: string,
     *                 arguments: string
     *             },
     *         },
     *         refusal: ?mixed
     *     },
     *     logprobs: string,
     *     finish_reason: 'stop'|'length'|'tool_calls'|'content_filter',
     * } $choice
     */
    private function convertChoice(array $choice): ToolCallResult|TextResult
    {
        if ('tool_calls' === $choice['finish_reason']) {
            return new ToolCallResult(...array_map([$this, 'convertToolCall'], $choice['message']['tool_calls']));
        }

        if (\in_array($choice['finish_reason'], ['stop', 'length'], true)) {
            return new TextResult($choice['message']['content']);
        }

        throw new RuntimeException(\sprintf('Unsupported finish reason "%s".', $choice['finish_reason']));
    }

    private function convertOutput(array $output): ToolCallResult|TextResult
    {
        $toolCalls = array_filter($output, static fn(array $item): bool => $item['type'] === 'function_call');
        if ($toolCalls !== []) {
            return new ToolCallResult(...array_map(
                static fn(array $toolCall): ToolCall => new ToolCall(
                    $toolCall['id'],
                    $toolCall['name'],
                    json_decode($toolCall['arguments'], true, \JSON_THROW_ON_ERROR),
                ),
                $toolCalls,
            ));
        }

        // @todo https://platform.openai.com/docs/api-reference/responses/object#responses/object-output
        foreach ($output as $item) {
            if ($item['type'] !== 'message' || $item['role'] !== Role::Assistant->value) {
                continue;
            }
            return new TextResult($item['content'][0]['text']);
        }

        throw new RuntimeException('Response does not contain output.');
    }

    /**
     * @param array{
     *     id: string,
     *     type: 'function',
     *     function: array{
     *         name: string,
     *         arguments: string
     *     }
     * } $toolCall
     */
    private function convertToolCall(array $toolCall): ToolCall
    {
        $arguments = json_decode($toolCall['function']['arguments'], true, \JSON_THROW_ON_ERROR);

        return new ToolCall($toolCall['id'], $toolCall['function']['name'], $arguments);
    }

    /**
     * Converts OpenAI's reset time format (e.g. "1s", "6m0s", "2m30s") into seconds.
     *
     * Supported formats:
     * - "1s"
     * - "6m0s"
     * - "2m30s"
     */
    private static function parseResetTime(string $resetTime): ?int
    {
        if (preg_match('/^(?:(\d+)m)?(?:(\d+)s)?$/', $resetTime, $matches)) {
            $minutes = isset($matches[1]) ? (int) $matches[1] : 0;
            $secs = isset($matches[2]) ? (int) $matches[2] : 0;

            return ($minutes * 60) + $secs;
        }

        return null;
    }
}
