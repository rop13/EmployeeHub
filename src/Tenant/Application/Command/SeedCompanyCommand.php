<?php

declare(strict_types=1);

namespace App\Tenant\Application\Command;

use App\People\Domain\Person;
use App\Tenant\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * There is deliberately no self-serve company signup for this MVP — a new
 * company and its first admin are seeded via this command instead, and the
 * admin adds people to it from the app from there.
 */
#[AsCommand(
    name: 'app:seed-company',
    description: 'Create a new company and its first admin person',
)]
final class SeedCompanyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('company-name', InputArgument::REQUIRED, "The company's name")
            ->addArgument('admin-first-name', InputArgument::REQUIRED, "The admin's first name")
            ->addArgument('admin-last-name', InputArgument::REQUIRED, "The admin's last name")
            ->addArgument('admin-email', InputArgument::REQUIRED, "The admin's email (their login)")
            ->addArgument('admin-password', InputArgument::REQUIRED, "The admin's initial password");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $company = new Company();
        $company->setName($this->requiredStringArgument($input, 'company-name'));

        $admin = new Person();
        $admin->setCompany($company);
        $admin->setFirstName($this->requiredStringArgument($input, 'admin-first-name'));
        $admin->setLastName($this->requiredStringArgument($input, 'admin-last-name'));
        $admin->setEmail($this->requiredStringArgument($input, 'admin-email'));
        $admin->setRole(Person::ROLE_ADMIN);
        $admin->setPassword($this->passwordHasher->hashPassword(
            $admin,
            $this->requiredStringArgument($input, 'admin-password')
        ));

        $violations = $this->validator->validate($company);
        $violations->addAll($this->validator->validate($admin));

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error(sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()));
            }

            return Command::FAILURE;
        }

        $this->entityManager->wrapInTransaction(function () use ($company, $admin): void {
            $this->entityManager->persist($company);
            $this->entityManager->persist($admin);
            $this->entityManager->flush();
        });

        $io->success(sprintf(
            'Created company "%s" with admin %s <%s>.',
            $company->getName(),
            $admin->getFullName(),
            $admin->getEmail()
        ));

        return Command::SUCCESS;
    }

    private function requiredStringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" must be a string.', $name));
        }

        return $value;
    }
}
