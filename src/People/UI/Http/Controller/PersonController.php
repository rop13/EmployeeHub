<?php

declare(strict_types=1);

namespace App\People\UI\Http\Controller;

use App\People\Domain\Person;
use App\People\Infrastructure\Persistence\Doctrine\PersonRepository;
use App\People\UI\Http\Form\PersonType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-only: no self-serve company signup — the console-seeded admin adds
 * people from here instead. The tenant-scoping filter (enabled per-request
 * for the logged-in admin's own company) is what keeps
 * PersonRepository::findBy() below scoped to that company alone; no manual
 * company filtering is needed in this controller.
 */
#[Route('/people')]
#[IsGranted('ROLE_ADMIN')]
final class PersonController extends AbstractController
{
    #[Route('', name: 'app_person_index', methods: ['GET'])]
    public function index(PersonRepository $personRepository): Response
    {
        return $this->render('person/index.html.twig', [
            'people' => $personRepository->findBy([], ['firstName' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_person_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        /** @var Person $admin */
        $admin = $this->getUser();
        $company = $admin->getCompany();
        if ($company === null) {
            throw $this->createAccessDeniedException();
        }

        $person = new Person();
        $person->setCompany($company);

        $form = $this->createForm(PersonType::class, $person);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if (!is_string($plainPassword) || $plainPassword === '') {
                throw new \LogicException('Expected a non-empty plainPassword after form validation.');
            }
            $person->setPassword($passwordHasher->hashPassword($person, $plainPassword));

            $entityManager->persist($person);
            $entityManager->flush();

            $this->addFlash('success', sprintf('%s was added.', $person->getFullName()));

            return $this->redirectToRoute('app_person_index');
        }

        return $this->render('person/new.html.twig', [
            'form' => $form,
        ]);
    }
}
