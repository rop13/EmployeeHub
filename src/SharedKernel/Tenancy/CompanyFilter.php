<?php

declare(strict_types=1);

namespace App\SharedKernel\Tenancy;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Scopes every query against a TenantOwnedInterface entity to the current
 * request's company — mirrors LeaveFlow's
 * docs/adr/2026-07-06-multi-tenancy-strategy.md. Disabled by default
 * (config/packages/doctrine.yaml); enabled per-request once the
 * authenticated person's company is known.
 */
final class CompanyFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(TenantOwnedInterface::class)) {
            return '';
        }

        try {
            $companyId = $this->getParameter('companyId');
        } catch (\InvalidArgumentException) {
            // Enabled without a companyId set is a bug in the caller, not a
            // reason to silently return every tenant's rows — fail closed.
            return sprintf('1 = 0 /* %s: companyId parameter not set */', self::class);
        }

        return sprintf('%s.company_id = %s', $targetTableAlias, (string) $companyId);
    }
}
