import { useQuery } from '@tanstack/react-query';
import { getAbout } from '@/api/endpoints/about';

/** Il controllo delle versioni è giornaliero sul server: in sessione basta chiederlo una volta all'ora. */
const STALE_MS = 60 * 60 * 1000;

export const aboutKeys = { all: ['about'] as const };

export function useAbout() {
  return useQuery({ queryKey: aboutKeys.all, queryFn: getAbout, staleTime: STALE_MS });
}
