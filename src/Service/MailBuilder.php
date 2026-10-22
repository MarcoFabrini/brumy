<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Reminder;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\OrgRole;
use App\Enum\ReminderUrgency;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Costruisce le email transazionali dell'app come {@see Email} branded (HTML +
 * testo) a partire da un template Twig generico, con copy localizzata (it/en)
 * in base alla lingua dell'utente. Il chiamante imposta solo `->to()` e passa
 * l'Email ad {@see AppMailer} per l'invio.
 *
 * Niente componente translation: la copy vive qui, per-locale, così i template
 * restano pura impaginazione e l'invio non dipende da listener Twig (l'SMTP
 * custom di AppMailer crea un Mailer senza dispatcher → renderizziamo a stringa).
 */
final class MailBuilder
{
    private const SUPPORTED = ['it', 'en'];

    public function __construct(
        private readonly Environment $twig,
        #[Autowire(param: 'app.frontend_url')] private readonly string $frontendUrl,
        #[Autowire(param: 'app.mailer_from')] private readonly string $from,
    ) {
    }

    public function reminder(
        User $user,
        Reminder $reminder,
        ReminderUrgency $urgency = ReminderUrgency::SOON,
        ?int $currentKm = null,
    ): Email {
        $loc = $this->locale($user);
        $vehicle = $reminder->getVehicle()->getName();
        $desc = $reminder->getDescription();
        $first = $user->getFirstName();
        $overdue = $urgency === ReminderUrgency::OVERDUE;
        $deadline = $this->reminderDeadline($reminder, $currentKm, $loc);

        $copy = $loc === 'en'
            ? [
                'subject' => 'Brumy — Reminder: '.$desc,
                'heading' => $overdue ? 'Reminder overdue' : 'Upcoming reminder',
                'greeting' => sprintf('Hi %s,', $first),
                'paragraphs' => [
                    sprintf($overdue ? 'A reminder for your vehicle %s is overdue:' : 'You have an upcoming reminder for your vehicle %s:', $vehicle),
                    $deadline !== '' ? sprintf('%s — %s', $desc, $deadline) : $desc,
                ],
                'button' => ['label' => 'Open Brumy', 'url' => $this->link('')],
                'footer' => "You're receiving this because you have access to this vehicle in Brumy.",
            ]
            : [
                'subject' => 'Brumy — Scadenza: '.$desc,
                'heading' => $overdue ? 'Scadenza superata' : 'Scadenza in arrivo',
                'greeting' => sprintf('Ciao %s,', $first),
                'paragraphs' => [
                    sprintf($overdue ? 'È scaduta una scadenza per il tuo veicolo %s:' : 'C\'è una scadenza in arrivo per il tuo veicolo %s:', $vehicle),
                    $deadline !== '' ? sprintf('%s — %s', $desc, $deadline) : $desc,
                ],
                'button' => ['label' => 'Apri Brumy', 'url' => $this->link('')],
                'footer' => 'Ricevi questa email perché hai accesso a questo veicolo in Brumy.',
            ];

        return $this->build($loc, $copy);
    }

    /** "scadenza 15/10/2026 · a 100.000 km (attuali: 99.500 km)" — solo le scadenze presenti. */
    private function reminderDeadline(Reminder $reminder, ?int $currentKm, string $locale): string
    {
        $en = $locale === 'en';
        $parts = [];

        if ($reminder->getDueDate() !== null) {
            $parts[] = ($en ? 'due ' : 'scadenza ').$reminder->getDueDate()->format($en ? 'Y-m-d' : 'd/m/Y');
        }
        if ($reminder->getDueKm() !== null) {
            $fmt = static fn (int $km): string => number_format($km, 0, $en ? '.' : ',', $en ? ',' : '.').' km';
            $parts[] = sprintf(
                $en ? 'at %s%s' : 'a %s%s',
                $fmt($reminder->getDueKm()),
                $currentKm !== null ? sprintf($en ? ' (now: %s)' : ' (attuali: %s)', $fmt($currentKm)) : '',
            );
        }

        return implode(' · ', $parts);
    }

