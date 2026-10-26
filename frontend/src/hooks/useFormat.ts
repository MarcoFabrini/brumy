import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
  formatBytes,
  formatCurrency,
  formatDate,
  formatDay,
  formatDecimal,
  formatFuelPrice,
  formatKm,
  formatMonth,
  intlLocale,
} from '@/lib/format';

/**
 * Formattatori di `lib/format` legati alla lingua dell'interfaccia: l'unico modo di formattare
 * numeri, importi e date nei componenti. Usa `useTranslation`, quindi il componente si rirenderizza
 * al cambio lingua; l'oggetto cambia identità solo con la lingua (sicuro nelle dipendenze di memo).
 */
export function useFormat() {
  const { i18n } = useTranslation();
  const locale = intlLocale(i18n.language);

  return useMemo(
    () => ({
      /** Locale `Intl` corrente ("it-IT" / "en-GB"). */
      locale,
      km: (km: number) => formatKm(km, locale),
      decimal: (n: number | null | undefined, maxFractionDigits?: number) => formatDecimal(n, maxFractionDigits, locale),
      /** Importi sempre in EUR: cambia solo la formattazione, non la valuta. */
      currency: (amount: string | number | null | undefined) => formatCurrency(amount, locale),
      /** Prezzo al litro con tre decimali (carburante). */
      fuelPrice: (price: string | number | null | undefined) => formatFuelPrice(price, locale),
      date: (iso: string | null | undefined) => formatDate(iso, locale),
      month: (yyyyMm: string) => formatMonth(yyyyMm, locale),
      /** Giorno breve per gli assi dei grafici ("25 ott"). */
      day: (iso: string) => formatDay(iso, locale),
      bytes: (bytes: number) => formatBytes(bytes, locale),
    }),
    [locale],
  );
}
