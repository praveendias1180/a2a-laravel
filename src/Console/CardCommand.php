<?php

declare(strict_types=1);

namespace A2A\Laravel\Console;

use A2A\Laravel\A2AManager;
use A2A\Laravel\AgentDefinition;
use A2A\Laravel\Http\A2AController;
use A2A\Utils\Errors\InvalidParamsError;
use A2A\Utils\ProtoUtils;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;

/**
 * php artisan a2a:card [agent]: prints an agent's card as it will be served
 * and checks it (required fields, at least one skill and one interface).
 * Exits 1 when the card is invalid.
 *
 * @internal Not covered by the 1.x backward-compatibility promise; may change in any release.
 */
final class CardCommand extends Command
{
    protected $signature = 'a2a:card {agent=default : Agent name (from Route::a2a or config/a2a.php)}';

    protected $description = 'Print and validate an A2A agent card';

    public function handle(A2AManager $manager, Router $router): int
    {
        $name = $this->argument('agent');
        if (!is_string($name) || $name === '') {
            $this->error('Pass an agent name.');

            return self::FAILURE;
        }
        $definition = $this->fromRoutes($router, $name) ?? $manager->agent($name);
        $card = $manager->card($definition, url(trim($definition->prefix, '/')));

        $this->line((string) json_encode(json_decode($card->serializeToJsonString()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $problems = [];
        try {
            ProtoUtils::validateProtoRequiredFields($card);
        } catch (InvalidParamsError $e) {
            $problems[] = $e->getMessage();
        }
        if (count($card->getSkills()) === 0) {
            $problems[] = 'The card declares no skills.';
        }
        if (count($card->getSupportedInterfaces()) === 0) {
            $problems[] = 'The card declares no interfaces (mount it with Route::a2a() to fill them in).';
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }
        $this->info('The card is valid.');

        return self::SUCCESS;
    }

    private function fromRoutes(Router $router, string $name): ?AgentDefinition
    {
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $data = $route->defaults[A2AController::AGENT_DEFAULT] ?? null;
            if (is_array($data) && ($data['name'] ?? null) === $name) {
                return AgentDefinition::fromArray($data);
            }
        }

        return null;
    }
}
