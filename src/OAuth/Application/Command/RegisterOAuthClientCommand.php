<?php

declare(strict_types=1);

namespace App\OAuth\Application\Command;

use App\OAuth\Application\Service\RegisterOAuthClientService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Registers a first-party OAuth2 client (e.g. a future "Performance
 * Reviews" app) for the Authorization Code + PKCE flow from the console.
 * Slice 3 added an equivalent admin UI
 * (App\OAuth\UI\Http\Controller\OAuthClientController), platform-admin-only
 * — both this command and that controller delegate the actual
 * id/secret/hashing/persistence work to RegisterOAuthClientService so the
 * two entry points can never drift.
 *
 * Only the authorization_code and refresh_token grants are ever
 * registered for a client — this app never issues client_credentials or
 * password-grant clients, and the authorization_server config
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
        private readonly RegisterOAuthClientService $registerOAuthClientService,
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
            $registered = $this->registerOAuthClientService->register($name, $redirectUriArgument);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Registered OAuth2 client "%s".', $name));
        $io->table(
            ['Client ID', 'Client secret (shown once — save it now)'],
            [[$registered->client->getIdentifier(), $registered->plainSecret]]
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
