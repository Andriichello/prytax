<?php

namespace PryTax\Mcp\Tools;

use Core\Helpers\CurrentOrganization;
use Core\Http\Controllers\CrudController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Mcp\ApiDispatcher;
use PryTax\Mcp\McpSession;
use Stringable;
use Throwable;

/**
 * Class ApiEndpoints.
 *
 * The endpoints of PryTax's API, which Claude may reach (`api-read`, `api-write`): their methods and paths, and the
 * fields, which each takes, with their rules, as its request validates them (where they can be told without a
 * request of its own).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('List the endpoints of PryTax\'s API Claude may reach with api-read and api-write: method, path (under /api/) and, where they can be told, the fields each takes with their validation rules. Narrow it with search, such as "invoices" or "payments". Lists take filter[field][eq|in|gte|lte]=…, sort=-field, include=relation, page[size]=n.')]
class ApiEndpoints extends SessionTool
{
    /**
     * Handle the call.
     *
     * @param Request $request
     * @param McpSession $session
     *
     * @return Response|ResponseFactory
     */
    public function handle(Request $request, McpSession $session): Response|ResponseFactory
    {
        [$integration, $user] = $session->open();
        $search = mb_strtolower(trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '')));
        $writes = $integration->allowsWrites();
        $endpoints = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            $path = (string) preg_replace('#^api/#', '', $route->uri());
            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            if (!str_starts_with($route->uri(), 'api/') || !ApiDispatcher::isOpen($path) || ($search !== '' && !str_contains(mb_strtolower($path . ' ' . $route->getName()), $search))) {
                continue;
            }

            // Without changes allowed, only what reads
            if (!$writes && !in_array('GET', $methods, true)) {
                continue;
            }

            $endpoints[] = array_filter([
                'methods' => $methods,
                'path' => $path,
                'name' => $route->getName(),
                'fields' => in_array('GET', $methods, true) ? null : CurrentOrganization::run((int) $user->organization_id, fn () => $this->fieldsOf($route, $user)),
            ], fn ($value) => $value !== null);
        }

        return Response::structured(['writes' => $writes, 'endpoints' => $endpoints]);
    }

    /**
     * Get the fields of a route's request, with their rules, or null when they can't be told.
     *
     * @param Route $route
     * @param mixed $user
     *
     * @return array<string, string>|null
     */
    protected function fieldsOf(Route $route, mixed $user): ?array
    {
        try {
            $controller = $route->getController();
            $class = $controller instanceof CrudController ? $controller->getOperationRequest($route->getActionMethod()) : null;

            if ($class === null) {
                return null;
            }

            $request = $class::createFrom(HttpRequest::create('/', 'POST'));
            $request->setUserResolver(fn () => $user);
            $request->setContainer(app());

            return array_map(fn ($rules) => implode('|', array_map(
                fn ($rule) => is_string($rule) ? $rule : ($rule instanceof Stringable ? (string) $rule : class_basename($rule)),
                is_array($rules) ? $rules : explode('|', (string) $rules),
            )), $request->rules());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the tool's input schema.
     *
     * @param JsonSchema $schema
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['search' => $schema->string()->description('Only the paths or names, which contain it, such as invoices.')];
    }
}
