<?php

declare(strict_types=1);

namespace App\People\Infrastructure\Security;

use App\People\Domain\Person;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blocks login for a deactivated Person. Account-status checks like this
 * belong here (Symfony's dedicated hook for them), not baked into the user
 * loader — the loader's only job is finding the right row.
 */
final class PersonUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Person && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account has been deactivated.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
