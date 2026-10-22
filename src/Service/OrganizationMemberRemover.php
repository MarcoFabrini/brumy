<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Repository\OrganizationInvitationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Repository\VehicleShareRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rimozione di un membro da un'organizzazione senza lasciare veicoli senza proprietario.
 *
 * Dashboard, promemoria in arrivo e notifiche sono "solo proprietario" (share `admin` accettato):
 * se le share del membro uscente sparissero e basta, i suoi veicoli resterebbero visibili a
 * owner/admin dell'org ma nessuno riceverebbe più le scadenze. Quindi, nella stessa transazione:
 * 1. ogni veicolo dell'org (archiviati inclusi) di cui il membro è proprietario passa a chi esegue
 *    la rimozione (share esistente promossa ad admin accettato, altrimenti creata);
 * 2. le share residue del membro sull'org cadono, come la membership (se venisse reinvitato non
 *    deve riavere i vecchi accessi né un proprietario "fantasma");
 * 3. gli inviti non ancora accettati che aveva spedito nell'org vengono eliminati (un admin
 *    rimosso non può lasciare in giro inviti spendibili, ad esempio verso una seconda casella).
 */
final class OrganizationMemberRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VehicleShareRepository $shareRepo,
        private readonly VehicleOwnership $ownership,
        private readonly OrganizationMemberRepository $memberRepo,
        private readonly OrganizationInvitationRepository $invitationRepo,
    ) {
    }

    public function remove(OrganizationMember $member, User $actor): void
    {
        $org = $member->getOrganization();
        $removed = $member->getUser();

        $this->em->wrapInTransaction(function () use ($member, $org, $removed, $actor): void {
            $this->handOverVehicles($removed, $org, $this->resolveNewOwner($member, $actor));
            // Gli inviti pendenti che aveva spedito non sopravvivono a chi li ha emessi
            $this->invitationRepo->deletePendingByInviter($removed, $org);
            $this->em->remove($member);
        });
    }

    /**
     * Sposta la proprietà dei veicoli di $user nell'org a $newOwner e fa cadere le sue share residue
     * (membership esclusa: la gestisce il chiamante). Senza $newOwner i veicoli non cambiano mano,
     * ma le share cadono comunque. Va chiamato dentro una transazione del chiamante.
     * Usato anche dalla cancellazione GDPR, che tiene la membership dell'account anonimizzato.
     */
    public function handOverVehicles(User $user, Organization $org, ?User $newOwner): void
    {
        if ($newOwner !== null) {
            foreach ($this->shareRepo->findOwnerSharesInOrganization($user, $org) as $ownerShare) {
                $this->ownership->assign($ownerShare->getVehicle(), $newOwner);
            }
        }

        $this->shareRepo->deleteAllForUserInOrganization($user, $org);
    }

    /**
     * Chi eredita i veicoli: l'utente che rimuove. Se si sta rimuovendo da solo (un admin che esce)
     * non può ereditare da sé: passano al più anziano degli owner accettati dell'org.
     */
    private function resolveNewOwner(OrganizationMember $member, User $actor): ?User
    {
        if ($actor->getId() !== $member->getUser()->getId()) {
            return $actor;
        }

        return $this->memberRepo->findOldestAcceptedOwner($member->getOrganization(), $actor);
    }
}
