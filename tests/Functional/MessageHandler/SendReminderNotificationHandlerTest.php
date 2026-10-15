<?php

declare(strict_types=1);

namespace App\Tests\Functional\MessageHandler;

use App\Entity\Organization;
use App\Entity\Reminder;
use App\Entity\User;
use App\Entity\Vehicle;
use App\Enum\OrgRole;
use App\Enum\ReminderUrgency;
use App\Entity\PushSubscription;
use App\Enum\PushPlatform;
use App\Message\SendReminderNotificationMessage;
use App\MessageHandler\SendReminderNotificationHandler;
use App\Repository\OrganizationMemberRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\ReminderRepository;
use App\Service\AppClock;
use App\Service\AppMailer;
use App\Service\MailBuilder;
use App\Service\Push\PushDeliveryResult;
use App\Service\Push\PushDispatcher;
use App\Service\Push\PushNotifierInterface;
use App\Service\Push\PushPayload;
use App\Service\VehicleStatsService;
use App\Tests\Factory\OrganizationMemberFactory;
use App\Tests\Factory\RefuelingFactory;
use App\Tests\Factory\ReminderFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\VehicleFactory;
use App\Tests\Factory\VehicleShareFactory;
use App\Tests\Support\SpyLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Test del flow async dei reminder. Verifica che l'handler:
 * - skippi reminder completati (early return)
 * - chiami il PushDispatcher (in test = FakePushNotifier che logga)
 * - invii email Mailer (in test = MailerInterface con transport null o profiler)
 *
 * Nota: in env test il Messenger transport "async" è in-memory, quindi possiamo
 * istanziare l'handler direttamente e invocarlo come funzione.
 */
final class SendReminderNotificationHandlerTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    private SendReminderNotificationHandler $handler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->handler = static::getContainer()->get(SendReminderNotificationHandler::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testHandlerSkipsCompletedReminder(): void
    {
        $reminder = ReminderFactory::createOne([
            'completedAt' => new \DateTimeImmutable(), // già completato
        ]);

        // L'handler non deve sollevare eccezioni né accedere a member/push/mail
        ($this->handler)(new SendReminderNotificationMessage((int) $reminder->getId()));

        self::assertCount(0, static::getMailerMessages());
    }

    public function testHandlerSkipsMissingReminder(): void
    {
        ($this->handler)(new SendReminderNotificationMessage(999999));
        self::assertCount(0, static::getMailerMessages());
    }

    public function testHandlerNotifiesOnlyTheVehicleOwner(): void
    {
        $vehicle = VehicleFactory::createOne();
        $org = $vehicle->getOrganization();

        $orgOwner = UserFactory::createOne(['email' => 'org-owner@test.it']);
        $vehicleOwner = UserFactory::createOne(['email' => 'vehicle-owner@test.it']);
        $sharedMember = UserFactory::createOne(['email' => 'shared@test.it']);
        $strangerMember = UserFactory::createOne(['email' => 'stranger@test.it']);
        $uPending = UserFactory::createOne(['email' => 'pending@test.it']);

        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $orgOwner, 'role' => OrgRole::OWNER]);
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $vehicleOwner, 'role' => OrgRole::MEMBER]);
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $sharedMember, 'role' => OrgRole::MEMBER]);
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $strangerMember, 'role' => OrgRole::MEMBER]);
        OrganizationMemberFactory::createOne([
            'organization' => $org, 'user' => $uPending,
            'role' => OrgRole::MEMBER,
            'acceptedAt' => null, // pending
        ]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $vehicleOwner]);
        // Il membro "shared" ha una condivisione in sola lettura; "stranger" nessun accesso.
        VehicleShareFactory::createOne(['vehicle' => $vehicle, 'user' => $sharedMember]);

        $reminder = ReminderFactory::createOne([
            'organization' => $org,
            'vehicle' => $vehicle,
            'description' => 'Revisione',
            'dueDate' => new \DateTimeImmutable('+15 days'),
        ]);

        ($this->handler)(new SendReminderNotificationMessage((int) $reminder->getId()));

        // Filtra solo le mail generate dal nostro handler (subject "Brumy — Scadenza: ...").
        // Necessario perché Symfony profiler collector può accumulare messaggi inter-test
        // se non resettato; restringere via subject + recipients noti rende l'assertion
        // robusta indipendentemente dall'isolamento del collector.
        $ourMessages = $this->filterHandlerEmails($reminder->getDescription());
        $recipients = $this->extractRecipients($ourMessages);
        sort($recipients);

        // Solo il proprietario del veicolo: non l'owner dell'org (vede i veicoli altrui ma non sono
        // suoi), non chi ha la condivisione in sola lettura, né chi non ha accesso o è pending.
        self::assertSame(['vehicle-owner@test.it'], array_values(array_unique($recipients)));
        self::assertEmailTextBodyContains($ourMessages[0], 'Revisione');
    }

    public function testHandlerCallsPushDispatcherForUsersWithSubscriptions(): void
    {
        $vehicle = VehicleFactory::createOne();
        $org = $vehicle->getOrganization();
        $user = UserFactory::createOne(['email' => 'pushed@test.it']);
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $user, 'role' => OrgRole::OWNER]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $user]);

        // Crea una push subscription Web
        $sub = (new PushSubscription())
            ->setUser($user)
            ->setPlatform(PushPlatform::WEB)
            ->setEndpoint('https://push.example.com/endpoint')
            ->setP256dh('p256dh-key')
            ->setAuthSecret('auth-secret');
        $this->em->persist($sub);
        $this->em->flush();

        $reminder = ReminderFactory::createOne([
            'organization' => $org,
            'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('+10 days'),
        ]);

        // In env test PushDispatcher usa FakePushNotifier (logga, ritorna ok)
        ($this->handler)(new SendReminderNotificationMessage((int) $reminder->getId()));

        $ourMessages = $this->filterHandlerEmails($reminder->getDescription());
        $recipients = $this->extractRecipients($ourMessages);

        self::assertSame(['pushed@test.it'], array_values(array_unique($recipients)));
    }

    public function testHandlerSkipsReminderThatIsNotDueYet(): void
    {
        [$vehicle, $org] = $this->vehicleWithOwner('early@test.it');
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('+100 days'), 'notifyDaysBefore' => 30,
            'description' => 'Ancora presto',
        ]);

        ($this->handler)(new SendReminderNotificationMessage((int) $reminder->getId()));

        self::assertCount(0, $this->filterHandlerEmails('Ancora presto'));
        self::assertNull($reminder->getNotifiedUrgency());
    }

    public function testKmReminderIsNotifiedWhenTheOdometerGetsCloseAndMentionsKm(): void
    {
        [$vehicle, $org] = $this->vehicleWithOwner('km@test.it', initialKm: 99_500);
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => null, 'dueKm' => 100_000, 'description' => 'Cambio olio',
        ]);

        ($this->handler)(new SendReminderNotificationMessage((int) $reminder->getId()));

        $mails = $this->filterHandlerEmails('Cambio olio');
        self::assertCount(1, $mails);
        self::assertEmailTextBodyContains($mails[0], '100.000 km');
        self::assertEmailTextBodyContains($mails[0], '99.500 km');
        self::assertSame(ReminderUrgency::SOON, $reminder->getNotifiedUrgency());
    }

    public function testKmReminderFarFromThresholdIsNotNotified(): void
    {
        [$vehicle, $org] = $this->vehicleWithOwner('kmfar@test.it', initialKm: 20_000);
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => null, 'dueKm' => 100_000, 'description' => 'Tagliando lontano',
        ]);

        ($this->handler)(new SendReminderNotificationMessage((int) $reminder->getId()));

        self::assertCount(0, $this->filterHandlerEmails('Tagliando lontano'));
    }

    public function testOneNotificationPerLevelThenEscalatesWhenKmArePassed(): void
    {
        [$vehicle, $org] = $this->vehicleWithOwner('esc@test.it', initialKm: 99_500);
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => null, 'dueKm' => 100_000, 'description' => 'Gomme',
        ]);
        $message = new SendReminderNotificationMessage((int) $reminder->getId());

        ($this->handler)($message);
        ($this->handler)($message); // doppione in coda / cron rieseguito: scartato
        self::assertCount(1, $this->filterHandlerEmails('Gomme'), 'stesso livello: una sola notifica');

        // L'utente registra un rifornimento oltre la soglia → "scaduto": nuova notifica, e basta.
        RefuelingFactory::createOne(['organization' => $org, 'vehicle' => $vehicle, 'km' => 100_400]);
        ($this->handler)($message);
        ($this->handler)($message);

        $mails = $this->filterHandlerEmails('Gomme');
        self::assertCount(2, $mails);
        self::assertEmailHtmlBodyContains($mails[1], 'Scadenza superata');
        self::assertSame(ReminderUrgency::OVERDUE, $reminder->getNotifiedUrgency());
    }

    public function testHandlerDoesNothingWhenAnotherWorkerAlreadyClaimedTheLevel(): void
    {
        [$vehicle, $org] = $this->vehicleWithOwner('race@test.it');
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('-2 days'), 'description' => 'Race',
        ]);

        // Un altro worker ha già preso in carico il livello "scaduto" (l'entity in memoria è ancora stale).
        self::assertTrue(
            static::getContainer()->get(\App\Repository\ReminderRepository::class)
                ->claimNotification((int) $reminder->getId(), ReminderUrgency::OVERDUE, null),
        );

        ($this->handler)(new SendReminderNotificationMessage((int) $reminder->getId()));

        self::assertCount(0, $this->filterHandlerEmails('Race'), 'Il claim atomico evita il doppio invio');
    }

    public function testAnonymizedAccountsAreNeverNotified(): void
    {
        [$vehicle, $org] = $this->vehicleWithOwner('deleted-7-abc@anonymized.local');
        OrganizationMemberFactory::createOne([
            'organization' => $org,
            'user' => UserFactory::createOne(['email' => 'vivo@test.it']),
            'role' => OrgRole::ADMIN,
        ]);
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('-1 day'), 'description' => 'Anon',
        ]);

        ($this->handler)(new SendReminderNotificationMessage((int) $reminder->getId()));

        // Il proprietario è anonimizzato: nessuno riceve nulla (l'admin dell'org non è il proprietario).
        self::assertSame([], $this->extractRecipients($this->filterHandlerEmails('Anon')));
    }

    public function testReminderWithoutEligibleOwnerIsReleasedSoItCanBeNotifiedLater(): void
    {
        // Il proprietario è anonimizzato: nessun destinatario idoneo
        [$vehicle, $org] = $this->vehicleWithOwner('deleted-9-abc@anonymized.local');
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('-1 day'), 'description' => 'Senza owner',
        ]);
        $logger = new SpyLogger();
        $handler = new SendReminderNotificationHandler(
            static::getContainer()->get(\App\Repository\ReminderRepository::class),
            static::getContainer()->get(\App\Repository\OrganizationMemberRepository::class),
            static::getContainer()->get(\App\Service\VehicleStatsService::class),
            static::getContainer()->get(\App\Service\Push\PushDispatcher::class),
            static::getContainer()->get(\App\Service\AppMailer::class),
            static::getContainer()->get(\App\Service\MailBuilder::class),
            $this->em,
            $logger,
            static::getContainer()->get(\App\Service\AppClock::class),
        );
        $message = new SendReminderNotificationMessage((int) $reminder->getId());

        $handler($message);

        $this->em->clear();
        $fresh = $this->em->find(\App\Entity\Reminder::class, $reminder->getId());
        self::assertNull($fresh?->getNotifiedUrgency(), 'Nessuno è stato avvisato: il livello non risulta notificato');
        self::assertNull($fresh?->getLastNotifiedAt());
        self::assertSame([], $this->filterHandlerEmails('Senza owner'));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['reminder_id' => $reminder->getId(), 'vehicle_id' => $vehicle->getId()], $logger->records[0]['context']);

        // Appena il veicolo ha un proprietario idoneo, il giro successivo lo avvisa
        $newOwner = UserFactory::createOne(['email' => 'arrived-later@test.it']);
        OrganizationMemberFactory::createOne(['organization' => $org, 'user' => $newOwner, 'role' => OrgRole::MEMBER]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $this->em->find(\App\Entity\Vehicle::class, $vehicle->getId()), 'user' => $newOwner]);

        $handler($message);

        self::assertSame(['arrived-later@test.it'], $this->extractRecipients($this->filterHandlerEmails('Senza owner')));
        $this->em->clear();
        self::assertSame(ReminderUrgency::OVERDUE, $this->em->find(\App\Entity\Reminder::class, $reminder->getId())?->getNotifiedUrgency());
    }

    public function testReleaseRestoresTheLastNotifiedTimestamp(): void
    {
        $reminder = ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('-2 days')]);
        $repo = static::getContainer()->get(\App\Repository\ReminderRepository::class);
        $id = (int) $reminder->getId();

        $repo->claimNotification($id, ReminderUrgency::OVERDUE, null);
        $repo->releaseNotification($id, ReminderUrgency::OVERDUE, null, null);

        $this->em->clear();
        $fresh = $this->em->find(\App\Entity\Reminder::class, $id);
        self::assertNull($fresh?->getNotifiedUrgency());
        self::assertNull($fresh?->getLastNotifiedAt(), 'Nessuno è stato notificato: niente timestamp');
    }

    public function testClaimIsCompareAndSwap(): void
    {
        $reminder = ReminderFactory::createOne(['dueDate' => new \DateTimeImmutable('-2 days')]);
        $repo = static::getContainer()->get(\App\Repository\ReminderRepository::class);
        $id = (int) $reminder->getId();

        self::assertTrue($repo->claimNotification($id, ReminderUrgency::SOON, null));
        self::assertFalse($repo->claimNotification($id, ReminderUrgency::SOON, null), 'già preso');
        self::assertTrue($repo->claimNotification($id, ReminderUrgency::OVERDUE, ReminderUrgency::SOON));

        $repo->releaseNotification($id, ReminderUrgency::OVERDUE, ReminderUrgency::SOON);
        self::assertTrue($repo->claimNotification($id, ReminderUrgency::OVERDUE, ReminderUrgency::SOON), 'rilasciato: si può riprovare');
    }

    public function testWhenNothingIsDeliveredTheClaimIsReleasedAndMessengerRetriesThenItGoesThrough(): void
    {
        [$vehicle, $org, $owner] = $this->vehicleWithOwnerAndDevice('unreachable@test.it');
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('-1 day'), 'description' => 'Tutto giù',
        ]);
        $message = new SendReminderNotificationMessage((int) $reminder->getId());

        // SMTP giù e push service che rifiuta: nessun canale consegna
        $broken = $this->handlerWith(self::failingMailer(), self::pushNotifier(PushDeliveryResult::failed('push_service_down')));
        try {
            $broken($message);
            self::fail('Senza nessuna consegna l\'handler deve sollevare, così Messenger ritenta');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('delivery failed for all recipients', $e->getMessage());
        }

        $this->em->clear();
        $fresh = $this->em->find(Reminder::class, $reminder->getId());
        self::assertNull($fresh?->getNotifiedUrgency(), 'Nessuno ha ricevuto nulla: il livello non risulta notificato');
        self::assertNull($fresh?->getLastNotifiedAt());

        // Al retry i canali funzionano: la notifica parte (il livello era stato rilasciato)
        $this->handlerWith(static::getContainer()->get(MailerInterface::class), self::pushNotifier(PushDeliveryResult::ok()))($message);

        self::assertSame(['unreachable@test.it'], $this->extractRecipients($this->filterHandlerEmails('Tutto giù')));
        $this->em->clear();
        self::assertSame(ReminderUrgency::OVERDUE, $this->em->find(Reminder::class, $reminder->getId())?->getNotifiedUrgency());
    }

    public function testASingleWorkingChannelKeepsTheLevelNotifiedSoRetriesDoNotDuplicate(): void
    {
        [$vehicle, $org] = $this->vehicleWithOwnerAndDevice('pushonly@test.it');
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('-1 day'), 'description' => 'Solo push',
        ]);
        $message = new SendReminderNotificationMessage((int) $reminder->getId());
        $pushes = new \ArrayObject();
        $handler = $this->handlerWith(self::failingMailer(), self::pushNotifier(PushDeliveryResult::ok(), $pushes));

        $handler($message); // l'email fallisce ma la push è arrivata: nessuna eccezione
        $handler($message); // un secondo giro (doppione in coda, retry) non rimanda la push

        self::assertCount(1, $pushes, 'La push parte una volta sola');
        $this->em->clear();
        self::assertSame(ReminderUrgency::OVERDUE, $this->em->find(Reminder::class, $reminder->getId())?->getNotifiedUrgency());
    }

    public function testADeadPushSubscriptionIsRemovedAndTheEmailStillDelivers(): void
    {
        [$vehicle, $org] = $this->vehicleWithOwnerAndDevice('gone@test.it');
        $reminder = ReminderFactory::createOne([
            'organization' => $org, 'vehicle' => $vehicle,
            'dueDate' => new \DateTimeImmutable('-1 day'), 'description' => 'Device morto',
        ]);

        $this->handlerWith(
            static::getContainer()->get(MailerInterface::class),
            self::pushNotifier(PushDeliveryResult::failed('410 Gone', gone: true)),
        )(new SendReminderNotificationMessage((int) $reminder->getId()));

        self::assertSame(['gone@test.it'], $this->extractRecipients($this->filterHandlerEmails('Device morto')));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM push_subscriptions'), 'Il device revocato lato push service va dimenticato');
    }

    /**
     * Handler reale con mailer e notifier push sostituiti: gli unici due effetti collaterali che non si possono eseguire.
     */
    private function handlerWith(MailerInterface $mailer, PushNotifierInterface $notifier): SendReminderNotificationHandler
    {
        $container = static::getContainer();

        return new SendReminderNotificationHandler(
            $container->get(ReminderRepository::class),
            $container->get(OrganizationMemberRepository::class),
            $container->get(VehicleStatsService::class),
            new PushDispatcher([$notifier], $container->get(PushSubscriptionRepository::class), $this->em, new SpyLogger()),
            new AppMailer($mailer, new SpyLogger(), 'smtp://localhost'),
            $container->get(MailBuilder::class),
            $this->em,
            new SpyLogger(),
            $container->get(AppClock::class),
        );
    }

    private static function failingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('SMTP down');
            }
        };
    }

    /** @param \ArrayObject<int, PushPayload>|null $sent raccoglie i payload consegnati al notifier */
    private static function pushNotifier(PushDeliveryResult $result, ?\ArrayObject $sent = null): PushNotifierInterface
    {
        return new class($result, $sent) implements PushNotifierInterface {
            /** @param \ArrayObject<int, PushPayload>|null $sent */
            public function __construct(private readonly PushDeliveryResult $result, private readonly ?\ArrayObject $sent)
            {
            }

            public function supportedPlatforms(): array
            {
                return [PushPlatform::WEB];
            }

            public function send(PushSubscription $subscription, PushPayload $payload): PushDeliveryResult
            {
                $this->sent?->append($payload);

                return $this->result;
            }
        };
    }

    /**
     * @return array{0: Vehicle, 1: Organization, 2: User}
     */
    private function vehicleWithOwnerAndDevice(string $email): array
    {
        [$vehicle, $org] = $this->vehicleWithOwner($email);
        $owner = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $owner);
        $this->em->persist(
            (new PushSubscription())
                ->setUser($owner)
                ->setPlatform(PushPlatform::WEB)
                ->setEndpoint('https://push.example.com/'.bin2hex(random_bytes(4)))
                ->setP256dh('p256dh-key')
                ->setAuthSecret('auth-secret'),
        );
        $this->em->flush();

        return [$vehicle, $org, $owner];
    }

    /**
     * @return array{0: \App\Entity\Vehicle, 1: \App\Entity\Organization}
     */
    private function vehicleWithOwner(string $email, int $initialKm = 0): array
    {
        $vehicle = VehicleFactory::createOne(['initialKm' => $initialKm]);
        $org = $vehicle->getOrganization();
        $owner = UserFactory::createOne(['email' => $email]);
        OrganizationMemberFactory::createOne([
            'organization' => $org,
            'user' => $owner,
            'role' => OrgRole::OWNER,
        ]);
        VehicleShareFactory::new()->asAdmin()->create(['vehicle' => $vehicle, 'user' => $owner]);

        return [$vehicle, $org];
    }

    /**
     * Filtra mail generate dal nostro handler via subject prefix "Brumy — Scadenza: <description>".
     *
     * @return list<Email>
     */
    private function filterHandlerEmails(string $reminderDescription): array
    {
        $expectedSubject = 'Brumy — Scadenza: '.$reminderDescription;
        $filtered = [];
        foreach (static::getMailerMessages() as $msg) {
            if ($msg instanceof Email && $msg->getSubject() === $expectedSubject) {
                $filtered[] = $msg;
            }
        }
        return $filtered;
    }

    /**
     * @param list<Email> $messages
     * @return list<string>
     */
    private function extractRecipients(array $messages): array
    {
        $recipients = [];
        foreach ($messages as $msg) {
            foreach ($msg->getTo() as $addr) {
                $recipients[] = $addr->getAddress();
            }
        }
        return $recipients;
    }
}
