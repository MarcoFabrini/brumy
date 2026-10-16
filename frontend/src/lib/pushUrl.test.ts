import { describe, expect, it } from 'vitest';
import { sameOriginPushUrl } from './pushUrl';

const ORIGIN = 'https://brumy.example.test';

describe('sameOriginPushUrl', () => {
  it.each([
    ['/reminders/12', '/reminders/12'],
    ['/vehicles/3?tab=stats#top', '/vehicles/3?tab=stats#top'],
    [`${ORIGIN}/settings`, '/settings'],
    ['reminders/5', '/reminders/5'],
  ])('tiene i percorsi della stessa origine: %s', (raw, expected) => {
    expect(sameOriginPushUrl(raw, ORIGIN)).toBe(expected);
  });

  it.each([
    'https://evil.example.test/login',
    '//evil.example.test/login',
    'http://brumy.example.test/settings',
    'javascript:alert(1)',
    'data:text/html,<b>x</b>',
  ])('scarta le destinazioni fuori origine: %s', (raw) => {
    expect(sameOriginPushUrl(raw, ORIGIN)).toBe('/');
  });

  it.each([undefined, null, 42, {}, ''])('ricade su "/" per un valore non valido: %s', (raw) => {
    expect(sameOriginPushUrl(raw, ORIGIN)).toBe('/');
  });
});
