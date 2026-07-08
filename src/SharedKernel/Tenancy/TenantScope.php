<?php

declare(strict_types=1);

namespace App\SharedKernel\Tenancy;

use App\Tenant\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Enables/disables the company-scoping Doctrine filter. A thin wrapper so
 * callers (the auth request listener, console commands, tests) share one
 * place that knows the filter's name and parameter type — not duplicated
 * string literals scattered across the app.
 *
 * Public: nothing autowires it yet besides TenantScopeRequestListener, so
 * tests need to fetch it directly.
 */
#[Autoconfigure(public: true)]
final class TenantScope
{
    public const string FILTER_NAME = 'company_filter';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws \LogicException if $company has no id (unpersisted) — failing
     *     loudly here is far clearer than letting an empty/malformed value
     *     reach CompanyFilter, which would surface as an opaque "invalid
     *     UUID syntax" error from Postgres on the next query instead.
     */
    public function enableFor(Company $company): void
    {
        $companyId = $company->getId();
        if ($companyId === null) {
            throw new \LogicException('Cannot scope to a Company with no id (it must be persisted first).');
        }

        $this->entityManager->getFilters()
            ->enable(self::FILTER_NAME)
            ->setParameter('companyId', $companyId, 'uuid');
    }

    public function disable(): void
    {
        if ($this->entityManager->getFilters()->isEnabled(self::FILTER_NAME)) {
            $this->entityManager->getFilters()->disable(self::FILTER_NAME);
        }
    }
}
