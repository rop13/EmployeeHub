<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant\Domain;

use App\People\Domain\Person;
use App\Tenant\Domain\Company;
use PHPUnit\Framework\TestCase;

final class CompanyTest extends TestCase
{
    public function testAddPersonSetsTheInverseSideToo(): void
    {
        $company = new Company();
        $person = new Person();

        $company->addPerson($person);

        self::assertTrue($company->getPeople()->contains($person));
        self::assertSame($company, $person->getCompany());
    }

    public function testToStringReturnsTheName(): void
    {
        $company = new Company();
        $company->setName('Acme Inc.');

        self::assertSame('Acme Inc.', (string) $company);
    }
}
