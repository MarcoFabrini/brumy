<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Entity\VehicleShare;
use App\Enum\OrgRole;
use App\Enum\ReminderUrgency;
use App\Enum\ShareRole;
use App\Message\SendReminderNotificationMessage;
use App\MessageHandler\SendReminderNotificationHandler;
use App\Repository\OrganizationMemberRepository;
use App\Repository\ReminderRepository;
use App\Repository\VehicleShareRepository;
use App\Service\UserValues;
use App\Service\VehicleOwnership;
use App\Service\VehicleOwnershipTransfer;
use App\Service\VehicleTransferException;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Il service di trasferimento senza il filtro del voter: lo stato riletto dopo il lock decide, e il
 * nuovo proprietario riceve le notifiche dei promemoria già in scadenza.
 */
final class VehicleOwnershipTransferTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;
    use MailerAssertionsTrait;

    private VehicleOwnershipTransfer $transfer;
    private EntityManagerInterface $em;
    private Vehicle $vehicle;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $shareRepo = $container->get(VehicleShareRepository::class);
        $this->transfer = new VehicleOwnershipTransfer(
            $this->em,
            $shareRepo,
            $container->get(OrganizationMemberRepository::class),
            $container->get(ReminderRepository::class),
            new VehicleOwnership($this->em, $shareRepo, $container->get(UserValues::class)),
        );

        $this->vehicle = VehicleFactory::createOne();
        $this->alice = $this->member(OrgRole::MEMBER, 'alice@test.it');
        $this->bob = $this->member(OrgRole::MEMBER, 'bob@test.it');
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $this->vehicle, 'user' => $this->alice]);
    }

    private function uid(User $user): int
    {
        return (int) $user->getId();
    }

    private function member(OrgRole $role, string $email): User
    {
        $user = UserFactory::createOne(['email' => $email]);
        OrganizationMemberFactory::createOne(['organization' => $this->vehicle->getOrganization(), 'user' => $user, 'role' => $role]);

        return $user;
    }

    /** @return array<int, string> userId => ruolo, solo gli share accettati */
    private function roles(): array
    {
        $this->em->clear();
        $out = [];
        foreach ($this->em->getRepository(VehicleShare::class)->findBy(['vehicle' => $this->vehicle->getId()]) as $share) {
            $out[(int) $share->getUser()->getId()] = $share->getRole()->value;
        }

        return $out;
    }

    public function testAStaleActorIsRejectedWithOwnershipChanged(): void
    {
        $carol = $this->member(OrgRole::MEMBER, 'carol@test.it');

        // Alice trasferisce a Bob...
        $this->transfer->transfer($this->vehicle, (int) $this->bob->getId(), true, $this->alice);
        // ...e la sua seconda richiesta, partita con l'autorizzazione vecchia (voter già passato), ora è un viewer
        try {
            $this->transfer->transfer($this->vehicle, (int) $carol->getId(), true, $this->alice);
            self::fail('Il trasferimento con autorizzazione scaduta deve fallire');
        } catch (VehicleTransferException $e) {
            self::assertSame('transfer.ownership_changed', $e->key);
            self::assertSame(409, $e->status);
        }

        self::assertSame([$this->uid($this->alice) => 'viewer', $this->uid($this->bob) => 'admin'], $this->roles(), 'La proprietà è rimasta a Bob');
    }

    public function testAnActorWhoLostTheOrganizationMembershipIsRejected(): void
    {
        $admin = $this->member(OrgRole::ADMIN, 'admin@test.it');
        $membership = $this->em->getRepository(OrganizationMember::class)->findOneBy(['user' => $admin]);
        self::assertNotNull($membership);
        $this->em->remove($membership);
        $this->em->flush();

        $this->expectException(VehicleTransferException::class);
        $this->expectExceptionMessage('transfer.ownership_changed');
        $this->transfer->transfer($this->vehicle, (int) $this->bob->getId(), true, $admin);
    }

    public function testAnActorDemotedFromAdminDuringTheWaitIsRejected(): void
    {
        $admin = $this->member(OrgRole::ADMIN, 'admin@test.it');
        // il ruolo cambia nel DB dopo che l'entity era già in memoria (il voter l'ha letta così)
        $membership = $this->em->getRepository(OrganizationMember::class)->findOneBy(['user' => $admin]);
        self::assertNotNull($membership);
        $this->em->getConnection()->executeStatement("UPDATE organization_members SET role = 'member' WHERE id = ?", [$membership->getId()]);

        try {
            $this->transfer->transfer($this->vehicle, (int) $this->bob->getId(), true, $admin);
            self::fail('Un admin declassato nell\'attesa non può più trasferire');
        } catch (VehicleTransferException $e) {
            self::assertSame('transfer.ownership_changed', $e->key);
        }
        self::assertSame([$this->uid($this->alice) => 'admin'], $this->roles());
    }

    public function testASameOwnerRequestAfterTheOwnershipMovedIsRejected(): void
    {
        $admin = $this->member(OrgRole::ADMIN, 'admin@test.it');
        $this->transfer->transfer($this->vehicle, (int) $this->bob->getId(), true, $admin);

        try {
            $this->transfer->transfer($this->vehicle, (int) $this->bob->getId(), true, $admin);
            self::fail('Bob è già il proprietario');
        } catch (VehicleTransferException $e) {
            self::assertSame('transfer.same_owner', $e->key);
        }
        self::assertSame('admin', $this->roles()[$this->uid($this->bob)]);
    }

    public function testARejectedTransferWritesNothing(): void
    {
        $reminder = ReminderFactory::createOne(['organization' => $this->vehicle->getOrganization(), 'vehicle' => $this->vehicle]);
        $reminder->markNotified(ReminderUrgency::SOON);
        $this->em->flush();

        try {
            $this->transfer->transfer($this->vehicle, 424242, false, $this->alice);
            self::fail('Destinatario non valido');
        } catch (VehicleTransferException $e) {
            self::assertSame('transfer.recipient_invalid', $e->key);
        }

        self::assertSame([$this->uid($this->alice) => 'admin'], $this->roles());
        self::assertSame('soon', $this->em->getConnection()->fetchOne('SELECT notified_urgency FROM reminders WHERE id = ?', [$reminder->getId()]));
    }

    public function testAReminderAlreadyNotifiedToTheOldOwnerIsNotifiedAgainOnlyToTheNewOwner(): void
    {
        $org = $this->vehicle->getOrganization();
        $orgAdmin = $this->member(OrgRole::ADMIN, 'orgadmin@test.it');
        $viewer = $this->member(OrgRole::MEMBER, 'viewer@test.it');
        VehicleShareFactory::createOne(['vehicle' => $this->vehicle, 'user' => $viewer]);
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $this->vehicle,
            'description' => 'Tagliando Panda',
            'dueDate' => new \DateTimeImmutable('+10 days'),
        ]);
        $handler = static::getContainer()->get(SendReminderNotificationHandler::class);
        $message = new SendReminderNotificationMessage((int) $reminder->getId());
        $recipients = static function (): array {
            $to = [];
            foreach (self::getMailerMessages() as $mail) {
                if ($mail instanceof Email && str_contains((string) $mail->getSubject(), 'Tagliando Panda')) {
                    $to[] = $mail->getTo()[0]->getAddress();
                }
            }
            sort($to);

            return $to;
        };

        // Primo invio: il vecchio proprietario riceve il livello "soon"
        $handler($message);
        self::assertSame(['alice@test.it'], $recipients());
        // Secondo invio senza trasferimento: già notificato, non riparte
        $handler($message);
        self::assertSame(['alice@test.it'], $recipients());

        $this->transfer->transfer($this->vehicle, (int) $this->bob->getId(), true, $orgAdmin);
        $this->em->clear(); // un altro processo (il worker) rilegge il promemoria dal DB
        $handler($message);

        // Solo il nuovo proprietario in più: né il vecchio (ora viewer), né l'admin dell'org, né il viewer
        self::assertSame(['alice@test.it', 'bob@test.it'], $recipients());
    }
}
