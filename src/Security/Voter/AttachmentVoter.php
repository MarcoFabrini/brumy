<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Attachment;
use App\Entity\User;
use App\Enum\AttachmentEntityType;
use App\Repository\ComponentRepository;
use App\Repository\ExpenseRepository;
use App\Repository\MaintenanceRepository;
use App\Repository\RefuelingRepository;
use App\Repository\ReminderRepository;
use App\Repository\VehicleRepository;
use App\Service\VehicleAccessChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Allegato polimorfico: l'accesso deriva dall'entità a cui è agganciato (Vehicle/Maintenance/Expense/Refueling/Reminder).
 * Risolviamo il veicolo target e poi deleghiamo a VehicleAccessChecker.
 *
 * @extends Voter<string, Attachment>
 */
final class AttachmentVoter extends Voter
{
    public const VIEW = 'ATTACHMENT_VIEW';
    public const DELETE = 'ATTACHMENT_DELETE';

    public function __construct(
        private readonly VehicleAccessChecker $access,
        private readonly VehicleRepository $vehicleRepo,
        private readonly MaintenanceRepository $maintenanceRepo,
        private readonly ExpenseRepository $expenseRepo,
        private readonly RefuelingRepository $refuelingRepo,
        private readonly ReminderRepository $reminderRepo,
        private readonly ComponentRepository $componentRepo,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Attachment
            && in_array($attribute, [self::VIEW, self::DELETE], true);
    }

    /** @param Attachment $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $vehicle = $this->resolveVehicle($subject);
        if (!$vehicle) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => $this->access->canView($user, $vehicle),
            self::DELETE => $this->access->canDelete($user, $vehicle),
            default => false,
        };
    }

    private function resolveVehicle(Attachment $a): ?\App\Entity\Vehicle
    {
        $id = (int) $a->getEntityId();
        $org = $a->getOrganization();

        return match ($a->getEntityType()) {
            AttachmentEntityType::VEHICLE => $this->vehicleRepo->findOneInOrganization($id, $org),
            AttachmentEntityType::MAINTENANCE => $this->maintenanceRepo->findOneInOrganization($id, $org)?->getVehicle(),
            AttachmentEntityType::EXPENSE => $this->expenseRepo->findOneInOrganization($id, $org)?->getVehicle(),
            AttachmentEntityType::REFUELING => $this->refuelingRepo->findOneInOrganization($id, $org)?->getVehicle(),
            AttachmentEntityType::REMINDER => $this->reminderRepo->findOneInOrganization($id, $org)?->getVehicle(),
            AttachmentEntityType::COMPONENT => $this->componentRepo->findOneInOrganization($id, $org)?->getVehicle(),
        };
    }
}
