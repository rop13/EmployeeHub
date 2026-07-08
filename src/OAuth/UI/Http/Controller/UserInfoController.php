<?php

declare(strict_types=1);

namespace App\OAuth\UI\Http\Controller;

use App\People\Domain\Person;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The resource endpoint a client calls once, after obtaining an access
 * token, to learn who the user is (id/name/company/role) — the plan's
 * "userinfo endpoint instead of custom JWT claims" decision (see Slice 2
 * plan) in place of overriding the token library's internal JWT
 * construction to embed custom claims.
 *
 * Protected by the "api" firewall's oauth2 authenticator
 * (config/packages/security.yaml) — reaching this action at all already
 * means a valid, unexpired bearer token was presented. That firewall's
 * user provider is the same "people" provider Slice 1's login uses, so
 * $this->getUser() here returns the Person the token was issued for, not
 * a league-specific "resource owner" type — no separate bridge class is
 * needed for this direction (only OAuth2Authenticator's own
 * UserProviderInterface call is involved, which Person already satisfies
 * via Slice 1's UserInterface implementation).
 */
final class UserInfoController extends AbstractController
{
    #[Route('/api/userinfo', name: 'oauth2_userinfo', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof Person) {
            // Invariant, not a real runtime branch: the "api" firewall's
            // provider only ever loads Person instances. Fails loudly
            // instead of returning a bogus payload if that's ever broken.
            throw new \LogicException('The OAuth2-authenticated user is not a Person.');
        }

        $company = $user->getCompany();

        return new JsonResponse([
            'id' => (string) $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'company' => $company === null ? null : [
                'id' => (string) $company->getId(),
                'name' => $company->getName(),
            ],
            'role' => $user->getRole(),
        ]);
    }
}
