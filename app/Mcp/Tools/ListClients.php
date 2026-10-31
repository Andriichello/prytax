<?php

namespace PryTax\Mcp\Tools;

use Core\Models\User;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Http\Resources\ClientResource;
use PryTax\Models\Client;
use PryTax\Models\Integration;
use PryTax\Models\Profile;
use PryTax\Repositories\ClientRepository;

/**
 * Class ListClients.
 *
 * A ФОП's clients, with how many contracts and invoices each has, and what each owes.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('List a ФОП\'s clients, with their counts of contracts and invoices and what each owes (outstanding, per currency). Their ids filter list-invoices.')]
class ListClients extends ReadTool
{
    /**
     * The model, whose policy authorizes the read.
     *
     * @var class-string
     */
    protected string $model = Client::class;

    /**
     * Read the clients.
     *
     * @param array<string, mixed> $arguments
     * @param User $user
     * @param Integration $integration
     * @param Profile|null $profile
     *
     * @return array<string, mixed>
     */
    protected function read(array $arguments, User $user, Integration $integration, ?Profile $profile): array
    {
        $request = $this->apiRequest($user, ['append' => 'outstanding']);

        return [
            'clients' => app(ClientRepository::class)->builder($request)->get()
                ->map(fn (Client $client) => (new ClientResource($client))->toArray($request))
                ->all(),
        ];
    }
}
