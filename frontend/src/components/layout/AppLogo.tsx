import { cn } from '@/lib/utils';

/**
 * Logo dell'app come icona d'app (sfondo bianco, strada bianca): leggibile sia sul tema chiaro sia su quello scuro.
 * Accanto al nome dell'organizzazione è decorativo (`alt=""`), il testo che segue dice già dove siamo.
 */
export function AppLogo({ className }: { className?: string }) {
  return <img src="/apple-touch-icon.png" alt="" width={36} height={36} className={cn('shrink-0 rounded-lg', className)} />;
}
