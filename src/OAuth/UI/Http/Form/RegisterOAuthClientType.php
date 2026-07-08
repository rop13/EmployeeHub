<?php

declare(strict_types=1);

namespace App\OAuth\UI\Http\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Used by OAuthClientController::new(). Deliberately not backed by a
 * data_class — RegisterOAuthClientService takes plain name/redirectUri
 * strings, the same shape RegisterOAuthClientCommand's own console
 * arguments already have, so the form's submitted data maps straight
 * through without an intermediate DTO.
 *
 * @extends AbstractType<array{name: string, redirectUri: string}>
 */
final class RegisterOAuthClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Application name',
                'constraints' => [new NotBlank()],
            ])
            ->add('redirectUri', UrlType::class, [
                'label' => 'Redirect URI',
                'constraints' => [new NotBlank()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
