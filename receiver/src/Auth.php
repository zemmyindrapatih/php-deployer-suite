<?php

namespace Deployer\Receiver;

class Auth
{
    private string $tokenHash;
    private string $passwordHash;

    public function __construct(string $tokenHash, string $passwordHash)
    {
        $this->tokenHash = $tokenHash;
        $this->passwordHash = $passwordHash;
    }

    public function checkToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($this->tokenHash, hash('sha256', $token));
    }

    public function checkPassword(?string $password): bool
    {
        if ($password === null || $password === '') {
            return false;
        }

        return password_verify($password, $this->passwordHash);
    }

    public function isSessionAuthenticated(array $session): bool
    {
        return !empty($session['deployer_authenticated']) && $session['deployer_authenticated'] === true;
    }

    public function markSessionAuthenticated(array &$session): void
    {
        $session['deployer_authenticated'] = true;
    }

    /**
     * True if either a valid token header or an authenticated session is present.
     */
    public function isAuthorized(?string $tokenHeader, array $session): bool
    {
        if ($this->checkToken($tokenHeader)) {
            return true;
        }

        return $this->isSessionAuthenticated($session);
    }
}
