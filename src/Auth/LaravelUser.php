<?php

declare(strict_types=1);

namespace A2A\Laravel\Auth;

use A2A\Auth\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A Laravel user as an A2A user. The auth identifier (normally the primary
 * key) is the user name, so it is what tasks are scoped by.
 */
final class LaravelUser implements User
{
    public function __construct(public readonly Authenticatable $user) {}

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function userName(): string
    {
        $id = $this->user->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : '';
    }
}
