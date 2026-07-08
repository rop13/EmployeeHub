<?php

declare(strict_types=1);

namespace App\People\UI\Http\Form;

use App\People\Domain\Person;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Used by PersonController::new(), admin-only. Every required field carries
 * an explicit NotBlank constraint from day one — a blank submission must
 * re-render the form with an inline error, not crash the controller.
 *
 * @extends AbstractType<Person>
 */
final class PersonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'First name',
                'constraints' => [new NotBlank()],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last name',
                'constraints' => [new NotBlank()],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email (their login)',
                'constraints' => [new NotBlank()],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'choices' => [
                    'Employee' => Person::ROLE_EMPLOYEE,
                    'Admin' => Person::ROLE_ADMIN,
                ],
                'constraints' => [new NotBlank()],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Initial password',
                'mapped' => false,
                'constraints' => [new NotBlank()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Person::class]);
    }
}
