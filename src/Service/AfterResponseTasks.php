<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Lavoro da fare DOPO aver inviato la risposta (kernel.terminate), sullo stesso processo e senza worker.
 *
 * Serve a togliere dal tempo di risposta un lavoro lento ma non essenziale per il client: l'invio di
 * un'email SMTP in `forgot-password`, che altrimenti renderebbe distinguibile (per tempi) un account
 * esistente da uno inesistente. Per lavoro che deve sopravvivere a un crash o essere ritentato si usa
 * Messenger, non questo. Un task che solleva un'eccezione viene solo loggato: la risposta è già partita.
 *
 * Dipende dal fatto che il server chiuda la connessione col client prima di kernel.terminate
 * (fastcgi_finish_request / FrankenPHP); nei test e in CLI kernel.terminate gira comunque dopo la risposta.
 */
final class AfterResponseTasks
{
    /** @var list<array{task: \Closure(): void, context: array<string, scalar|null>}> */
    private array $tasks = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param \Closure(): void                $task    eseguito dopo la risposta
     * @param array<string, scalar|null>      $context finisce nel log se il task fallisce (niente dati personali)
     */
    public function defer(\Closure $task, array $context = []): void
    {
        $this->tasks[] = ['task' => $task, 'context' => $context];
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function run(): void
    {
        // Svuoto PRIMA di eseguire: in un processo a vita lunga un task non deve girare due volte.
        $tasks = $this->tasks;
        $this->tasks = [];

        foreach ($tasks as ['task' => $task, 'context' => $context]) {
            try {
                $task();
            } catch (\Throwable $e) {
                $this->logger->error('Task dopo la risposta fallito', $context + ['error' => LogRedactor::scrub($e->getMessage())]);
            }
        }
    }
}