    public function resetPassword(User $user, string $rawToken): Email
    {
        $loc = $this->locale($user);
        $url = $this->link('/reset-password', $rawToken);
        $first = $user->getFirstName();

        $copy = $loc === 'en'
            ? [
                'subject' => 'Brumy — Reset your password',
                'heading' => 'Reset your password',
                'greeting' => sprintf('Hi %s,', $first),
                'paragraphs' => ['You asked to reset your Brumy password. This link is valid for 1 hour.'],
                'button' => ['label' => 'Reset password', 'url' => $url],
                'outro' => "If you didn't request this, ignore this email — your password stays unchanged.",
                'footer' => 'Brumy — self-hosted vehicle management.',
            ]
            : [
                'subject' => 'Brumy — Reimposta la tua password',
                'heading' => 'Reimposta la password',
                'greeting' => sprintf('Ciao %s,', $first),
                'paragraphs' => ['Hai richiesto di reimpostare la password del tuo account Brumy. Il link è valido 1 ora.'],
                'button' => ['label' => 'Reimposta password', 'url' => $url],
                'outro' => 'Se non hai richiesto tu il reset, ignora questa email: la password resta invariata.',
                'footer' => 'Brumy — gestione veicoli self-host.',
            ];

        return $this->build($loc, $copy);
    }

    public function verifyEmail(User $user, string $rawToken): Email
    {
        $loc = $this->locale($user);
        $url = $this->link('/verify-email', $rawToken);
        $first = $user->getFirstName();

        $copy = $loc === 'en'
            ? [
                'subject' => 'Brumy — Confirm your email',
                'heading' => 'Confirm your email',
                'greeting' => sprintf('Hi %s,', $first),
                'paragraphs' => ['Welcome to Brumy! Confirm your email address to finish setting up your account. This link is valid for 24 hours.'],
                'button' => ['label' => 'Confirm email', 'url' => $url],
                'outro' => "If you didn't create an Brumy account, ignore this email.",
                'footer' => 'Brumy — self-hosted vehicle management.',
            ]
            : [
                'subject' => 'Brumy — Conferma il tuo indirizzo email',
                'heading' => 'Conferma la tua email',
                'greeting' => sprintf('Ciao %s,', $first),
                'paragraphs' => ['Benvenuto in Brumy! Conferma il tuo indirizzo email per completare la registrazione. Il link è valido 24 ore.'],
                'button' => ['label' => 'Conferma email', 'url' => $url],
                'outro' => 'Se non ti sei registrato tu, ignora questa email.',
                'footer' => 'Brumy — gestione veicoli self-host.',
            ];

        return $this->build($loc, $copy);
    }

    /** Conferma del cambio email: va al NUOVO indirizzo, che diventa l'email dell'account solo aprendo il link. */
    public function confirmEmailChange(User $user, string $rawToken): Email
    {
        $loc = $this->locale($user);
        $url = $this->link('/verify-email', $rawToken);
        $first = $user->getFirstName();

        $copy = $loc === 'en'
            ? [
                'subject' => 'Brumy — Confirm your new email address',
                'heading' => 'Confirm your new email address',
                'greeting' => sprintf('Hi %s,', $first),
                'paragraphs' => [
                    'You asked to use this address for your Brumy account. Open the link to confirm: from then on you will log in with this address, and every open session will be closed. This link is valid for 24 hours.',
                ],
                'button' => ['label' => 'Confirm new email', 'url' => $url],
                'outro' => "If you didn't ask for this, ignore this email: the account address won't change.",
                'footer' => 'Brumy — self-hosted vehicle management.',
            ]
            : [
                'subject' => 'Brumy — Conferma il nuovo indirizzo email',
                'heading' => 'Conferma il nuovo indirizzo email',
                'greeting' => sprintf('Ciao %s,', $first),
                'paragraphs' => [
                    'Hai chiesto di usare questo indirizzo per il tuo account Brumy. Apri il link per confermare: da quel momento accederai con questo indirizzo e tutte le sessioni aperte verranno chiuse. Il link è valido 24 ore.',
                ],
                'button' => ['label' => 'Conferma nuova email', 'url' => $url],
                'outro' => "Se non l'hai chiesto tu, ignora questa email: l'indirizzo dell'account non cambia.",
                'footer' => 'Brumy — gestione veicoli self-host.',
            ];

        return $this->build($loc, $copy);
    }

