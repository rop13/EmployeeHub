<?php

declare(strict_types=1);

namespace App\OAuth\Infrastructure\Security;

use League\Bundle\OAuth2ServerBundle\Entity\Client as LeagueClientEntity;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface as BundleClientInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * Replaces league/oauth2-server-bundle's own default client repository
 * (League\Bundle\OAuth2ServerBundle\Repository\ClientRepository) for
 * secret validation. Confirmed from the installed bundle's own source
 * (vendor/league/oauth2-server-bundle/src/Repository/ClientRepository.php):
 * its validateClient() does
 * `hash_equals((string) $client->getSecret(), (string) $clientSecret)` —
 * a direct compare against a PLAINTEXT stored secret. This app stores
 * client secrets hashed (see RegisterOAuthClientCommand), so that compare
 * would always fail; this class does the equivalent job with
 * password_verify() instead.
 *
 * Wired in as the League\OAuth2\Server\Repositories\
 * ClientRepositoryInterface implementation via config/services.yaml,
 * overriding the bundle's own service alias (which the app's own
 * config/services.yaml loads after config/packages/*.yaml, so this
 * override wins).
 */
final class HashedSecretClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        private readonly ClientManagerInterface $clientManager,
    ) {
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $client = $this->clientManager->find($clientIdentifier);

        if ($client === null) {
            return null;
        }

        return $this->buildClientEntity($client);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $client = $this->clientManager->find($clientIdentifier);

        if ($client === null || !$client->isActive() || !$this->isGrantSupported($client, $grantType)) {
            return false;
        }

        if (!$client->isConfidential()) {
            return true;
        }

        return $clientSecret !== null && password_verify($clientSecret, (string) $client->getSecret());
    }

    private function buildClientEntity(BundleClientInterface $client): LeagueClientEntity
    {
        $clientEntity = new LeagueClientEntity();
        $clientEntity->setName($client->getName());
        $clientEntity->setIdentifier($client->getIdentifier());
        $clientEntity->setRedirectUri(array_map('strval', $client->getRedirectUris()));
        $clientEntity->setConfidential($client->isConfidential());
        $clientEntity->setAllowPlainTextPkce($client->isPlainTextPkceAllowed());

        return $clientEntity;
    }

    private function isGrantSupported(BundleClientInterface $client, ?string $grant): bool
    {
        if ($grant === null) {
            return true;
        }

        $grants = $client->getGrants();

        if ($grants === []) {
            return true;
        }

        foreach ($grants as $registeredGrant) {
            if ((string) $registeredGrant === $grant) {
                return true;
            }
        }

        return false;
    }
}
