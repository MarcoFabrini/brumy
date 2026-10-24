import { Fragment, type ReactNode } from 'react';

/** Riga secondaria delle liste: pezzi separati da " · " (i vuoti saltati), ognuno nel suo span. */
export function MetaLine({ parts }: { parts: ReactNode[] }) {
  const shown = parts.filter((p) => p !== null && p !== undefined && p !== false && p !== '');
  return (
    <span className="flex flex-wrap items-center gap-x-1.5">
      {shown.map((part, i) => (
        <Fragment key={i}>
          {i > 0 && <span aria-hidden="true">·</span>}
          <span className="min-w-0">{part}</span>
        </Fragment>
      ))}
    </span>
  );
}
