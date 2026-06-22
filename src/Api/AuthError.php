<?php

declare(strict_types=1);

namespace Phlix\Console\Api;

/**
 * Authentication failed or the session expired (HTTP 401, or a failed token
 * refresh). The app should drop the stored token and return to the login
 * screen when it sees this.
 */
final class AuthError extends ApiError
{
}
