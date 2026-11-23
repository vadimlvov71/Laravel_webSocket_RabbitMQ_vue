<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

/** Отзывает все ключи клиента: следующий запрос с ними получит 401. */
class ApiClientRevoke extends Command
{
    protected $signature = 'api:client-revoke {client : Client id from api:client-list}';

    protected $description = 'Revoke all tokens of an API client';

    public function handle(): int
    {
        $client = ApiClient::find($this->argument('client'));

        if (! $client) {
            $this->error('Client not found.');

            return self::FAILURE;
        }

        $count = $client->tokens()->delete();
        $this->info("Revoked {$count} token(s) of \"{$client->name}\".");

        return self::SUCCESS;
    }
}