    /**
     * Invito a un'organizzazione: l'invitato può non avere ancora un account,
     * quindi accettiamo solo l'email (niente nome) + locale opzionale (default it).
     */
    public function invitation(string $email, Organization $org, ?User $inviter, string $rawToken, string $locale = 'it'): Email
    {
        $loc = in_array($locale, self::SUPPORTED, true) ? $locale : 'it';
        $url = $this->link('/accept-invite', $rawToken);
        $orgName = $org->getName();
        $inviterName = $inviter !== null
            ? trim($inviter->getFirstName().' '.$inviter->getLastName())
            : null;

        $copy = $loc === 'en'
            ? [
                'subject' => 'Brumy — Invitation to '.$orgName,
                'heading' => "You've been invited",
                'greeting' => null,
                'paragraphs' => [
                    $inviterName !== null
                        ? sprintf('%s invited you to join the organization "%s" on Brumy.', $inviterName, $orgName)
                        : sprintf('You have been invited to join the organization "%s" on Brumy.', $orgName),
                    'Accept the invitation to start sharing vehicles — you can create your account on the spot if you don\'t have one. This link is valid for 7 days.',
                ],
                'button' => ['label' => 'Accept invitation', 'url' => $url],
                'outro' => "If you weren't expecting this invitation, ignore this email.",
                'footer' => 'Brumy — self-hosted vehicle management.',
            ]
            : [
                'subject' => 'Brumy — Invito a '.$orgName,
                'heading' => 'Sei stato invitato',
                'greeting' => null,
                'paragraphs' => [
                    $inviterName !== null
                        ? sprintf('%s ti ha invitato a unirti all\'organizzazione "%s" su Brumy.', $inviterName, $orgName)
                        : sprintf('Sei stato invitato a unirti all\'organizzazione "%s" su Brumy.', $orgName),
                    'Accetta l\'invito per condividere i veicoli — se non hai un account puoi crearlo al momento. Il link è valido 7 giorni.',
                ],
                'button' => ['label' => 'Accetta invito', 'url' => $url],
                'outro' => 'Se non ti aspettavi questo invito, ignora questa email.',
                'footer' => 'Brumy — gestione veicoli self-host.',
            ];

        return $this->build($loc, $copy);
    }

    /** Nome del ruolo d'organizzazione nella lingua dell'email/push (stessi termini dell'interfaccia). */
    public static function roleLabel(OrgRole $role, string $locale): string
    {
        return match ($role) {
            OrgRole::OWNER => $locale === 'en' ? 'Owner' : 'Proprietario',
            OrgRole::ADMIN => $locale === 'en' ? 'Admin' : 'Amministratore',
            OrgRole::MEMBER => $locale === 'en' ? 'Member' : 'Membro',
        };
    }

