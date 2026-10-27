<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use App\Entity\Attachment;
use App\Entity\AuditLog;
use App\Entity\EmailVerificationToken;
use App\Entity\Organization;
use App\Entity\OrganizationInvitation;
use App\Entity\OrganizationMember;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Enum\OrgRole;
use App\Repository\OrganizationInvitationRepository;
use App\Repository\OrganizationMemberRepository;
use App\Repository\PushSubscriptionRepository;
use App\Security\RememberMeTokens;
use App\Service\OrganizationMemberRemover;
use App\Service\Storage\AttachmentStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * GDPR Art. 17 — right to erasure (#5.5).
 *
 * Strategia: anonymize (non hard-delete) per preservare integrità referenziale
 * dei dati org-owned (vehicles, maintenance, ecc.) che potrebbero appartenere
 * ad altri membri dell'organization.
 *
 * Operazioni:
 * 1. Verifica precondizioni: l'utente NON deve essere unico owner di un'org
 *    con altri membri attivi (deve trasferire ownership prima).
 * 2. Anonymize PII (email, firstName, lastName, password) → email univoca
 *    pseudo-randomica `deleted-<id>-<hash>@anonymized.local`.
 * 3. Chiude ogni sessione: email e password cambiano, quindi le sessioni aperte non autenticano più alla richiesta
 *    successiva ({@see User::isEqualTo()}); le serie remember-me della vecchia email vengono cancellate; la verifica in
 *    due passaggi e l'ultima org attiva si azzerano.
 * 4. Delete tutte le push subscription (token mobile, endpoint web).
 * 5. Per ogni org dove l'utente è unico membro → delete org (cascade) e dei suoi file allegati.
 *    Negli altri casi le memberships restano (storia partecipazione), ma i veicoli di cui è
 *    proprietario passano al più anziano owner accettato dell'org, le sue altre share cadono e gli
 *    inviti pendenti che ha spedito vengono eliminati.
 * 6. Rimuove il file avatar, azzera IP/user-agent negli audit log dell'utente ed elimina gli
 *    inviti indirizzati alla sua vecchia email (PII residua fuori dalla riga `users`) e i suoi
 *    token di reset password / verifica email.
 *
 * Il record `users` resta in DB con dati anonimi per:
 * - mantenere FK attachments.uploaded_by, vehicle_shares.invited_by
 * - audit log futuro (#3.6)
 * - rispettare cascadi DB esistenti
 */
final class GdprDeleteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RememberMeTokens $rememberMeTokens,
        private readonly PushSubscriptionRepository $pushRepo,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AttachmentStorageInterface $storage,
        private readonly OrganizationMemberRemover $memberRemover,
        private readonly OrganizationMemberRepository $memberRepo,
        private readonly OrganizationInvitationRepository $invitationRepo,
    ) {
    }

    /**
     * @return array{anonymized_user_id: int, deleted_remember_me_tokens: int, deleted_push_subs: int, deleted_orgs: int, kept_memberships: int}
     *
     * @throws GdprDeleteBlockedException se l'utente è ultimo owner di un'org condivisa
     */
    public function anonymize(User $user): array
    {
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Cannot anonymize unpersisted user');
        }

        $this->assertSafeToDelete($user);

        /** @var list<string> $filesToDelete file fisici da eliminare solo dopo il commit del DB */
        $filesToDelete = [];
        // Tutto o niente: se il flush finale fallisse, audit log e inviti già modificati resterebbero
        // in uno stato intermedio con l'utente non anonimizzato.
        $summary = $this->em->wrapInTransaction(fn (): array => $this->anonymizeInTransaction($user, $userId, $filesToDelete));

        foreach ($filesToDelete as $storedPath) {
            try {
                $this->storage->delete($storedPath);
            } catch (\Throwable) {
                // File già assente o non rimovibile: il DB è già anonimizzato, non blocchiamo il diritto all'oblio.
            }
        }

        return $summary;
    }

    /**
     * @param list<string> $filesToDelete riempito con i file da rimuovere dopo il commit
     *
     * @return array{anonymized_user_id: int, deleted_remember_me_tokens: int, deleted_push_subs: int, deleted_orgs: int, kept_memberships: int}
     */
    private function anonymizeInTransaction(User $user, int $userId, array &$filesToDelete): array
    {
        $deletedOrgs = 0;
        $keptMemberships = 0;
        /** @var list<Organization> $orgsToRemove */
        $orgsToRemove = [];
        $oldEmail = $user->getEmail();

        // Drop org dove non resta nessun'altra persona viva (membri pendenti o già anonimizzati non contano):
        // altrimenti i suoi dati resterebbero conservati senza nessuno che possa vederli o cancellarli.
        foreach ($user->getMemberships()->toArray() as $m) {
            $org = $m->getOrganization();

            if ($this->liveOtherMembers($org, $user) === []) {
                /** @var list<string> $paths */
                $paths = $this->em->createQuery('SELECT a.storedPath FROM '.Attachment::class.' a WHERE a.organization = :org')
                    ->setParameter('org', $org)
                    ->getSingleColumnResult();
                array_push($filesToDelete, ...$paths);
                // I diff degli audit log (targa, VIN, note...) sono dati dell'org: con la FK SET NULL sopravviverebbero
                // alla sua rimozione, quindi vanno cancellati qui, nella stessa transazione.
                $this->em->createQuery('DELETE FROM '.AuditLog::class.' l WHERE l.organization = :org')
                    ->setParameter('org', $org)
                    ->execute();
                $orgsToRemove[] = $org;
                ++$deletedOrgs;
            } else {
                // Altri membri restano: i veicoli dell'utente passano al più anziano degli owner
                // (altrimenti l'account anonimo resterebbe "proprietario" e nessuno riceverebbe
                // più le scadenze) e le sue altre share cadono. La membership resta (storia).
                $this->memberRemover->handOverVehicles($user, $org, $this->memberRepo->findOldestAcceptedOwner($org, $user));
                ++$keptMemberships;
            }
        }
        // Rimosse dopo il ciclo: handOverVehicles() fa flush, e un'org già eliminata da un flush intermedio
        // resterebbe referenziata dalle entity ancora gestite (es. un audit log già caricato) al flush finale.
        foreach ($orgsToRemove as $org) {
            $this->em->remove($org);
        }

        // Gli inviti pendenti spediti dall'utente non devono sopravvivergli
        $this->invitationRepo->deletePendingByInviter($user);

        // Hard-delete push subscriptions (token device + endpoint web)
        $pushSubs = $this->pushRepo->findBy(['user' => $user]);
        foreach ($pushSubs as $sub) {
            $this->em->remove($sub);
        }
        $deletedPushSubs = count($pushSubs);

        if ($user->getAvatarPath() !== null) {
            $filesToDelete[] = $user->getAvatarPath();
            $user->setAvatarPath(null);
        }

        // PII residua fuori da `users`: metadati tecnici negli audit log e inviti con la vecchia email
        $this->em->createQuery('UPDATE '.AuditLog::class.' l SET l.ipAddress = NULL, l.userAgent = NULL WHERE l.user = :user')
            ->setParameter('user', $user)
            ->execute();
        $this->em->createQuery('DELETE FROM '.OrganizationInvitation::class.' i WHERE LOWER(i.email) = :email')
            ->setParameter('email', mb_strtolower($oldEmail))
            ->execute();
        // Token di reset password e verifica email ancora validi: l'account è anonimo, non devono restare spendibili
        foreach ([PasswordResetToken::class, EmailVerificationToken::class] as $tokenClass) {
            $this->em->createQuery('DELETE FROM '.$tokenClass.' t WHERE t.user = :user')
                ->setParameter('user', $user)
                ->execute();
        }

        // Anonymize PII
        // Cookie remember-me: puntano alla vecchia email, che sparisce; le righe vanno via comunque (contengono l'email).
        $deletedRememberMe = $this->rememberMeTokens->deleteForUser($oldEmail);
        $user->disableTwoFactor();
        $user->setLastActiveOrganization(null);

        $anonEmail = sprintf('deleted-%d-%s%s', $userId, bin2hex(random_bytes(8)), User::ANONYMIZED_EMAIL_SUFFIX);
        $randomPassword = bin2hex(random_bytes(32));
        $user->cancelPendingEmail();
        $user
            ->setEmail($anonEmail)
            ->setFirstName('Deleted')
            ->setLastName('User')
            ->setLocale('it')
            ->setPassword($this->hasher->hashPassword($user, $randomPassword));

        $this->em->flush();

        return [
            'anonymized_user_id' => $userId,
            'deleted_remember_me_tokens' => $deletedRememberMe,
            'deleted_push_subs' => $deletedPushSubs,
            'deleted_orgs' => $deletedOrgs,
            'kept_memberships' => $keptMemberships,
        ];
    }

    /**
     * Blocca se l'utente è l'ultimo owner vivo di un'org in cui restano altre persone vive. Un co-owner già
     * anonimizzato o non ancora accettato non conta (stessa regola di {@see OrganizationMemberRepository::countAcceptedOwners()},
     * usata dal cambio ruolo): altrimenti l'org resterebbe senza nessuno che possa gestirla o cancellarla.
     */
    private function assertSafeToDelete(User $user): void
    {
        foreach ($user->getMemberships() as $m) {
            if ($m->getRole() !== OrgRole::OWNER || !$m->isAccepted()) {
                continue;
            }
            $org = $m->getOrganization();
            $liveOthers = $this->liveOtherMembers($org, $user);

            if ($liveOthers !== [] && $this->memberRepo->countAcceptedOwners($org, $user) === 0) {
                throw new GdprDeleteBlockedException(sprintf(
                    'User is sole owner of organization "%s" with %d other members. Transfer ownership first.',
                    $org->getSlug(),
                    count($liveOthers),
                ));
            }
        }
    }

    /** @return list<OrganizationMember> membri accettati e non anonimizzati dell'org, escluso $user */
    private function liveOtherMembers(Organization $org, User $user): array
    {
        return array_values(array_filter(
            $org->getMembers()->toArray(),
            static fn (OrganizationMember $x): bool => $x->getUser()->getId() !== $user->getId()
                && $x->isAccepted()
                && !$x->getUser()->isAnonymized(),
        ));
    }
}
