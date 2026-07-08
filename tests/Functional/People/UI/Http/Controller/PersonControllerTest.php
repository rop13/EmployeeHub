<?php

declare(strict_types=1);

namespace App\Tests\Functional\People\UI\Http\Controller;

use App\People\Domain\Person;
use App\Tenant\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Exercises the real HTTP request cycle end to end (login -> authenticated
 * page -> form submission), which is what actually proves
 * TenantScopeRequestListener's event-listener priority is correct in
 * practice — the unit-style test that invokes it directly can't catch a
 * future reordering relative to the security firewall's own listeners.
 */
final class PersonControllerTest extends WebTestCase
{
    private function createAdmin(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        string $companyName,
        string $email
    ): Person {
        $company = new Company();
        $company->setName($companyName);
        $entityManager->persist($company);

        $admin = new Person();
        $admin->setCompany($company);
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setEmail($email);
        $admin->setRole(Person::ROLE_ADMIN);
        $admin->setPassword($passwordHasher->hashPassword($admin, 'correct-horse-battery-staple'));
        $entityManager->persist($admin);

        $entityManager->flush();

        return $admin;
    }

    private function createPerson(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        Company $company,
        string $email
    ): Person {
        $person = new Person();
        $person->setCompany($company);
        $person->setFirstName('Plain');
        $person->setLastName('Person');
        $person->setEmail($email);
        $person->setPassword($passwordHasher->hashPassword($person, 'correct-horse-battery-staple'));
        $entityManager->persist($person);
        $entityManager->flush();

        return $person;
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

    public function testAdminCanAddAPersonAndOnlySeesTheirOwnCompanysPeople(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $adminA = $this->createAdmin(
            $entityManager,
            $passwordHasher,
            'PersonControllerTestAlpha',
            'admin-alpha-pctest@example.test'
        );
        $companyA = $adminA->getCompany();
        self::assertNotNull($companyA);
        $this->createPerson($entityManager, $passwordHasher, $companyA, 'existing-alpha-pctest@example.test');

        $adminB = $this->createAdmin(
            $entityManager,
            $passwordHasher,
            'PersonControllerTestBeta',
            'admin-beta-pctest@example.test'
        );
        $companyB = $adminB->getCompany();
        self::assertNotNull($companyB);
        $this->createPerson($entityManager, $passwordHasher, $companyB, 'existing-beta-pctest@example.test');

        $this->logIn($client, 'admin-alpha-pctest@example.test');

        // The people list, reached through a real authenticated HTTP
        // request, must show only company Alpha's people.
        $client->request('GET', '/people');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'existing-alpha-pctest@example.test');
        self::assertSelectorTextNotContains('body', 'existing-beta-pctest@example.test');
        self::assertSelectorTextNotContains('body', 'admin-beta-pctest@example.test');

        // Adding a new person attaches them to the acting admin's own
        // company, not some other one.
        $crawler = $client->request('GET', '/people/new');
        $form = $crawler->selectButton('Add person')->form([
            'person[firstName]' => 'New',
            'person[lastName]' => 'Hire',
            'person[email]' => 'new-hire-pctest@example.test',
            'person[role]' => Person::ROLE_EMPLOYEE,
            'person[plainPassword]' => 'a-temporary-password',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/people');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'New Hire was added.');
        self::assertSelectorTextContains('body', 'new-hire-pctest@example.test');

        $entityManager->clear();
        $newHire = $entityManager->getRepository(Person::class)
            ->findOneBy(['email' => 'new-hire-pctest@example.test']);
        self::assertNotNull($newHire);
        self::assertSame((string) $companyA->getId(), (string) $newHire->getCompany()?->getId());
    }

    public function testAddingAPersonWithABlankPasswordShowsAnInlineErrorInsteadOfCrashing(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $admin = $this->createAdmin(
            $entityManager,
            $passwordHasher,
            'PersonControllerTestDelta',
            'admin-delta-pctest@example.test'
        );
        $companyDelta = $admin->getCompany();
        self::assertNotNull($companyDelta);

        $this->logIn($client, 'admin-delta-pctest@example.test');

        $crawler = $client->request('GET', '/people/new');
        $form = $crawler->selectButton('Add person')->form([
            'person[firstName]' => 'New',
            'person[lastName]' => 'Hire',
            'person[email]' => 'blank-password-pctest@example.test',
            'person[role]' => Person::ROLE_EMPLOYEE,
            'person[plainPassword]' => '',
        ]);
        $client->submit($form);

        // 422, not 500 — the form re-renders with an inline error instead
        // of the controller's "unreachable" LogicException guard firing.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'should not be blank');

        $entityManager->clear();
        $notCreated = $entityManager->getRepository(Person::class)
            ->findOneBy(['email' => 'blank-password-pctest@example.test']);
        self::assertNull($notCreated);
    }

    public function testAPlainPersonCannotAccessThePeopleList(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $admin = $this->createAdmin(
            $entityManager,
            $passwordHasher,
            'PersonControllerTestGamma',
            'admin-gamma-pctest@example.test'
        );
        $companyGamma = $admin->getCompany();
        self::assertNotNull($companyGamma);
        $this->createPerson($entityManager, $passwordHasher, $companyGamma, 'plain-gamma-pctest@example.test');

        $this->logIn($client, 'plain-gamma-pctest@example.test');

        $client->request('GET', '/people');

        self::assertResponseStatusCodeSame(403);
    }
}