    /**
     * Avviso al membro il cui ruolo nell'organizzazione è stato cambiato da $actor.
     * Il ruolo nuovo è quello già salvato su $member.
     */
    public function roleChanged(OrganizationMember $member, User $actor): Email
    {
        $user = $member->getUser();
        $loc = $this->locale($user);
        $orgName = $member->getOrganization()->getName();
        $actorName = trim($actor->getFirstName().' '.$actor->getLastName());
        $role = self::roleLabel($member->getRole(), $loc);
        $first = $user->getFirstName();

        $copy = $loc === 'en'
            ? [
                'subject' => 'Brumy — Your role in '.$orgName.' has changed',
                'heading' => 'Your role has changed',
                'greeting' => sprintf('Hi %s,', $first),
                'paragraphs' => [
                    sprintf('%s changed your role in the organization "%s": you are now %s.', $actorName, $orgName, $role),
                ],
                'button' => ['label' => 'Open Brumy', 'url' => $this->link('')],
                'footer' => "You're receiving this because you are a member of this organization in Brumy.",
            ]
            : [
                'subject' => 'Brumy — Il tuo ruolo in '.$orgName.' è cambiato',
                'heading' => 'Il tuo ruolo è cambiato',
                'greeting' => sprintf('Ciao %s,', $first),
                'paragraphs' => [
                    sprintf('%s ha cambiato il tuo ruolo nell\'organizzazione "%s": ora sei %s.', $actorName, $orgName, $role),
                ],
                'button' => ['label' => 'Apri Brumy', 'url' => $this->link('')],
                'footer' => 'Ricevi questa email perché fai parte di questa organizzazione in Brumy.',
            ];

        return $this->build($loc, $copy);
    }

    /**
     * Avviso al nuovo proprietario di un veicolo. $actor è chi ha eseguito il trasferimento (può essere
     * lo stesso $newOwner, per un owner/admin che prende in carico un veicolo); $previousOwner è nullo
     * per un veicolo orfano.
     */
    public function vehicleReceived(User $newOwner, Vehicle $vehicle, User $actor, ?User $previousOwner): Email
    {
        $loc = $this->locale($newOwner);
        $en = $loc === 'en';
        $vehicleName = $vehicle->getName();
        $orgName = $vehicle->getOrganization()->getName();
        $self = $actor->getId() === $newOwner->getId();
        $actorName = self::fullName($actor);
        $fromName = $previousOwner !== null && $previousOwner->getId() !== $actor->getId() ? self::fullName($previousOwner) : null;

        if ($en) {
            $first = $self
                ? sprintf('You took over the ownership of the vehicle "%s" in the organization "%s".', $vehicleName, $orgName)
                : sprintf('%s transferred the ownership of the vehicle "%s" in the organization "%s" to you.', $actorName, $vehicleName, $orgName);
            $paragraphs = [$first];
            if ($fromName !== null) {
                $paragraphs[] = sprintf('Previous owner: %s.', $fromName);
            }
            $paragraphs[] = 'Reminders, totals and charts of this vehicle, history included, are now yours.';
            $copy = [
                'subject' => 'Brumy — '.$vehicleName.' is now yours',
                'heading' => 'You are now the owner of a vehicle',
                'greeting' => sprintf('Hi %s,', $newOwner->getFirstName()),
                'paragraphs' => $paragraphs,
                'button' => ['label' => 'Open the vehicle', 'url' => $this->link('/vehicles/'.$vehicle->getId())],
                'footer' => "You're receiving this because you are a member of this organization in Brumy.",
            ];
        } else {
            $first = $self
                ? sprintf('Hai preso in carico la proprietà del veicolo "%s" nell\'organizzazione "%s".', $vehicleName, $orgName)
                : sprintf('%s ti ha trasferito la proprietà del veicolo "%s" nell\'organizzazione "%s".', $actorName, $vehicleName, $orgName);
            $paragraphs = [$first];
            if ($fromName !== null) {
                $paragraphs[] = sprintf('Precedente proprietario: %s.', $fromName);
            }
            $paragraphs[] = 'Promemoria, totali e grafici di questo veicolo, storico incluso, ora sono tuoi.';
            $copy = [
                'subject' => 'Brumy — '.$vehicleName.' ora è tuo',
                'heading' => 'Sei il nuovo proprietario di un veicolo',
                'greeting' => sprintf('Ciao %s,', $newOwner->getFirstName()),
                'paragraphs' => $paragraphs,
                'button' => ['label' => 'Apri il veicolo', 'url' => $this->link('/vehicles/'.$vehicle->getId())],
                'footer' => 'Ricevi questa email perché fai parte di questa organizzazione in Brumy.',
            ];
        }

        return $this->build($loc, $copy);
    }

