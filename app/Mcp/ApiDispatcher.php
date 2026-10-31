<?php

namespace PryTax\Mcp;

use Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PryTax\Models\Integration;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ApiDispatcher.
 *
 * Claude's way to everything its acting user may do (builds.md §7): a request to PryTax's own API (`/api/…`), handled
 * as the API handles the SPA's (routes, validation, policies, side effects), as that user, signed in by `McpSession`.
 * Local files go along as uploads (an invoice's PDF). Never reached: the integrations themselves (Claude would let
 * itself write, or take a new key), signing in and passwords, the whole installation's copies and restores, and the
 * recovery kit; while the integration allows some ФОП only, only their paths (`profiles/{id}/…`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp
 */
class ApiDispatcher
{
    /**
     * The paths, under `/api/`, which Claude never reaches.
     *
     * @var list<string>
     */
    public const array BLOCKED = [
        'organization/integrations',
        'auth/',
        'installation',
        'desktop/',
        'registration',
        'single/',
        'environment',
    ];

    /**
     * The longest answer Claude gets, in characters of JSON; a longer one is cut, with a note.
     *
     * @var int
     */
    public const int MAX_ANSWER = 200_000;

    /**
     * The largest file Claude uploads, in bytes.
     *
     * @var int
     */
    public const int MAX_FILE = 50 * 1024 * 1024;

    /**
     * Send a request to the API as the user, and get its status and its answer.
     *
     * @param Integration $integration
     * @param User $user
     * @param string $method
     * @param string $path Such as `profiles/1/invoices`, with or without `/api/`.
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @param array<string, string> $files The fields of local files to upload, with their paths.
     * @param array<string, string> $headers Such as `Idempotency-Key` or `If-Unmodified-Since`, by name.
     *
     * @return array{status: int, body: mixed}
     * @throws ValidationException
     */
    public function dispatch(
        Integration $integration,
        User $user,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $files = [],
        array $headers = [],
    ): array {
        $path = $this->pathOf($integration, $path);
        $method = strtoupper($method);
        $uploads = array_map(fn (string $file) => $this->upload($file), $files);
        $server = [
            ...array_combine(
                array_map(fn (string $name) => 'HTTP_' . strtoupper(str_replace('-', '_', $name)), array_keys($headers)),
                array_values($headers),
            ),
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'Claude (PryTax MCP)',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $uri = '/api/' . $path . ($method !== 'GET' && $query !== [] ? '?' . http_build_query($query) : '');

        // A body with files goes as a form, as the SPA's uploads do; without them, as JSON
        $request = $uploads !== []
            ? Request::create($uri, $method, $body ?? [], [], $uploads, $server)
            : Request::create($uri, $method, $method === 'GET' ? $query : [], [], [], [
                ...$server,
                ...($body === null ? [] : ['CONTENT_TYPE' => 'application/json']),
            ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $request->setUserResolver(fn () => $user);

        $response = app()->handle($request);

        return ['status' => $response->getStatusCode(), 'body' => $this->answerOf($response)];
    }

    /**
     * Determine whether Claude may reach the path at all.
     *
     * @param string $path Under `/api/`.
     *
     * @return bool
     */
    public static function isOpen(string $path): bool
    {
        foreach (self::BLOCKED as $blocked) {
            if (str_starts_with($path, $blocked)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the path under `/api/`, refusing one Claude may not reach.
     *
     * @param Integration $integration
     * @param string $path
     *
     * @return string
     * @throws ValidationException
     */
    protected function pathOf(Integration $integration, string $path): string
    {
        $path = trim((string) preg_replace('#^/?api/#', '', trim($path)), '/');

        if ($path === '' || str_contains($path, '..') || !self::isOpen($path)) {
            throw ValidationException::withMessages(['path' => "Claude doesn't reach /api/$path."]);
        }

        if ($integration->profile_ids !== null) {
            $profile = preg_match('#^profiles/(\d+)(/|$)#', $path, $match) ? (int) $match[1] : null;

            if ($profile === null || !$integration->allowsProfile($profile)) {
                throw ValidationException::withMessages(['path' => 'Claude sees some ФОП only: a path of one of them, profiles/{id}/…, of '
                    . implode(', ', $integration->profile_ids) . '.']);
            }
        }

        return $path;
    }

    /**
     * Get a local file as an upload.
     *
     * @param string $path
     *
     * @return UploadedFile
     * @throws ValidationException
     */
    protected function upload(string $path): UploadedFile
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ValidationException::withMessages(['files' => "There's no file to read at $path."]);
        }

        if (filesize($path) > self::MAX_FILE) {
            throw ValidationException::withMessages(['files' => "$path is larger than 50 MB."]);
        }

        return new UploadedFile($path, basename($path), mime_content_type($path) ?: null, null, true);
    }

    /**
     * Get the answer: its JSON, without an error's trace (only a server error's message), cut when it's too long; or
     * its text.
     *
     * @param Response $response
     *
     * @return mixed
     */
    protected function answerOf(Response $response): mixed
    {
        $content = (string) $response->getContent();
        $answer = json_decode($content, true);

        if (!is_array($answer)) {
            return mb_substr($content, 0, 2000);
        }

        if ($response->getStatusCode() >= 500) {
            return ['message' => $answer['message'] ?? 'Server error'];
        }

        // In debug mode, an error carries where it was thrown: the installation's code and folders, not PryTax's data
        $answer = array_diff_key($answer, array_flip(['exception', 'file', 'line', 'trace']));

        $json = (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_strlen($json) > self::MAX_ANSWER
            ? ['truncated' => true, 'note' => 'The answer was too long: ask for less (filters, page[size]).', 'json' => mb_substr($json, 0, self::MAX_ANSWER)]
            : $answer;
    }
}
