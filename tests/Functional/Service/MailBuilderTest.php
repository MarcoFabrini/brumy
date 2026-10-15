<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Reminder;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\OrgRole;
use App\Enum\ReminderUrgency;
use App\Service\MailBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MailBuilder: rende le email transazionali branded (HTML + testo) con copy
 * localizzata in base alla lingua dell'utente (closes #2.2).
 */
final class MailBuilderTest extends KernelTestCase
{
    private function mailBuilder(): MailBuilder
    {
        self::bootKernel();
        return static::getContainer()->get(MailBuilder::class);
    }

    private function user(string $locale): User
    {
        return (new User())->setEmail('u@test.it')->setFirstName('Mario')->setLastName('Rossi')->setLocale($locale);
    }

    public function testVerifyEmailItalianHasHtmlTextAndLink(): void
    {
        $email = $this->mailBuilder()->verifyEmail($this->user('it'), 'tok-abc');

        self::assertStringContainsString('Conferma', (string) $email->getSubject());

        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Conferma la tua email', $html);
        self::assertStringContainsString('verify-email?token=tok-abc', $html);
        self::assertStringContainsString('<!DOCTYPE html>', $html, 'Corpo HTML branded');

        $text = (string) $email->getTextBody();
        self::assertStringContainsString('verify-email?token=tok-abc', $text);
    }

    public function testResetPasswordEnglishCopy(): void
    {
        $email = $this->mailBuilder()->resetPassword($this->user('en'), 'tok-xyz');

        self::assertStringContainsString('Reset your password', (string) $email->getSubject());
        self::assertStringContainsString('reset-password?token=tok-xyz', (string) $email->getHtmlBody());
    }

    public function testUnsupportedLocaleFallsBackToItalian(): void
    {
        $email = $this->mailBuilder()->verifyEmail($this->user('fr'), 'tok-1');

        self::assertStringContainsString('Conferma', (string) $email->getSubject());
    }

    private function kmReminder(): Reminder
    {
        return (new Reminder())
            ->setVehicle((new Vehicle())->setName('Panda'))
            ->setDescription('Cambio olio')
            ->setDueKm(100_000);
    }

    public function testReminderMentionsKmDeadlineAndCurrentOdometer(): void
    {
        $email = $this->mailBuilder()->reminder($this->user('it'), $this->kmReminder(), ReminderUrgency::SOON, 99_500);

        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Cambio olio', $text);
        self::assertStringContainsString('a 100.000 km (attuali: 99.500 km)', $text);
        self::assertStringContainsString('Scadenza in arrivo', (string) $email->getHtmlBody());
    }

    public function testReminderOverdueEnglishCopy(): void
    {
        $email = $this->mailBuilder()->reminder($this->user('en'), $this->kmReminder(), ReminderUrgency::OVERDUE, 100_400);

        self::assertStringContainsString('Reminder overdue', (string) $email->getHtmlBody());
        self::assertStringContainsString('at 100,000 km (now: 100,400 km)', (string) $email->getTextBody());
    }

    public function testReminderWithBothDateAndKmListsBoth(): void
    {
        $r = $this->kmReminder()->setDueDate(new \DateTimeImmutable('2026-10-15'));

        $text = (string) $this->mailBuilder()->reminder($this->user('it'), $r, ReminderUrgency::SOON, null)->getTextBody();

        self::assertStringContainsString('scadenza 15/10/2026 · a 100.000 km', $text);
        self::assertStringNotContainsString('attuali', $text, 'senza km attuali noti non si inventa nulla');
    }

    private function roleChange(string $locale, OrgRole $role): \Symfony\Component\Mime\Email
    {
        $member = (new OrganizationMember())
            ->setOrganization((new Organization())->setName('Officina Rossi'))
            ->setUser($this->user($locale))
            ->setRole($role);
        $actor = (new User())->setEmail('a@test.it')->setFirstName('Anna')->setLastName('Bianchi');

        return $this->mailBuilder()->roleChanged($member, $actor);
    }

