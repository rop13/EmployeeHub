<?php

declare(strict_types=1);

namespace App\OAuth\Domain;

use App\OAuth\Infrastructure\Persistence\Doctrine\OAuthClientRepository;
use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;

/**
 * A platform-level registration of a *consuming application* (e.g. a
 * future "Performance Reviews" client) — deliberately NOT
 * TenantOwnedInterface and not related to Company. EmployeeHub itself is
 * the shared identity provider here; a client isn't owned by any one
 * tenant, so it sits outside the tenant-scoping mechanism entirely (see
 * Slice 2 plan's "Data model" section).
 *
 * Extends league/oauth2-server-bundle's own League\Bundle\
 * OAuth2ServerBundle\Model\AbstractClient per its documented extension
 * point (docs/using-custom-client.md), confirmed against the installed
 * bundle's source rather than assumed: name, redirect URIs (exact-match
 * only — see RegisterOAuthClientCommand), grants, scopes, and the active
 * flag are all inherited from AbstractClient. This subclass only adds:
 *  - the Doctrine entity mapping (AbstractClient is a mapped superclass,
 *    not directly persistable — its inherited-field mapping comes from
 *    the bundle's own internal metadata driver; only `identifier` needs
 *    an explicit column here, per the bundle's docs example);
 *  - a wider identifier column to hold a UUID-based client id instead of
 *    the bundle's own default md5-hash-based one.
 *
 * Client secrets are stored HASHED (password_hash), not compared via the
 * bundle's own default plaintext hash_equals() scheme — confirmed from
 * the installed bundle's League\Bundle\OAuth2ServerBundle\Repository\
 * ClientRepository source, which compares the stored secret directly
 * against the presented one. See
 * App\OAuth\Infrastructure\Security\HashedSecretClientRepository (which
 * replaces that default) for the corresponding password_verify()-based
 * validation, and RegisterOAuthClientCommand for where hashing happens.
 */
#[ORM\Entity(repositoryClass: OAuthClientRepository::class)]
#[ORM\Table(name: 'oauth_client')]
class OAuthClient extends AbstractClient
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    protected string $identifier;
}
