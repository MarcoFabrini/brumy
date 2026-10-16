<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\PushSubscription;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Le push subscription sono scoped per User, non per Organization.
 * Solo l'utente proprietario può vederle, modificarle o cancellarle.
 *
 * @extends Voter<string, PushSubscription>
 */
final class PushSubscriptionVoter extends Voter
{
    public const VIEW = 'PUSH_VIEW';
    public const DELETE = 'PUSH_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof PushSubscription
            && in_array($attribute, [self::VIEW, self::DELETE], true);
    }

    /** @param PushSubscription $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Entrambe le azioni sono riservate al proprietario; un attributo nuovo non viene concesso per riflesso.
        return match ($attribute) {
            self::VIEW, self::DELETE => $subject->getUser()->getId() === $user->getId(),
            default => false,
        };
    }
}
