<?php

declare(strict_types=1);

namespace App\OAuth\Infrastructure\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * league/oauth2-server-bundle's own client-creation/update console
 * commands store secrets in plaintext, which
 * HashedSecretClientRepository's password_verify() check can never
 * match — a client registered or updated through either of these would
 * be permanently unable to authenticate, silently.
 * app:register-oauth-client is the only supported way to create a
 * client.
 *
 * Clearing just the console.command tag (rather than redefining the
 * service in services.yaml) keeps the bundle's own bound constructor
 * arguments — e.g. $clientFqcn — intact; a full redefinition breaks
 * autowiring on those scalar arguments.
 *
 * The bundle registers these under its own internal service ids
 * (confirmed via `bin/console debug:container`), not their class name —
 * the class name is only a private alias, which
 * ContainerBuilder::hasDefinition() does not resolve.
 */
final class DisableUnsafeClientCommandsPass implements CompilerPassInterface
{
    private const COMMAND_SERVICE_IDS = [
        'league.oauth2_server.command.create_client',
        'league.oauth2_server.command.update_client',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::COMMAND_SERVICE_IDS as $serviceId) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $container->getDefinition($serviceId)->clearTag('console.command');
        }
    }
}
