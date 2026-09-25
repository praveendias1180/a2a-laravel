<?php

declare(strict_types=1);

namespace A2A\Laravel;

/**
 * One agent as the bridge sees it: where its card and executor come from,
 * how it runs, and where it is mounted.
 *
 * Holds only strings and arrays, so it survives `php artisan route:cache`
 * (it travels in the route defaults) and queue serialization (the job
 * carries it to the worker).
 *
 * @phpstan-type CardSpec class-string|array<array-key, mixed>
 */
final class AgentDefinition
{
    /**
     * @param class-string|array<array-key, mixed>      $card
     * @param class-string                           $executor
     * @param list<string>                           $middleware
     * @param class-string|array<array-key, mixed>|null $extendedCard
     */
    public function __construct(
        public readonly string $name,
        public readonly string|array $card,
        public readonly string $executor,
        public readonly ?string $runner = null,
        public readonly array $middleware = [],
        public readonly string|array|null $extendedCard = null,
        public readonly string $prefix = '',
    ) {}

    /**
     * @param array<array-key, mixed> $config
     */
    public static function fromConfig(string $name, array $config, string $prefix = ''): self
    {
        $card = self::cardSpec($config['card'] ?? null, "a2a.agents.{$name}.card");
        $executor = $config['executor'] ?? null;
        if (!is_string($executor) || !class_exists($executor)) {
            throw new \InvalidArgumentException(sprintf('a2a.agents.%s.executor must be an existing AgentExecutor class.', $name));
        }
        $runner = $config['runner'] ?? null;
        $middleware = $config['middleware'] ?? [];
        $extended = $config['extended_card'] ?? null;

        return new self(
            name: $name,
            card: $card,
            executor: $executor,
            runner: is_string($runner) ? $runner : null,
            middleware: is_array($middleware) ? array_values(array_filter($middleware, 'is_string')) : [],
            extendedCard: $extended === null ? null : self::cardSpec($extended, "a2a.agents.{$name}.extended_card"),
            prefix: $prefix,
        );
    }

    /**
     * @return class-string|array<array-key, mixed>
     */
    private static function cardSpec(mixed $spec, string $key): string|array
    {
        if (is_array($spec) || (is_string($spec) && class_exists($spec))) {
            return $spec;
        }

        throw new \InvalidArgumentException(sprintf('%s must be an existing AgentCardProvider class or a card array.', $key));
    }

    public function withPrefix(string $prefix): self
    {
        return new self($this->name, $this->card, $this->executor, $this->runner, $this->middleware, $this->extendedCard, $prefix);
    }

    /**
     * @return array{name: string, card: class-string|array<array-key, mixed>, executor: class-string, runner: ?string, middleware: list<string>, extended_card: class-string|array<array-key, mixed>|null, prefix: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'card' => $this->card,
            'executor' => $this->executor,
            'runner' => $this->runner,
            'middleware' => $this->middleware,
            'extended_card' => $this->extendedCard,
            'prefix' => $this->prefix,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? 'default';
        $prefix = $data['prefix'] ?? '';

        return self::fromConfig(is_string($name) ? $name : 'default', $data, is_string($prefix) ? $prefix : '');
    }
}
