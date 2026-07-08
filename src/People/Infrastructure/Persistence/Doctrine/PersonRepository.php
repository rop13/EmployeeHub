<?php

declare(strict_types=1);

namespace App\People\Infrastructure\Persistence\Doctrine;

use App\People\Domain\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Person>
 */
class PersonRepository extends ServiceEntityRepository implements UserLoaderInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    /**
     * Email is only unique *per company* (see Person's class docblock), so
     * a login lookup by email alone could, in principle, match more than
     * one company's person. Rather than silently picking one (which could
     * log someone into the wrong company), this fails loudly — the rare
     * disambiguation case is explicitly out of scope until it's real, not
     * silently mishandled.
     */
    public function loadUserByIdentifier(string $identifier): ?Person
    {
        $matches = $this->findBy(['email' => $identifier]);

        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                'Email "%s" matches more than one company\'s person — login cannot '
                    . 'disambiguate which one. This is a known, deliberately unhandled edge '
                    . 'case (see Person\'s class docblock).',
                $identifier
            ));
        }

        return $matches[0] ?? null;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Person) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
