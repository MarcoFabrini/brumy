<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Entity\UserValue;
use App\Enum\ExpenseSection;
use App\Enum\RecurringPeriod;
use App\Validator\DecimalString;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ExpenseRequest
{
    public function __construct(
        #[Assert\Positive]
        public int $vehicleId = 0,

        #[Assert\NotBlank]
        #[Assert\Date]
        // Date ISO: il confronto tra stringhe è quello cronologico. Un inizio di secoli fa non ha senso per una
        // spesa e, con una cadenza ravvicinata, farebbe generare migliaia di addebiti a ogni lettura.
        #[Assert\GreaterThanOrEqual('1970-01-01')]
        public string $occurredAt = '',

        /**
         * Nome della categoria (altre spese) o del tipo di tassa (tasse): si cerca nella lista del proprietario del
         * veicolo o diventa una voce nuova; obbligatorio tranne che per l'assicurazione ({@see self::validateSection()}).
         * Lo slug di un vecchio client ('toll') punta alla voce di partenza.
         */
        #[Assert\Length(max: 200)]
        public string $category = '',

        public ExpenseSection $section = ExpenseSection::OTHER,

        /** Compagnia, solo per l'assicurazione (facoltativa). */
        #[Assert\Length(max: 200)]
        public ?string $insurer = null,

        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public string $description = '',

        #[DecimalString(maxDecimals: 2, maxIntegerDigits: 8, message: 'validation.amount_format')]
        public string $amount = '0',

        public bool $recurring = false,
        public ?RecurringPeriod $recurringPeriod = null,

        /** Ultima data di addebito (Y-m-d, inclusa); vuota = ancora in corso. */
        #[Assert\Date]
        public ?string $recurringUntil = null,

        #[Assert\Length(max: 2000)]
        public ?string $notes = null,

        /**
         * Solo in creazione, per revisione, assicurazione e bollo/superbollo: data della prossima scadenza, che
         * aggiorna o crea il promemoria ({@see \App\Service\RecordReminders}); null = nessun promemoria.
         */
        #[Assert\Date]
        public ?string $nextDueDate = null,
    ) {
    }

    /** Categoria o tipo di tassa obbligatori (vuoti anche se di soli spazi Unicode); l'assicurazione ha la compagnia. */
    #[Assert\Callback]
    public function validateSection(ExecutionContextInterface $context): void
    {
        $blank = UserValue::normalizeName($this->category) === '';
        if ($blank && $this->section !== ExpenseSection::INSURANCE) {
            $message = $this->section === ExpenseSection::TAX ? 'expense.tax_type.required' : 'expense.category.required';
            $context->buildViolation($message)->atPath('category')->addViolation();
        }
    }

    /**
     * Il periodo ha senso solo per le spese ricorrenti (e lì è obbligatorio); la data di fine pure
     * (opzionale) e non può precedere il primo addebito.
     */
    #[Assert\Callback]
    public function validateRecurrence(ExecutionContextInterface $context): void
    {
        if ($this->recurring && $this->recurringPeriod === null) {
            $context->buildViolation('expense.recurring_period_required')->atPath('recurringPeriod')->addViolation();
        }
        if (!$this->recurring && $this->recurringPeriod !== null) {
            $context->buildViolation('expense.recurring_period_unexpected')->atPath('recurringPeriod')->addViolation();
        }

        $until = $this->recurringUntil;
        if ($until === null || $until === '') {
            return;
        }
        if (!$this->recurring) {
            $context->buildViolation('expense.recurring_until_unexpected')->atPath('recurringUntil')->addViolation();

            return;
        }
        // Le date in formato Y-m-d si confrontano come stringhe; un formato errato lo segnala già Assert\Date.
        $isDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        if ($isDate($until) && $isDate($this->occurredAt) && $until < $this->occurredAt) {
            $context->buildViolation('expense.recurring_until_before_start')->atPath('recurringUntil')->addViolation();
        }
    }
}