    public function testRoleChangedItalianNamesOrganizationActorAndRole(): void
    {
        $email = $this->roleChange('it', OrgRole::ADMIN);

        self::assertSame('Brumy — Il tuo ruolo in Officina Rossi è cambiato', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Ciao Mario,', $text);
        self::assertStringContainsString('Anna Bianchi ha cambiato il tuo ruolo nell\'organizzazione "Officina Rossi": ora sei Amministratore.', $text);
        self::assertStringContainsString('Apri Brumy', (string) $email->getHtmlBody());
    }

    public function testRoleChangedEnglishNamesOrganizationActorAndRole(): void
    {
        $email = $this->roleChange('en', OrgRole::MEMBER);

        self::assertSame('Brumy — Your role in Officina Rossi has changed', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Hi Mario,', $text);
        self::assertStringContainsString('Anna Bianchi changed your role in the organization "Officina Rossi": you are now Member.', $text);
        self::assertStringContainsString('Open Brumy', (string) $email->getHtmlBody());
    }

    private function transferVehicle(): Vehicle
    {
        return (new Vehicle())->setOrganization((new Organization())->setName('Officina Rossi'))->setName('Panda');
    }

    private function person(string $first, string $last, string $locale = 'it'): User
    {
        $user = (new User())->setEmail($first.'@test.it')->setFirstName($first)->setLastName($last)->setLocale($locale);
        // Serve un id per distinguere "chi ha agito" dal destinatario (le entity qui non sono persistite)
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, crc32($first));

        return $user;
    }

    public function testVehicleReceivedItalianNamesVehicleActorAndPreviousOwner(): void
    {
        $email = $this->mailBuilder()->vehicleReceived($this->person('Luca', 'Verdi'), $this->transferVehicle(), $this->person('Anna', 'Bianchi'), $this->person('Mario', 'Rossi'));

        self::assertSame('Brumy — Panda ora è tuo', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Ciao Luca,', $text);
        self::assertStringContainsString('Anna Bianchi ti ha trasferito la proprietà del veicolo "Panda" nell\'organizzazione "Officina Rossi".', $text);
        self::assertStringContainsString('Precedente proprietario: Mario Rossi.', $text);
        self::assertStringContainsString('Promemoria, totali e grafici di questo veicolo, storico incluso, ora sono tuoi.', $text);
        self::assertStringContainsString('/vehicles/', $text);
    }

    public function testVehicleReceivedEnglishWhenTheActorTakesItForThemselves(): void
    {
        $actor = $this->person('Luca', 'Verdi', 'en');
        $email = $this->mailBuilder()->vehicleReceived($actor, $this->transferVehicle(), $actor, null);

        self::assertSame('Brumy — Panda is now yours', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('You took over the ownership of the vehicle "Panda"', $text);
        self::assertStringNotContainsString('Previous owner', $text, 'Veicolo orfano: nessun precedente proprietario');
        self::assertStringContainsString('Reminders, totals and charts of this vehicle, history included, are now yours.', $text);
    }

    public function testVehicleHandedOverStatesWhetherReadOnlyAccessIsKept(): void
    {
        $builder = $this->mailBuilder();
        $kept = $builder->vehicleHandedOver($this->person('Mario', 'Rossi'), $this->transferVehicle(), $this->person('Luca', 'Verdi'), $this->person('Anna', 'Bianchi'), true);
        $lost = $builder->vehicleHandedOver($this->person('Mario', 'Rossi', 'en'), $this->transferVehicle(), $this->person('Luca', 'Verdi'), $this->person('Anna', 'Bianchi'), false);

        self::assertSame('Brumy — Panda ha un nuovo proprietario', $kept->getSubject());
        self::assertStringContainsString('Anna Bianchi ha trasferito la proprietà del veicolo "Panda" nell\'organizzazione "Officina Rossi" a Luca Verdi.', (string) $kept->getTextBody());
        self::assertStringContainsString('Mantieni l\'accesso al veicolo in sola lettura.', (string) $kept->getTextBody());
        self::assertSame('Brumy — Panda has a new owner', $lost->getSubject());
        self::assertStringContainsString('You no longer have direct access to the vehicle.', (string) $lost->getTextBody());
        self::assertStringNotContainsString('read-only', (string) $lost->getTextBody());
    }
}
