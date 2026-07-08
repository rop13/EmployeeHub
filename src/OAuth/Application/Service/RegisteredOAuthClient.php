<?php

declare(strict_types=1);

namespace App\OAuth\Application\Service;

use App\OAuth\Domain\OAuthClient;

/**
 * The result of RegisterOAuthClientService::register(): the persisted
 * client plus its plaintext secret, which exists only for this one return
 * value — nothing downstream of this ever stores or logs the plaintext,
 * matching the one-time-reveal discipline already established by
 * RegisterOAuthClientCommand.
 */
final readonly class RegisteredOAuthClient
{
    public function __construct(
        public OAuthClient $client,
        public string $plainSecret,
    ) {
    }
}
