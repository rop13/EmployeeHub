<?php

declare(strict_types=1);

namespace App\OAuth\Application\Command;

use App\OAuth\Domain\OAuthClient;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Registers a first-party OAuth2 client (e.g. a future "Performance
 * Reviews" app) for the Authorization Code + PKCE flow. No admin UI for
 * client registration yet — that's Slice 3 — so, mirroring
 * App\Tenant\Application\Command\SeedCompanyCommand's single-purpose,
 * operator-run pattern, this command is the only way to register one for
 * now.
 *
 * Only the authorization_code and refresh_token grants are ever
 * registered for a client here — this app never issues client_credentials
 * or password-grant clients, and the authorization_server config
 * (config/packages/league_oauth2_server.yaml) disables those grant types
 * globally too, as defense in depth.
 *
 * The plaintext client secret is shown here once — only its
 * password_hash() is persisted (see OAuthClient's class docblock and
 * App\OAuth\Infrastructure\Security\HashedSecretClientRepository) — the
 * same one-time-reveal UX real OAuth providers use for client secrets.
 */
#[AsCommand(
    name: 'app:register-oauth-client',
    description: 'Register a new first-party OAuth2 client and print its one-time secret',
)]
final class RegisterOAuthClientCommand extends Command
{
    public function __construct(
        private readonly ClientManagerInterface $clientManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The client name (e.g. "Performance Reviews")')
            ->addArgument('redirect-uri', InputArgument::REQUIRED, 'The exact redirect URI this client will use');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $this->requiredStringArgument($input, 'name');
        $redirectUriArgument = $this->requiredStringArgument($input, 'redirect-uri');

        try {
            $redirectUri = new RedirectUri($redirectUriArgument);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

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

        $io->success(sprintf('Registered OAuth2 client "%s".', $name));
        $io->table(
            ['Client ID', 'Client secret (shown once — save it now)'],
            [[$identifier, $plainSecret]]
        );
        $io->warning('This secret cannot be recovered after this. Re-register the client if it is lost.');

        return Command::SUCCESS;
    }

    /**
     * @return non-empty-string
     */
    private function requiredStringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(sprintf('Argument "%s" must be a non-empty string.', $name));
        }

        return $value;
    }
}
