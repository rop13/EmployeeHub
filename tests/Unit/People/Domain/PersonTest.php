<?php

declare(strict_types=1);

namespace App\Tests\Unit\People\Domain;

use App\People\Domain\Person;
use PHPUnit\Framework\TestCase;

final class PersonTest extends TestCase
{
    public function testGetFullNameJoinsFirstAndLastName(): void
    {
        $person = new Person();
        $person->setFirstName('Ada');
        $person->setLastName('Lovelace');

        self::assertSame('Ada Lovelace', $person->getFullName());
    }

    public function testDefaultRoleIsEmployeeOnly(): void
    {
        $person = new Person();

        self::assertSame(['ROLE_EMPLOYEE'], $person->getRoles());
    }

    public function testAdminRoleAlsoGrantsEmployee(): void
    {
        $person = new Person();
        $person->setRole(Person::ROLE_ADMIN);

        self::assertSame(['ROLE_ADMIN', 'ROLE_EMPLOYEE'], $person->getRoles());
    }

    public function testUserIdentifierIsTheEmail(): void
    {
        $person = new Person();
        $person->setEmail('ada@example.test');

        self::assertSame('ada@example.test', $person->getUserIdentifier());
    }

    public function testUserIdentifierRefusesToReturnEmptyForAPersonWithNoEmail(): void
    {
        $person = new Person();

        $this->expectException(\LogicException::class);
        $person->getUserIdentifier();
    }

    public function testIsActiveDefaultsToTrue(): void
    {
        $person = new Person();

        self::assertTrue($person->isActive());
    }
}
