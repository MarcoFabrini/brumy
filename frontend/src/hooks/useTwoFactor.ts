import { useMutation } from '@tanstack/react-query';
import {
  beginTwoFactorSetup,
  disableTwoFactor,
  enableTwoFactor,
  regenerateBackupCodes,
} from '@/api/endpoints/auth';
import { reloadUser } from '@/lib/authSession';

/**
 * Gestione della verifica in due passaggi dal profilo. Nessuna query in cache: lo stato (attiva, codici rimasti) è
 * nell'utente di `/me`, che ogni mutation riuscita ricarica nello store.
 */

/**
 * Ricarica l'utente senza legarvi l'esito della mutation: se `/me` fallisce proprio ora, l'operazione è comunque
 * riuscita e i codici di recupero vanno mostrati (sono visibili solo adesso). Lo store si allinea al prossimo `/me`.
 */
function refreshUser(): void {
  reloadUser().catch(() => undefined);
}

/** Nuovo segreto in attesa di conferma, con QR e chiave da inserire a mano. */
export function useBeginTwoFactorSetup() {
  return useMutation({ mutationFn: beginTwoFactorSetup });
}

/** Attiva con password + codice dell'app; ritorna i codici di recupero (da mostrare una volta sola). */
export function useEnableTwoFactor() {
  return useMutation({
    mutationFn: enableTwoFactor,
    onSuccess: refreshUser,
  });
}

/** Disattiva con password + codice dell'app o di recupero. */
export function useDisableTwoFactor() {
  return useMutation({
    mutationFn: disableTwoFactor,
    onSuccess: refreshUser,
  });
}

/** Codici di recupero nuovi (i precedenti smettono di valere) con password + codice. */
export function useRegenerateBackupCodes() {
  return useMutation({
    mutationFn: regenerateBackupCodes,
    onSuccess: refreshUser,
  });
}
