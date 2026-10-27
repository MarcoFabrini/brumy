import { authFetch } from '@/api/client';
import { aboutSchema, type About } from '@/api/types/about';

export async function getAbout(): Promise<About> {
  return aboutSchema.parse(await authFetch<unknown>('/api/about'));
}
