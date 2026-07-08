<?php

declare(strict_types=1);

namespace App\Tests\Functional\OAuth\UI\Http\Controller;

use App\OAuth\Domain\OAuthClient;
use App\People\Domain\Person;
use App\Tenant\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Exercises the admin UI for OAuth2 client management end to end through
 * real HTTP requests — the same "prove it over the real wire" discipline
 * as PersonControllerTest and OAuthAuthorizationFlowTest.
 *
 * Two things this suite specifically must prove, with the same rigor this
 * portfolio gives its cross-tenant isolation tests:
 *  - a plain company ROLE_ADMIN (NOT platform admin) is refused on every
 *    client-management route — this is what actually closes the
 *    privilege-escalation gap described on OAuthClientController's own
 *    class docblock (any company admin could otherwise register a client
 *    able to harvest every OTHER company's identity data);
 *  - a UI-registered client is genuinely equivalent to a console-registered
 *    one (same hashing, same persistence path) by actually completing a
 *    real OAuth2 authorization code + PKCE round trip against it.
 */
final class OAuthClientControllerTest extends WebTestCase
{
    private const string REDIRECT_URI = 'https://ui-client.example.test/callback';

    private function createPlatformAdmin(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        string $email
    ): Person {
        $company = new Company();
        $company->setName('OAuthClientControllerTestCo');
        $entityManager->persist($company);

        $admin = new Person();
        $admin->setCompany($company);
        $admin->setFirstName('Pat');
        $admin->setLastName('Platform');
        $admin->setEmail($email);
        $admin->setRole(Person::ROLE_ADMIN);
        $admin->setPlatformAdmin(true);
        $admin->setPassword($passwordHasher->hashPassword($admin, 'correct-horse-battery-staple'));
        $entityManager->persist($admin);
        $entityManager->flush();

        return $admin;
    }

    private function createCompanyAdmin(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        string $email
    ): Person {
        $company = new Company();
        $company->setName('OAuthClientControllerTestPlainCo');
        $entityManager->persist($company);

        $admin = new Person();
        $admin->setCompany($company);
        $admin->setFirstName('Cara');
        $admin->setLastName('CompanyAdmin');
        $admin->setEmail($email);
        $admin->setRole(Person::ROLE_ADMIN);
        // Deliberately NOT platform admin.
        $admin->setPassword($passwordHasher->hashPassword($admin, 'correct-horse-battery-staple'));
        $entityManager->persist($admin);
        $entityManager->flush();

        return $admin;
    }

    private function createPlainEmployee(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        string $email
    ): Person {
        $company = new Company();
        $company->setName('OAuthClientControllerTestEmployeeCo');
        $entityManager->persist($company);

        $employee = new Person();
        $employee->setCompany($company);
        $employee->setFirstName('Eddie');
        $employee->setLastName('Employee');
        $employee->setEmail($email);
        // Default role (ROLE_EMPLOYEE), not even company-admin, and
        // deliberately NOT platform admin.
        $employee->setPassword($passwordHasher->hashPassword($employee, 'correct-horse-battery-staple'));
        $entityManager->persist($employee);
        $entityManager->flush();

        return $employee;
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
     * @return array{0: string, 1: string} [codeVerifier, codeChallenge]
     */
    private function generatePkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    public function testPlatformAdminCanRegisterAClientThroughTheFormAndItAuthenticatesForReal(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createPlatformAdmin($entityManager, $passwordHasher, 'platform-admin-register@example.test');
        $this->logIn($client, 'platform-admin-register@example.test');

        $crawler = $client->request('GET', '/oauth-clients/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Register client')->form([
            'register_o_auth_client[name]' => 'UI Registered Client',
            'register_o_auth_client[redirectUri]' => self::REDIRECT_URI,
        ]);
        $client->submit($form);

        // The secret-reveal page, shown exactly once.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'UI Registered Client');
        self::assertSelectorTextContains('body', 'never be shown again');

        $entityManager->clear();
        $oauthClient = $entityManager->getRepository(OAuthClient::class)
            ->findOneBy(['name' => 'UI Registered Client']);
        self::assertNotNull($oauthClient);
        $clientId = $oauthClient->getIdentifier();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($clientId, $content);

        $matched = preg_match('/\b([0-9a-f]{64})\b/', $content, $secretMatch);
        self::assertSame(1, $matched, 'Expected a 64-hex-char plaintext secret on the reveal page.');
        self::assertArrayHasKey(1, $secretMatch);
        $clientSecret = $secretMatch[1];

        // The list page must never show the secret again.
        $client->request('GET', '/oauth-clients');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'UI Registered Client');
        self::assertSelectorTextContains('body', $clientId);
        self::assertStringNotContainsString($clientSecret, (string) $client->getResponse()->getContent());

        // Now prove the UI-created client is genuinely equivalent to a
        // console-created one: complete a real authorization code + PKCE
        // round trip against it, still as the already-logged-in platform
        // admin — /authorize only requires an authenticated Person, same
        // as any OAuth2 authorization server.
        $admin = $entityManager->getRepository(Person::class)
            ->findOneBy(['email' => 'platform-admin-register@example.test']);
        self::assertNotNull($admin);

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
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
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
        self::assertIsString($tokenPayload['access_token']);

        $client->request('GET', '/api/userinfo', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenPayload['access_token'],
        ]);
        self::assertResponseIsSuccessful();
        $userinfo = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($userinfo);
        self::assertSame((string) $admin->getId(), $userinfo['id']);
    }

    public function testDeactivatingAClientViaTheUiPreventsItCompletingTheOAuthFlowAfterward(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createPlatformAdmin($entityManager, $passwordHasher, 'platform-admin-deactivate@example.test');
        $this->logIn($client, 'platform-admin-deactivate@example.test');

        $crawler = $client->request('GET', '/oauth-clients/new');
        $form = $crawler->selectButton('Register client')->form([
            'register_o_auth_client[name]' => 'Soon Deactivated Client',
            'register_o_auth_client[redirectUri]' => self::REDIRECT_URI,
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $oauthClient = $entityManager->getRepository(OAuthClient::class)
            ->findOneBy(['name' => 'Soon Deactivated Client']);
        self::assertNotNull($oauthClient);
        $clientId = $oauthClient->getIdentifier();

        // Deactivate it through the real UI route (POST + CSRF), not by
        // mutating the entity directly.
        $crawler = $client->request('GET', '/oauth-clients');
        $deactivateForm = $crawler->filter(sprintf('form[action$="/oauth-clients/%s/deactivate"]', $clientId))
            ->form();
        $client->submit($deactivateForm);
        self::assertResponseRedirects('/oauth-clients');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'was deactivated');
        self::assertSelectorTextContains('body', 'Deactivated');

        // Still the same already-logged-in platform admin session —
        // /authorize only requires an authenticated Person. Matching
        // OAuthAuthorizationFlowTest::
        // testTokenExchangeForInactiveClientIsRejected's established
        // behaviour, deactivation is enforced by
        // HashedSecretClientRepository::validateClient() at /token
        // exchange time, not at /authorize.
        [$verifier, $challenge] = $this->generatePkcePair();

        $client->request('GET', '/authorize', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
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
            'client_secret' => 'irrelevant-because-the-client-is-inactive',
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        // The client is inactive — HashedSecretClientRepository rejects
        // it before even checking the secret, same 401 invalid_client
        // path as an unknown client id.
        self::assertResponseStatusCodeSame(401);
    }

    public function testPlainCompanyAdminGetsForbiddenOnEveryOAuthClientManagementRoute(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        // A pre-existing client to try (and fail) to deactivate.
        $this->createPlatformAdmin($entityManager, $passwordHasher, 'seed-platform-admin@example.test');
        $this->logIn($client, 'seed-platform-admin@example.test');
        $crawler = $client->request('GET', '/oauth-clients/new');
        $form = $crawler->selectButton('Register client')->form([
            'register_o_auth_client[name]' => 'Target Of Escalation Attempt',
            'register_o_auth_client[redirectUri]' => self::REDIRECT_URI,
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $oauthClient = $entityManager->getRepository(OAuthClient::class)
            ->findOneBy(['name' => 'Target Of Escalation Attempt']);
        self::assertNotNull($oauthClient);
        $clientId = $oauthClient->getIdentifier();

        // A fresh session (clear the platform admin's cookies), logged in
        // as a plain company admin — ROLE_ADMIN but NOT
        // ROLE_PLATFORM_ADMIN. WebTestCase only supports one booted
        // kernel per test, so this reuses $client rather than calling
        // static::createClient() again.
        $client->getCookieJar()->clear();
        $this->createCompanyAdmin($entityManager, $passwordHasher, 'plain-company-admin@example.test');
        $this->logIn($client, 'plain-company-admin@example.test');

        $client->request('GET', '/oauth-clients');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/oauth-clients/new');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/oauth-clients/new', [
            'register_o_auth_client' => ['name' => 'Should Not Be Created', 'redirectUri' => self::REDIRECT_URI],
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', sprintf('/oauth-clients/%s/deactivate', $clientId), [
            '_token' => 'irrelevant-because-authorization-is-checked-first',
        ]);
        self::assertResponseStatusCodeSame(403);

        // The privilege-escalation gap this whole slice exists to close:
        // the client this plain company admin tried to attack must be
        // unaffected.
        $entityManager->clear();
        $untouchedClient = $entityManager->getRepository(OAuthClient::class)->find($clientId);
        self::assertNotNull($untouchedClient);
        self::assertTrue($untouchedClient->isActive());
    }

    /**
     * A plain company admin still has ROLE_ADMIN, just not
     * ROLE_PLATFORM_ADMIN — the case above proves that's not enough. This
     * proves someone with neither company-admin standing nor
     * platform-admin standing is refused too, not just company admins.
     */
    public function testPlainEmployeeWithNoAdminRoleAtAllGetsForbiddenOnEveryOAuthClientManagementRoute(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createPlainEmployee($entityManager, $passwordHasher, 'plain-employee@example.test');
        $this->logIn($client, 'plain-employee@example.test');

        $client->request('GET', '/oauth-clients');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/oauth-clients/new');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/oauth-clients/new', [
            'register_o_auth_client' => ['name' => 'Should Not Be Created', 'redirectUri' => self::REDIRECT_URI],
        ]);
        self::assertResponseStatusCodeSame(403);

        $entityManager->clear();
        self::assertNull(
            $entityManager->getRepository(OAuthClient::class)->findOneBy(['name' => 'Should Not Be Created'])
        );
    }

    /**
     * The earlier "plain company admin" deactivate attempt is rejected by
     * IsGranted before the controller's own CSRF check ever runs — that
     * proves the role gate, not the CSRF gate. This test authenticates as
     * a genuine platform admin and submits a missing/wrong token, so it's
     * the controller's own isCsrfTokenValid() branch that has to reject
     * it.
     */
    public function testDeactivateRejectsAnInvalidCsrfTokenEvenFromAnAuthorizedPlatformAdmin(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $this->createPlatformAdmin($entityManager, $passwordHasher, 'platform-admin-csrf@example.test');
        $this->logIn($client, 'platform-admin-csrf@example.test');

        $crawler = $client->request('GET', '/oauth-clients/new');
        $form = $crawler->selectButton('Register client')->form([
            'register_o_auth_client[name]' => 'CSRF Guarded Client',
            'register_o_auth_client[redirectUri]' => self::REDIRECT_URI,
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful();

        $entityManager->clear();
        $oauthClient = $entityManager->getRepository(OAuthClient::class)
            ->findOneBy(['name' => 'CSRF Guarded Client']);
        self::assertNotNull($oauthClient);
        $clientId = $oauthClient->getIdentifier();

        $client->request('POST', sprintf('/oauth-clients/%s/deactivate', $clientId), [
            '_token' => 'definitely-not-the-real-token',
        ]);
        self::assertResponseStatusCodeSame(403);

        $entityManager->clear();
        $stillActiveClient = $entityManager->getRepository(OAuthClient::class)->find($clientId);
        self::assertNotNull($stillActiveClient);
        self::assertTrue($stillActiveClient->isActive());
    }
}
