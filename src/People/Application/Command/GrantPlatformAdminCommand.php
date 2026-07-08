<?php

declare(strict_types=1);

namespace App\People\Application\Command;

use App\People\Infrastructure\Persistence\Doctrine\PersonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only way to grant platform-admin status — deliberately console-only,
 * never exposed in any UI or form (see Person::$platformAdmin's class
 * docblock for why: it's a platform-wide capability, orthogonal to the
 * existing company-scoped ROLE_ADMIN, and a company admin managing their
 * own company's people must never be able to grant it to themselves or
 * anyone else). Mirrors App\Tenant\Application\Command\
 * SeedCompanyCommand's single-purpose, operator-run pattern for other
 * rare/sensitive operations in this portfolio.
 *
 * Looks the person up by email via PersonRepository::loadUserByIdentifier()
 * rather than a plain findOneBy() so this command inherits the same
 * "email is only unique per company, fail loudly rather than guess" guard
 * already established there.
 */
#[AsCommand(
    name: 'app:grant-platform-admin',
    description: 'Grant platform-admin status to an existing Person, identified by email',
)]
final class GrantPlatformAdminCommand extends Command
{
    public function __construct(
        private readonly PersonRepository $personRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'email',
            InputArgument::REQUIRED,
            'The email of the Person to grant platform-admin status to'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        if (!is_string($email) || $email === '') {
            $io->error('Argument "email" must be a non-empty string.');

            return Command::FAILURE;
        }

        $person = $this->personRepository->loadUserByIdentifier($email);
        if ($person === null) {
            $io->error(sprintf('No person found with email "%s".', $email));

            return Command::FAILURE;
        }

        if ($person->isPlatformAdmin()) {
            $io->warning(sprintf('%s <%s> is already a platform admin.', $person->getFullName(), $email));

            return Command::SUCCESS;
        }

        $person->setPlatformAdmin(true);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s <%s> is now a platform admin and can manage OAuth2 clients.',
            $person->getFullName(),
            $email
        ));

        return Command::SUCCESS;
    }
}
