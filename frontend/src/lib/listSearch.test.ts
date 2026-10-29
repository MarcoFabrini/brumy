import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import {
  buildSearchQuery,
  foldText,
  LIST_SEARCH_FAMILIES,
  normalizeSearch,
  SEARCH_MAX_LENGTH,
  searchTerms,
  shouldShowListSearch,
  termHints,
  type Translate,
} from './listSearch';

const t: Translate = (key, options) => i18n.t(key, options);

/** Gli hint della query string, parola per parola. */
function hintsOf(query: string): Record<string, string[]> {
  const result: Record<string, string[]> = {};
  for (const [name, value] of new URLSearchParams(query)) {
    if (name !== 'q') (result[name] ??= []).push(value);
  }
  return result;
}

describe('listSearch', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('it');
  });

  afterEach(async () => {
    await i18n.changeLanguage('it');
  });

  describe('normalizeSearch e searchTerms', () => {
    it('riduce ogni tipo di spazio a uno e toglie quelli ai bordi', () => {
      expect(normalizeSearch('  cambio  olio\t2025 \n')).toBe('cambio olio 2025');
    });

    it(`taglia a ${SEARCH_MAX_LENGTH} caratteri contando le emoji come uno (come mb_substr)`, () => {
      expect(Array.from(normalizeSearch('🚗'.repeat(150)))).toHaveLength(SEARCH_MAX_LENGTH);
      expect(normalizeSearch(`${'a'.repeat(99)} bcd`)).toBe('a'.repeat(99));
    });

    it('tiene le prime sei parole, come il server', () => {
      expect(searchTerms('uno  due tre quattro cinque sei sette')).toEqual(['uno', 'due', 'tre', 'quattro', 'cinque', 'sei']);
      expect(searchTerms('   ')).toEqual([]);
    });
  });

  it('foldText toglie accenti e maiuscole', () => {
    expect(foldText('Perché ÀNCORA')).toBe('perche ancora');
  });

  describe('valori tradotti', () => {
    it('"tagliando" e "tagl" trovano il tipo di lavoro Tagliando', () => {
      expect(termHints('tagliando', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT')).toEqual(['maintenance_type:inspection']);
      expect(termHints('TAGL', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT')).toEqual(['maintenance_type:inspection']);
    });

    it('"revisione" è la sezione dei lavori e il tipo di promemoria', () => {
      expect(termHints('revisione', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT')).toEqual(['maintenance_category:inspection']);
      expect(termHints('revisione', LIST_SEARCH_FAMILIES.reminder, t, 'it-IT')).toEqual(['reminder_type:inspection']);
    });

    it('trova l\'etichetta che contiene la parola, senza accenti', () => {
      expect(termHints('olio', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT')).toEqual(['maintenance_type:oil_change']);
      expect(termHints('IMPREVÌSTI', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT')).toEqual(['maintenance_category:unscheduled']);
    });

    it('le famiglie sono quelle dell\'elenco', () => {
      expect(termHints('diesel', LIST_SEARCH_FAMILIES.refueling, t, 'it-IT')).toEqual(['fuel_type:diesel', 'fuel_type:biodiesel']);
      expect(termHints('diesel', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT')).toEqual([]);
      expect(termHints('parziale', LIST_SEARCH_FAMILIES.refueling, t, 'it-IT')).toEqual(['refueling_tank:partial']);
      expect(termHints('annuale', LIST_SEARCH_FAMILIES.expense, t, 'it-IT')).toEqual(['recurring_period:yearly']);
      expect(termHints('assicuraz', LIST_SEARCH_FAMILIES.expense, t, 'it-IT')).toEqual(['expense_section:insurance']);
      expect(termHints('altre', LIST_SEARCH_FAMILIES.expense, t, 'it-IT')).toEqual(['expense_section:other']);
      expect(termHints('moto', LIST_SEARCH_FAMILIES.vehicle, t, 'it-IT')).toEqual(['vehicle_type:motorcycle']);
    });

    it('una lettera sola non cerca tra le etichette', () => {
      expect(termHints('t', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT')).toEqual([]);
    });

    it('"marzo" e "mar" sono marzo, non maggio; "ma" nessun mese', () => {
      expect(termHints('marzo', LIST_SEARCH_FAMILIES.component, t, 'it-IT')).toEqual(['month:3']);
      expect(termHints('mar', LIST_SEARCH_FAMILIES.component, t, 'it-IT')).toEqual(['month:3']);
      expect(termHints('ma', LIST_SEARCH_FAMILIES.component, t, 'it-IT')).toEqual([]);
      expect(termHints('giu', LIST_SEARCH_FAMILIES.component, t, 'it-IT')).toEqual(['month:6']);
    });

    it('in inglese usa le etichette e i mesi inglesi', async () => {
      await i18n.changeLanguage('en');
      expect(termHints('march', LIST_SEARCH_FAMILIES.component, t, 'en-GB')).toEqual(['month:3']);
      expect(termHints('marzo', LIST_SEARCH_FAMILIES.component, t, 'en-GB')).toEqual([]);
      expect(termHints('diesel', LIST_SEARCH_FAMILIES.vehicle, t, 'en-GB')).toEqual(['fuel_type:diesel', 'fuel_type:biodiesel']);
      expect(termHints('gasol', LIST_SEARCH_FAMILIES.vehicle, t, 'en-GB')).toEqual(['fuel_type:gasoline']);
      expect(termHints('inspection', LIST_SEARCH_FAMILIES.maintenance, t, 'en-GB')).toEqual([
        'maintenance_type:inspection',
        'maintenance_category:inspection',
      ]);
      expect(termHints('tagliando', LIST_SEARCH_FAMILIES.maintenance, t, 'en-GB')).toEqual([]);
    });
  });

  describe('buildSearchQuery', () => {
    it('vuota senza parole', () => {
      expect(buildSearchQuery('  ', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT')).toBe('');
    });

    it('manda le parole e gli hint con l\'indice della parola', () => {
      const query = buildSearchQuery(' tagliando  marzo 2025 ', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT');

      expect(new URLSearchParams(query).get('q')).toBe('tagliando marzo 2025');
      expect(hintsOf(query)).toEqual({ 'hint[0][]': ['maintenance_type:inspection'], 'hint[1][]': ['month:3'] });
    });

    it('stessa ricerca, stessa stringa', () => {
      const a = buildSearchQuery('olio marzo', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT');
      const b = buildSearchQuery('olio   marzo', LIST_SEARCH_FAMILIES.maintenance, t, 'it-IT');
      expect(a).toBe(b);
    });

    it('al massimo 30 valori per parola', () => {
      // Ogni etichetta contiene la parola: 15 tipi di lavoro + 9 carburanti + 10 tipi di veicolo + 10 di promemoria
      const everything: Translate = () => 'tutto';
      const hints = termHints('tutto', ['maintenance_type', 'fuel_type', 'vehicle_type', 'reminder_type'], everything, 'it-IT');
      expect(hints).toHaveLength(30);
      expect(hints[0]).toBe('maintenance_type:oil_change');
    });
  });

  it('shouldShowListSearch: nascosto solo per un elenco caricato e vuoto senza ricerca', () => {
    expect(shouldShowListSearch('', '', [])).toBe(false);
    expect(shouldShowListSearch('', '', undefined)).toBe(true);
    expect(shouldShowListSearch('', '', [1])).toBe(true);
    expect(shouldShowListSearch('tagl', '', [])).toBe(true);
    expect(shouldShowListSearch('', 'tagl', [])).toBe(true);
  });
});
