<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\People\Domain\Person;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Slice 1 placeholder — login has to redirect somewhere. EmployeeHub's own
 * dashboard content (once it has something to show — client registrations,
 * issued-token activity, etc.) is not this slice's job; see PLAN.md.
 */
final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        /** @var Person $person */
        $person = $this->getUser();

        return $this->render('dashboard/index.html.twig', [
            'person' => $person,
        ]);
    }
}
