<?php

namespace Deployer\Tests\Receiver;

use Deployer\Receiver\Auth;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private Auth $auth;
    private string $rawToken = 'super-secret-token';
    private string $rawPassword = 'correct horse battery staple';

    protected function setUp(): void
    {
        $this->auth = new Auth(
            hash('sha256', $this->rawToken),
            password_hash($this->rawPassword, PASSWORD_DEFAULT)
        );
    }

    public function testValidTokenIsAccepted(): void
    {
        $this->assertTrue($this->auth->checkToken($this->rawToken));
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->assertFalse($this->auth->checkToken('wrong-token'));
    }

    public function testMissingTokenIsRejected(): void
    {
        $this->assertFalse($this->auth->checkToken(null));
        $this->assertFalse($this->auth->checkToken(''));
    }

    public function testValidPasswordIsAccepted(): void
    {
        $this->assertTrue($this->auth->checkPassword($this->rawPassword));
    }

    public function testInvalidPasswordIsRejected(): void
    {
        $this->assertFalse($this->auth->checkPassword('wrong-password'));
    }

    public function testSessionAuthenticationRoundTrip(): void
    {
        $session = [];
        $this->assertFalse($this->auth->isSessionAuthenticated($session));

        $this->auth->markSessionAuthenticated($session);

        $this->assertTrue($this->auth->isSessionAuthenticated($session));
    }

    public function testIsAuthorizedAcceptsValidToken(): void
    {
        $this->assertTrue($this->auth->isAuthorized($this->rawToken, []));
    }

    public function testIsAuthorizedAcceptsAuthenticatedSession(): void
    {
        $session = ['deployer_authenticated' => true];
        $this->assertTrue($this->auth->isAuthorized(null, $session));
    }

    public function testIsAuthorizedRejectsNeither(): void
    {
        $this->assertFalse($this->auth->isAuthorized(null, []));
        $this->assertFalse($this->auth->isAuthorized('wrong', []));
    }
}
