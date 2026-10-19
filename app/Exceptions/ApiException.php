<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Erreur metier renvoyee telle quelle au client : { "message": "...", "code": "machine_readable" }.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self(__('auth.failed'), 401, 'invalid_credentials');
    }

    public static function invalidRefreshToken(): self
    {
        return new self(__('auth.invalid_refresh_token'), 401, 'invalid_refresh_token');
    }

    public static function accountSuspended(): self
    {
        return new self(__('auth.suspended'), 403, 'account_suspended');
    }

    public static function forbiddenRole(): self
    {
        return new self(__('auth.forbidden_role'), 403, 'forbidden_role');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
