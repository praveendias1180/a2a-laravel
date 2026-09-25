<?php

declare(strict_types=1);

namespace A2A\Laravel\Auth;

use A2A\Auth\User;

/**
 * The caller's identity as a queue job carries it to the worker: just the
 * user name and whether they were authenticated, so the worker writes the
 * task under the same owner as the web request would.
 */
final class SerializedUser implements User
{
    public function __construct(
        private readonly string $userName,
        private readonly bool $authenticated,
    ) {}

    public static function from(User $user): self
    {
        return new self($user->userName(), $user->isAuthenticated());
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function userName(): string
    {
        return $this->userName;
    }
}
