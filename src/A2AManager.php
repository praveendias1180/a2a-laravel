<?php

declare(strict_types=1);

namespace A2A\Laravel;

use A2A\Client\Client;
use A2A\Client\ClientCallInterceptor;
use A2A\Client\ClientConfig;
use A2A\Client\ClientFactory;
use A2A\Laravel\Contracts\AgentCardProvider;
use A2A\Laravel\Events\RedisQueueManager;
use A2A\Laravel\Push\QueuedPushNotificationSender;
use A2A\Laravel\Queue\QueuedTaskRunner;
use A2A\Laravel\Stores\DatabasePushNotificationConfigStore;
use A2A\Server\AgentExecution\AgentExecutor;
use A2A\Server\AgentExecution\InlineTaskRunner;
use A2A\Server\AgentExecution\TaskRunner;
use A2A\Server\Events\PdoQueueManager;
use A2A\Server\Events\QueueManager;
use A2A\Server\RequestHandlers\DefaultRequestHandler;
use A2A\Server\Tasks\BasePushNotificationSender;
use A2A\Server\Tasks\PdoTaskStore;
use A2A\Server\Tasks\PushNotificationConfigStore;
use A2A\Server\Tasks\PushNotificationSender;
use A2A\Server\Tasks\TaskStore;
use A2A\Types\AgentCard;
use A2A\Types\AgentInterface;
use A2A\Utils\PushUrlValidator;
use A2A\Utils\Signing;
use A2A\Utils\TransportProtocol;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the core SDK objects for an agent from `config/a2a.php`: the task
 * store, the event log (QueueManager), the runner and the request handler.
 * Bound as a singleton; reach it through the A2A facade.
 *
 * Storage is the core PdoTaskStore and PdoQueueManager on the app's
 * database connection (same code the TCK checks, so behaviour is identical
 * to plain PHP), or RedisQueueManager for events.
 */
class A2AManager
{
    private ?QueueManager $queueManager = null;

    private ?TaskStore $taskStore = null;

