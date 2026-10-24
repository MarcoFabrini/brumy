import { z } from 'zod';

/**
 * Messaggi zod di default (inglese, es. "Invalid input: expected int, received number") → chiavi i18n
 * `errors.common.*`, che `tErr` nei form traduce. Si applica solo dove lo schema NON ha un messaggio
 * esplicito (quelli espliciti hanno la precedenza in zod); `undefined` lascia il messaggio di zod.
 */
const customError: z.core.$ZodErrorMap = (issue) => {
  switch (issue.code) {
    case 'invalid_type':
      if (issue.input === undefined || issue.input === null) return 'common.required';
      if (issue.expected === 'int') return 'common.must_be_integer';
      if (issue.expected === 'number') return 'common.invalid_number';
      return 'common.invalid_value';
    case 'too_small':
      return issue.origin === 'string' || issue.origin === 'array' ? 'common.too_short' : 'common.too_small';
    case 'too_big':
      return issue.origin === 'string' || issue.origin === 'array' ? 'common.too_long' : 'common.too_big';
    case 'invalid_format':
    case 'invalid_value':
      return 'common.invalid_value';
    default:
      return undefined;
  }
};

z.config({ customError });
