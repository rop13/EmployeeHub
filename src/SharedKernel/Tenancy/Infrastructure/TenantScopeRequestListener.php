<?php

declare(strict_types=1);

namespace App\SharedKernel\Tenancy\Infrastructure;

use App\SharedKernel\Tenancy\TenantOwnedInterface;
use App\SharedKernel\Tenancy\TenantScope;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enables the tenant-scoping filter for the current request's authenticated
 * principal, on every request. This is what makes "authenticated app
 * requests are always scoped" actually true, rather than just documented.
 *
 * Depends on TenantOwnedInterface rather than a concrete principal class
 * (e.g. Person) so this stays SharedKernel's own generic activation
 * mechanism — any future principal type just needs to implement
 * TenantOwnedInterface to be scoped correctly, with no change here.
 *
 * Priority -10: low enough to run after the security firewall's own
 * kernel.request listeners have restored the token from the session, so
 * Security::getUser() is populated by the time this runs.
 *
 * Public: tests invoke it directly (calling __invoke() with a hand-built
 * RequestEvent) rather than dispatching a real kernel.request, which would
 * also trigger the unrelated access-control/firewall listeners that also
 * listen on that event.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: -10)]
#[Autoconfigure(public: true)]
final class TenantScopeRequestListener
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantScope $tenantScope,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof TenantOwnedInterface) {
            $this->tenantScope->disable();
            return;
        }

        $company = $user->getCompany();
        if ($company === null) {
            $this->tenantScope->disable();
            return;
        }

        $this->tenantScope->enableFor($company);
    }
}
