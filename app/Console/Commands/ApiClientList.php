<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class ApiClientList extends Command
{
    protected $signature = 'api:client-list';

    protected $description = 'List API clients and their tokens';

    public function handle(): int
    {
        $rows = [];
        foreach (ApiClient::with('tokens')->orderBy('id')->get() as $client) {
            foreach ($client->tokens as $token) {
                $rows[] = [
                    $client->id,
                    $client->name,
                    $client->contact,
                    $token->id,
                    implode(', ', $token->abilities),
                    $token->last_used_at?->toDateTimeString() ?? 'never',
                ];
            }
        }

        $this->table(['Client', 'Name', 'Contact', 'Token', 'Abilities', 'Last used'], $rows);

        return self::SUCCESS;
    }
}
