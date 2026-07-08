<?php

declare(strict_types=1);

namespace App\SharedKernel\Tenancy;

use App\Tenant\Domain\Company;

/**
 * Marks an entity as belonging to exactly one Company. CompanyFilter only
 * scopes entities that implement this interface — an explicit allowlist
 * (mirroring LeaveFlow's docs/adr/2026-07-06-multi-tenancy-strategy.md), so
 * a new tenant-owned entity that forgets to implement it is unscoped by
 * omission rather than the filter silently doing nothing for a class it
 * doesn't recognize.
 */
interface TenantOwnedInterface
{
    public function getCompany(): ?Company;
}