    private ?PushNotificationConfigStore $pushConfigStore = null;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    /**
     * An agent from `a2a.agents`.
     */
    public function agent(string $name): AgentDefinition
    {
        $agents = $this->config->get('a2a.agents', []);
        $config = is_array($agents) ? ($agents[$name] ?? null) : null;
        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf('No A2A agent named "%s" in config/a2a.php.', $name));
        }

        return AgentDefinition::fromConfig($name, $config);
    }

    /**
     * The agent's card. When it declares no interfaces, JSON-RPC and
     * HTTP+JSON interfaces under $baseUrl (the agent's route prefix as a full
     * URL) are filled in.
     */
    public function card(AgentDefinition $agent, ?string $baseUrl = null): AgentCard
    {
        $card = $this->resolveCard($agent->card);
        if ($baseUrl !== null && count($card->getSupportedInterfaces()) === 0) {
            $base = rtrim($baseUrl, '/');
            $card->setSupportedInterfaces([
                new AgentInterface(['url' => $base . '/jsonrpc', 'protocol_binding' => TransportProtocol::JSONRPC->value, 'protocol_version' => '1.0']),
                new AgentInterface(['url' => $base . '/rest', 'protocol_binding' => TransportProtocol::HTTP_JSON->value, 'protocol_version' => '1.0']),
            ]);
        }

        return $card;
    }

    public function extendedCard(AgentDefinition $agent, ?string $baseUrl = null): ?AgentCard
    {
        if ($agent->extendedCard === null) {
            return null;
        }
        $card = $this->resolveCard($agent->extendedCard);
        if (count($card->getSupportedInterfaces()) === 0) {
            $card->setSupportedInterfaces($this->card($agent, $baseUrl)->getSupportedInterfaces());
        }

        return $card;
    }

    public function executor(AgentDefinition $agent): AgentExecutor
    {
        $executor = $this->container->make($agent->executor);
        if (!$executor instanceof AgentExecutor) {
            throw new \InvalidArgumentException(sprintf('%s must implement %s.', $agent->executor, AgentExecutor::class));
        }

        return $executor;
    }

    /**
     * The request handler serving one agent. $baseUrl fills in the card's
     * interfaces (see card()).
     */
    public function handler(AgentDefinition $agent, ?string $baseUrl = null): DefaultRequestHandler
    {
        $card = $this->card($agent, $baseUrl);

        return new DefaultRequestHandler(
            agentExecutor: $this->executor($agent),
            taskStore: $this->taskStore(),
            agentCard: $card,
            queueManager: $this->queueManager(),
            pushConfigStore: $this->pushConfigStore(),
            pushUrlValidator: $this->pushUrlValidator(),
            pushSender: $this->pushSender($card),
            extendedAgentCard: $this->extendedCard($agent, $baseUrl),
            extendedCardModifier: $this->extendedCardSigner(),
            taskRunner: $this->runner($agent),
            logger: $this->logger(),
            keepAliveSeconds: $this->float('a2a.sse.keep_alive', 15.0),
            subscribePollSeconds: $this->float('a2a.sse.poll', 0.25),
            maxSubscribeIdleSeconds: $this->nullableFloat('a2a.sse.max_idle'),
        );
    }

    /**
     * The Agent Card signer from `a2a.signing`, or null when no key is set.
     *
     * @return (\Closure(AgentCard): AgentCard)|null
     */
    public function cardSigner(): ?\Closure
    {
        $key = $this->config->get('a2a.signing.key');
        if (!is_string($key) || $key === '') {
            return null;
        }
        if (str_starts_with($key, 'file://')) {
            $contents = file_get_contents(substr($key, 7));
            if ($contents === false) {
                throw new \RuntimeException(sprintf('Cannot read the A2A signing key %s.', $key));
            }
            $key = $contents;
        }
        $alg = $this->config->get('a2a.signing.alg', 'ES256');
        $kid = $this->config->get('a2a.signing.kid', 'a2a');
        $jku = $this->config->get('a2a.signing.jku');

        return Signing::createAgentCardSigner($key, [
            'alg' => is_string($alg) ? $alg : 'ES256',
            'kid' => is_string($kid) ? $kid : 'a2a',
            'jku' => is_string($jku) ? $jku : null,
            'typ' => 'JOSE',
        ]);
    }

    /**
     * Signs a copy of the extended card per request, when signing is on.
     *
     * @return (\Closure(AgentCard, \A2A\Server\ServerCallContext): AgentCard)|null
     */
    private function extendedCardSigner(): ?\Closure
    {
        $signer = $this->cardSigner();
        if ($signer === null) {
            return null;
        }

        return static function (AgentCard $card) use ($signer): AgentCard {
            $copy = new AgentCard();
            $copy->mergeFrom($card);

            return $signer($copy);
        };
    }

    public function pushConfigStore(): PushNotificationConfigStore
    {
        return $this->pushConfigStore ??= new DatabasePushNotificationConfigStore();
    }

    /**
     * The sender for an agent's push notifications, or null when the agent
     * does not declare pushNotifications or `a2a.push.enabled` is false.
     * By default notifications go through a queue job (`a2a.push.queue`);
     * with `a2a.push.queue` false they are sent inline.
     */
    public function pushSender(AgentCard $card): ?PushNotificationSender
    {
        if ($card->getCapabilities()?->getPushNotifications() !== true || !$this->config->get('a2a.push.enabled', true)) {
            return null;
        }
        if (!$this->config->get('a2a.push.queue', true)) {
            return $this->directPushSender();
        }

        return new QueuedPushNotificationSender(
            $this->container->make(Dispatcher::class),
            $this->pushConfigStore(),
            $this->nullableString('a2a.push.connection'),
            $this->nullableString('a2a.push.queue_name'),
        );
    }

    /**
     * The sender that actually POSTs to webhooks (used by the push job, or
     * directly when `a2a.push.queue` is false). Bind `a2a.push.http_client`
     * in the container to use a specific HTTP client (an HttpSender, Guzzle,
     * Symfony HttpClient or PSR-18 client); otherwise one is discovered.
     */
    public function directPushSender(): BasePushNotificationSender
    {
        $attempts = $this->config->get('a2a.push.max_attempts', 3);
        $client = $this->container->bound('a2a.push.http_client') ? $this->container->make('a2a.push.http_client') : null;

        return new BasePushNotificationSender(
            configStore: $this->pushConfigStore(),
            httpClient: is_object($client) ? $client : null,
            pushUrlValidator: $this->pushUrlValidator(),
            maxAttempts: is_numeric($attempts) ? max(1, (int) $attempts) : 3,
            initialBackoffSeconds: $this->float('a2a.push.backoff', 0.5),
            timeoutSeconds: $this->float('a2a.push.timeout', 5.0),
            logger: $this->logger(),
        );
    }

    /**
     * Push-URL screening (SSRF guard), with `a2a.push.allowed_hosts`
     * exempt (for a local webhook in development).
     */
    public function pushUrlValidator(): PushUrlValidator
    {
        $allowed = $this->config->get('a2a.push.allowed_hosts', []);

        return new PushUrlValidator(
            logger: $this->logger(),
            allowedHosts: is_array($allowed) ? array_values(array_filter($allowed, 'is_string')) : [],
        );
    }

    public function runner(AgentDefinition $agent): TaskRunner
    {
        $runner = $agent->runner ?? $this->config->get('a2a.runner', 'inline');

        return match ($runner) {
            'inline' => new InlineTaskRunner($this->queueManager(), $this->logger(), $this->leaseSeconds()),
            'queued' => new QueuedTaskRunner(
                bus: $this->container->make(Dispatcher::class),
                cache: $this->runCache(),
                queueManager: $this->queueManager(),
                agent: $agent,
                connection: $this->nullableString('a2a.queue.connection'),
                queue: $this->nullableString('a2a.queue.name'),
                startTimeout: $this->float('a2a.queue.start_timeout', 30.0),
                pollSeconds: $this->float('a2a.sse.poll', 0.25),
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown A2A runner "%s": use "inline" or "queued".', is_string($runner) ? $runner : get_debug_type($runner))),
        };
    }

    public function taskStore(): TaskStore
    {
        return $this->taskStore ??= new PdoTaskStore($this->pdo(), $this->tablePrefix(), createTable: false);
    }

    public function queueManager(): QueueManager
    {
        if ($this->queueManager !== null) {
            return $this->queueManager;
        }

        $driver = $this->config->get('a2a.events.driver', 'database');
        if ($driver === 'redis') {
            $connection = $this->nullableString('a2a.events.redis_connection');
            /** @var \Illuminate\Redis\RedisManager $redis */
            $redis = $this->container->make('redis');
            $client = $redis->connection($connection)->client();
            if (!is_object($client)) {
                throw new \RuntimeException('The Redis connection has no client.');
            }

            return $this->queueManager = RedisQueueManager::forClient(
                $client,
                $this->nullableString('a2a.events.redis_prefix') ?? 'a2a:',
                (int) $this->float('a2a.events.active_ttl', 86400),
                (int) $this->float('a2a.events.finished_ttl', 3600),
            );
        }
        if ($driver !== 'database') {
            throw new \InvalidArgumentException(sprintf('Unknown A2A events driver "%s": use "database" or "redis".', is_string($driver) ? $driver : get_debug_type($driver)));
        }

        return $this->queueManager = new PdoQueueManager($this->pdo(), $this->tablePrefix(), createTables: false);
    }

    /**
     * The cache store holding queued runs' progress markers.
     */
    public function runCache(): Cache
    {
        /** @var CacheFactory $factory */
        $factory = $this->container->make(CacheFactory::class);

        return $factory->store($this->nullableString('a2a.queue.cache_store'));
    }

    public function logger(): LoggerInterface
    {
        /** @var \Illuminate\Log\LogManager $log */
        $log = $this->container->make('log');

        return $log->channel($this->nullableString('a2a.log_channel'));
    }

    public function leaseSeconds(): int
    {
        return (int) $this->float('a2a.lease_seconds', 600);
    }

    public function tablePrefix(): string
    {
        return $this->nullableString('a2a.storage.table_prefix') ?? 'a2a_';
    }

    public function pdo(): \PDO
    {
        /** @var ConnectionResolverInterface $db */
        $db = $this->container->make('db');
        $connection = $db->connection($this->nullableString('a2a.storage.connection'));
        if (!$connection instanceof \Illuminate\Database\Connection) {
            throw new \RuntimeException('The A2A storage connection is not a PDO database connection.');
        }

        return $connection->getPdo();
    }

    /**
     * A client for a remote agent: $agent is its base URL (the card is
     * fetched from /.well-known/agent-card.json) or its card. HTTP goes
     * through Guzzle, which Laravel ships, with live SSE streaming.
     *
     * Without $config, a ClientConfig bound in the container is used (bind
     * one to set transports, output modes or the HTTP client app-wide).
     *
     * @param list<ClientCallInterceptor> $interceptors
     */
    public function client(string|AgentCard $agent, ?ClientConfig $config = null, array $interceptors = []): Client
    {
        if ($config === null && $this->container->bound(ClientConfig::class)) {
            $config = $this->container->make(ClientConfig::class);
        }

        return ClientFactory::createClient($agent, $config, $interceptors);
    }

    /**
     * @param class-string|array<array-key, mixed> $spec
     */
    private function resolveCard(string|array $spec): AgentCard
    {
        if (is_array($spec)) {
            $card = new AgentCard();
            $card->mergeFromJsonString(json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $card;
        }

        $provider = $this->container->make($spec);
        if ($provider instanceof AgentCardProvider) {
            return $provider->agentCard();
        }
        if ($provider instanceof AgentCard) {
            return $provider;
        }

        throw new \InvalidArgumentException(sprintf('%s must implement %s.', $spec, AgentCardProvider::class));
    }

    private function float(string $key, float $default): float
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function nullableFloat(string $key): ?float
    {
        $value = $this->config->get($key);

        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
