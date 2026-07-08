<?php

declare(strict_types=1);

namespace App\OAuth\Infrastructure\EventListener;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * No consent screen in this slice (see Slice 2 plan): every registered
 * client is first-party — registered directly by EmployeeHub's own
 * operator via app:register-oauth-client, not a third-party app a user
 * needs to be warned about — so once a Person is logged in (Slice 1's
 * existing login/access_control already forces that before /authorize is
 * ever reached), the authorization request is auto-approved here.
 *
 * PKCE (S256) is mandatory for every client, not just public ones.
 * league/oauth2-server's own default (the
 * `require_code_challenge_for_public_clients` config, which stays at its
 * default `true`) only enforces a code_challenge for non-confidential
 * clients — confirmed from the installed library's
 * vendor/league/oauth2-server/src/Grant/AuthCodeGrant.php, which guards
 * that check with `!$client->isConfidential()`. Since this app's clients
 * are always confidential (registered with a secret), that check alone
 * would never fire; this listener adds the same requirement for them too,
 * as defense in depth. (The 'plain' challenge method is separately
 * rejected by the bundle's own AuthorizationController unless a client
 * has allowPlainTextPkce set, which RegisterOAuthClientCommand never
 * sets — so S256-only is already guaranteed once a challenge is present.)
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final class AutoApproveAuthorizationListener
{
    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        if ($event->getCodeChallenge() === null) {
            throw OAuthServerException::invalidRequest(
                'code_challenge',
                'PKCE (code_challenge, S256) is required for every client.'
            );
        }

        $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);
    }
}