    /**
     * Avviso al precedente proprietario (non è chi ha eseguito il trasferimento): a chi è passato il
     * veicolo e se gli resta l'accesso in sola lettura.
     */
    public function vehicleHandedOver(User $previousOwner, Vehicle $vehicle, User $newOwner, User $actor, bool $keepAccess): Email
    {
        $loc = $this->locale($previousOwner);
        $vehicleName = $vehicle->getName();
        $orgName = $vehicle->getOrganization()->getName();
        $actorName = self::fullName($actor);
        $newName = self::fullName($newOwner);

        $copy = $loc === 'en'
            ? [
                'subject' => 'Brumy — '.$vehicleName.' has a new owner',
                'heading' => 'Vehicle ownership transferred',
                'greeting' => sprintf('Hi %s,', $previousOwner->getFirstName()),
                'paragraphs' => [
                    sprintf('%s transferred the ownership of the vehicle "%s" in the organization "%s" to %s.', $actorName, $vehicleName, $orgName, $newName),
                    $keepAccess ? 'You keep read-only access to the vehicle.' : 'You no longer have direct access to the vehicle.',
                    'Its reminders, totals and charts now belong to the new owner.',
                ],
                'button' => ['label' => 'Open Brumy', 'url' => $this->link('')],
                'footer' => "You're receiving this because you owned this vehicle in Brumy.",
            ]
            : [
                'subject' => 'Brumy — '.$vehicleName.' ha un nuovo proprietario',
                'heading' => 'Proprietà del veicolo trasferita',
                'greeting' => sprintf('Ciao %s,', $previousOwner->getFirstName()),
                'paragraphs' => [
                    sprintf('%s ha trasferito la proprietà del veicolo "%s" nell\'organizzazione "%s" a %s.', $actorName, $vehicleName, $orgName, $newName),
                    $keepAccess ? 'Mantieni l\'accesso al veicolo in sola lettura.' : 'Non hai più un accesso diretto al veicolo.',
                    'Promemoria, totali e grafici ora appartengono al nuovo proprietario.',
                ],
                'button' => ['label' => 'Apri Brumy', 'url' => $this->link('')],
                'footer' => 'Ricevi questa email perché eri il proprietario di questo veicolo in Brumy.',
            ];

        return $this->build($loc, $copy);
    }

    private static function fullName(User $user): string
    {
        return trim($user->getFirstName().' '.$user->getLastName());
    }

    private function locale(User $user): string
    {
        return in_array($user->getLocale(), self::SUPPORTED, true) ? $user->getLocale() : 'it';
    }

    private function link(string $path, ?string $token = null): string
    {
        $url = rtrim($this->frontendUrl, '/').$path;
        return $token !== null ? $url.'?token='.$token : $url;
    }

    /**
     * @param array{subject:string, heading:string, greeting?:string|null, paragraphs?:list<string>, button?:array{label:string,url:string}|null, outro?:string|null, footer:string} $copy
     */
    private function build(string $locale, array $copy): Email
    {
        $context = [
            'locale' => $locale,
            'subject' => $copy['subject'],
            'preheader' => $copy['paragraphs'][0] ?? $copy['heading'],
            'heading' => $copy['heading'],
            'greeting' => $copy['greeting'] ?? null,
            'paragraphs' => $copy['paragraphs'] ?? [],
            'button' => $copy['button'] ?? null,
            'outro' => $copy['outro'] ?? null,
            'footer' => $copy['footer'],
        ];

        return (new Email())
            ->from($this->from)
            ->subject($copy['subject'])
            ->html($this->twig->render('emails/transactional.html.twig', $context))
            ->text($this->twig->render('emails/transactional.txt.twig', $context));
    }
}
