<?php

declare(strict_types=1);

namespace App\OAuth\Application\Service;

use App\OAuth\Domain\OAuthClient;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Component\Uid\Uuid;

/**
 * The single place client-creation logic lives: identifier/secret
 * generation, hashing, grant/PKCE policy, and persistence. Both
 * RegisterOAuthClientCommand (console, Slice 2) and OAuthClientController
 * (UI, Slice 3) call this instead of duplicating it — so a
 * console-registered client and a UI-registered client are, by
 * construction, identical in every way that matters (hashing scheme,
 * allowed grants, PKCE enforcement).
 */
final class RegisterOAuthClientService
{
    public function __construct(
        private readonly ClientManagerInterface $clientManager,
    ) {
    }

    /**
     * @throws \RuntimeException if the redirect URI is empty or invalid —
     *  see League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri
     */
    public function register(string $name, string $redirectUriValue): RegisteredOAuthClient
    {
        if ($redirectUriValue === '') {
            throw new \RuntimeException('The redirect URI must not be empty.');
        }

        $redirectUri = new RedirectUri($redirectUriValue);

        $identifier = Uuid::v4()->toRfc4122();
        $plainSecret = bin2hex(random_bytes(32));
        // password_hash() with PASSWORD_BCRYPT only ever returns a string
        // (or throws) on PHP 8.4 — no false-return branch to guard here.
        $hashedSecret = password_hash($plainSecret, PASSWORD_BCRYPT);

        $client = new OAuthClient($name, $identifier, $hashedSecret);
        $client->setActive(true);
        // Never allowed: this app requires the S256 PKCE challenge method
        // for every client (see AutoApproveAuthorizationListener).
        $client->setAllowPlainTextPkce(false);
        $client->setRedirectUris($redirectUri);
        $client->setGrants(
            new Grant(OAuth2Grants::AUTHORIZATION_CODE),
            new Grant(OAuth2Grants::REFRESH_TOKEN),
        );

        $this->clientManager->save($client);

        return new RegisteredOAuthClient($client, $plainSecret);
    }
}
