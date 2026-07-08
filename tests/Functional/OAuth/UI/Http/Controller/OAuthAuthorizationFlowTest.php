<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth\UI\Http\Controller;

use App\OAuth\Domain\OAuthClient;
use App\People\Domain\Person;
use App\Tenant\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken as AccessTokenModel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Exercises the real HTTP request cycle end to end — register a client,
 * log a Person in, drive /authorize -> PKCE code exchange at /token ->
 * GET /api/userinfo with the resulting access token — with the same
 * "prove it over the real wire, not just unit-style" discipline as
 * PersonControllerTest, and the same rigor this portfolio gives its
 * cross-tenant isolation tests, because this is the highest-stakes slice
 * built here so far: an actual authorization server.
 */
final class OAuthAuthorizationFlowTest extends WebTestCase
{
    private const string REDIRECT_URI = 'https://client.example.test/callback';

    private function createAdmin(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        string $email
    ): Person {
        $company = new Company();
        $company->setName('OAuthFlowTestCo');
        $entityManager->persist($company);

        $admin = new Person();
        $admin->setCompany($company);
        $admin->setFirstName('Oona');
        $admin->setLastName('Auth');
        $admin->setEmail($email);
        $admin->setRole(Person::ROLE_ADMIN);
        $admin->setPassword($passwordHasher->hashPassword($admin, 'correct-horse-battery-staple'));
        $entityManager->persist($admin);
        $entityManager->flush();

        return $admin;
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Log in')->form([
            '_username' => $email,
            '_password' => 'correct-horse-battery-staple',
        ]);
        $client->submit($form);
    }

    /**
     * Registers a client through the real console command (not a
     * shortcut fixture) so this test also proves
     * app:register-oauth-client actually works end to end. Returns
     * [clientId, plaintextSecret] parsed out of the command's one-time
     * printed table.
     *
     * @return array{0: string, 1: string}
     */
    private function registerClientViaCommand(
        KernelBrowser $client,
        string $name,
        string $redirectUri = self::REDIRECT_URI
    ): array {
        $application = new Application($client->getKernel());
        $command = $application->find('app:register-oauth-client');
        $commandTester = new CommandTester($command);
        $commandTester->execute(['name' => $name, 'redirect-uri' => $redirectUri]);

        $output = $commandTester->getDisplay();

        self::assertSame(0, $commandTester->getStatusCode());
        self::assertMatchesRegularExpression('/[0-9a-f-]{36}/', $output);

        preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $output, $idMatch);
        preg_match('/\b[0-9a-f]{64}\b/', $output, $secretMatch);

        if (!isset($idMatch[0])) {
            self::fail('Expected a UUID client id in the command output.');
        }
        if (!isset($secretMatch[0])) {
            self::fail('Expected a 64-hex-char plaintext secret in the command output.');
        }

        return [$idMatch[0], $secretMatch[0]];
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function extractStringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        self::assertIsString($value, sprintf('Expected "%s" to be a string in the response payload.', $key));

        return $value;
    }

    /**
     * @return array{0: string, 1: string} [codeVerifier, codeChallenge]
     */
    private function generatePkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    public function testFullAuthorizationCodeFlowWithPkceReturnsTheLoggedInPersonsIdentity(): void
    {
        $client = static::createClient();
        [$clientId, $clientSecret] = $this->registerClientViaCommand($client, 'Performance Reviews');

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $admin = $this->createAdmin($entityManager, $passwordHasher, 'oauth-fulltest@example.test');
        $company = $admin->getCompany();
        self::assertNotNull($company);

        $this->logIn($client, 'oauth-fulltest@example.test');

        [$verifier, $challenge] = $this->generatePkcePair();

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'userinfo',
            'state' => 'xyz-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringStartsWith(self::REDIRECT_URI, $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('code', $query);
        self::assertSame('xyz-state', $query['state']);
        $code = $query['code'];

        $client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        self::assertResponseIsSuccessful();
        $tokenPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($tokenPayload);
        $accessToken = $this->extractStringField($tokenPayload, 'access_token');

        $client->request('GET', '/api/userinfo', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
        ]);

        self::assertResponseIsSuccessful();
        $userinfo = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($userinfo);
        self::assertSame((string) $admin->getId(), $this->extractStringField($userinfo, 'id'));
        self::assertSame('Oona', $this->extractStringField($userinfo, 'firstName'));
        self::assertSame('Auth', $this->extractStringField($userinfo, 'lastName'));
        self::assertSame('oauth-fulltest@example.test', $this->extractStringField($userinfo, 'email'));
        self::assertSame(Person::ROLE_ADMIN, $this->extractStringField($userinfo, 'role'));
        $companyPayload = $userinfo['company'];
        self::assertIsArray($companyPayload);
        self::assertSame((string) $company->getId(), $this->extractStringField($companyPayload, 'id'));
        self::assertSame('OAuthFlowTestCo', $this->extractStringField($companyPayload, 'name'));
    }

    public function testAuthorizeWithoutPkceCodeChallengeIsRejected(): void
    {
        $redirectUri = 'https://no-pkce.example.test/callback';
        $client = static::createClient();
        [$clientId] = $this->registerClientViaCommand($client, 'No PKCE Client', $redirectUri);

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createAdmin($entityManager, $passwordHasher, 'oauth-nopkce@example.test');
        $this->logIn($client, 'oauth-nopkce@example.test');

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'userinfo',
            'state' => 'xyz',
            // No code_challenge at all — AutoApproveAuthorizationListener
            // must reject this even though this client is confidential
            // (the underlying library only enforces PKCE for public
            // clients by default).
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTokenExchangeWithWrongPkceVerifierIsRejected(): void
    {
        $redirectUri = 'https://wrong-verifier.example.test/callback';
        $client = static::createClient();
        [$clientId, $clientSecret] = $this->registerClientViaCommand($client, 'Wrong Verifier Client', $redirectUri);

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createAdmin($entityManager, $passwordHasher, 'oauth-wrongverifier@example.test');
        $this->logIn($client, 'oauth-wrongverifier@example.test');

        [, $challenge] = $this->generatePkcePair();
        [$wrongVerifier] = $this->generatePkcePair();

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'userinfo',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $code = $query['code'];

        $client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $wrongVerifier,
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAuthorizeWithMismatchedRedirectUriIsRejected(): void
    {
        $client = static::createClient();
        [$clientId] = $this->registerClientViaCommand(
            $client,
            'Redirect Mismatch Client',
            'https://registered.example.test/callback'
        );

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createAdmin($entityManager, $passwordHasher, 'oauth-badredirect@example.test');
        $this->logIn($client, 'oauth-badredirect@example.test');

        [, $challenge] = $this->generatePkcePair();

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => 'https://attacker.example.test/callback',
            'scope' => 'userinfo',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        // Per RFC 6749 ss4.1.2.1: an invalid redirect_uri must NOT
        // redirect the browser back to it (that would itself be an
        // open-redirect vector) — the authorization server renders the
        // error directly instead (league/oauth2-server's
        // AbstractGrant::validateRedirectUri() raises this as
        // OAuthServerException::invalidClient(), hence 401 rather than a
        // generic 400).
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($client->getResponse()->isRedirect());
    }

    public function testAuthorizationCodeCannotBeReusedAfterExchange(): void
    {
        $redirectUri = 'https://replay.example.test/callback';
        $client = static::createClient();
        [$clientId, $clientSecret] = $this->registerClientViaCommand($client, 'Replay Client', $redirectUri);

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createAdmin($entityManager, $passwordHasher, 'oauth-replay@example.test');
        $this->logIn($client, 'oauth-replay@example.test');

        [$verifier, $challenge] = $this->generatePkcePair();

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'userinfo',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $code = $query['code'];

        $tokenParameters = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $verifier,
        ];

        $client->request('POST', '/token', $tokenParameters);
        self::assertResponseIsSuccessful();

        // Same code, second time — must be rejected.
        $client->request('POST', '/token', $tokenParameters);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAuthorizeWithUnknownClientIdIsRejected(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createAdmin($entityManager, $passwordHasher, 'oauth-unknownclient@example.test');
        $this->logIn($client, 'oauth-unknownclient@example.test');

        [, $challenge] = $this->generatePkcePair();

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => 'does-not-exist-client-id',
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'userinfo',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        // league/oauth2-server's OAuthServerException::invalidClient()
        // responds 401 (RFC 6749 ss5.2's "invalid_client" case), not a
        // generic 400 — an unknown client fails the same way a
        // recognized-but-wrong-secret client would.
        self::assertResponseStatusCodeSame(401);
    }

    public function testTokenExchangeForInactiveClientIsRejected(): void
    {
        $redirectUri = 'https://inactive.example.test/callback';
        $client = static::createClient();
        [$clientId, $clientSecret] = $this->registerClientViaCommand($client, 'Soon Inactive Client', $redirectUri);

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createAdmin($entityManager, $passwordHasher, 'oauth-inactive@example.test');
        $this->logIn($client, 'oauth-inactive@example.test');

        [$verifier, $challenge] = $this->generatePkcePair();

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'userinfo',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $code = $query['code'];

        // Deactivate the client after the code was issued but before it's
        // exchanged — e.g. an operator revoking a compromised client.
        $oauthClient = $entityManager->getRepository(OAuthClient::class)->findOneBy(['identifier' => $clientId]);
        self::assertNotNull($oauthClient);
        $oauthClient->setActive(false);
        $entityManager->flush();

        $client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        // Same invalid_client (401) path as an unknown client id —
        // HashedSecretClientRepository::validateClient() rejects any
        // inactive client before it even looks at the secret.
        self::assertResponseStatusCodeSame(401);
    }

    public function testUserinfoRejectsMissingBearerToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/userinfo');

        self::assertResponseStatusCodeSame(401);
    }

    public function testUserinfoRejectsMalformedBearerToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/userinfo', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer not-a-real-jwt',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUserinfoRejectsARevokedAccessToken(): void
    {
        $redirectUri = 'https://revoked.example.test/callback';
        $client = static::createClient();
        [$clientId, $clientSecret] = $this->registerClientViaCommand($client, 'Revocation Client', $redirectUri);

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createAdmin($entityManager, $passwordHasher, 'oauth-revoked@example.test');
        $this->logIn($client, 'oauth-revoked@example.test');

        [$verifier, $challenge] = $this->generatePkcePair();

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'userinfo',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $code = $query['code'];

        $client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
        self::assertResponseIsSuccessful();
        $tokenPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($tokenPayload);
        $accessToken = $this->extractStringField($tokenPayload, 'access_token');

        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/userinfo', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
        ]);
        self::assertResponseIsSuccessful();

        // The access token is a self-contained signed JWT — its own
        // embedded expiry can't be altered after issuance without
        // waiting in real wall-clock time. Revocation is the other
        // documented rejection path the resource server actually checks
        // against the database (see BearerTokenValidator::
        // validateAuthorization()'s isAccessTokenRevoked() call) — this
        // is the practical, deterministic equivalent of "this token must
        // no longer be honoured" that this test exercises.
        $storedTokens = $entityManager->getRepository(AccessTokenModel::class)->findAll();
        self::assertNotEmpty($storedTokens);
        foreach ($storedTokens as $storedToken) {
            $storedToken->revoke();
        }
        $entityManager->flush();
        $entityManager->clear();

        $client->request('GET', '/api/userinfo', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
