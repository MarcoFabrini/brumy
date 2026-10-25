<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\VehicleShare;
use App\Enum\ShareRole;
use App\Repository\VehicleShareRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cos'è la proprietà di un veicolo: lo share `admin` accettato. Per un utente già esistente nei passaggi
 * di mano è il punto che lo scrive: la rimozione di un membro ({@see OrganizationMemberRemover}) e il
 * trasferimento ({@see VehicleOwnershipTransfer}) passano da {@see self::assign()}. La creazione del
 * veicolo (VehicleController) scrive lo share direttamente.
 *
 * assign() ricollega anche le voci del veicolo alla lista del nuovo proprietario
 * ({@see UserValues::rehome()}). Non fa flush né tocca gli altri share: transazione, flush e regole
 * del contorno sono del chiamante.
 */
final class VehicleOwnership
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VehicleShareRepository $shareRepo,
        private readonly UserValues $values,
    ) {
    }

    /**
     * Rende $newOwner proprietario di $vehicle: promuove sul posto uno share esistente (l'unicità
     * `(vehicle, user)` vieta un secondo record) oppure ne crea uno, e lo marca accettato. I record del
     * veicolo passano alle voci della lista di $newOwner ({@see UserValues::rehome()}).
     */
    public function assign(Vehicle $vehicle, User $newOwner): VehicleShare
    {
        $share = $this->shareRepo->findForUserAndVehicle($newOwner, $vehicle);
        if ($share === null) {
            $share = (new VehicleShare())
                ->setVehicle($vehicle)
                ->setUser($newOwner)
                ->setInvitedBy($newOwner);
            $this->em->persist($share);
        }
        $share->setRole(ShareRole::ADMIN);
        if (!$share->isAccepted()) {
            $share->setAcceptedAt(new \DateTimeImmutable());
        }
        // I record usano la lista del proprietario: distributori, officine e categorie lo seguono
        $this->values->rehome($vehicle, $newOwner);

        return $share;
    }
}
