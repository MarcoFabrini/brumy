/** Fa scaricare al browser un Blob con il nome dato (link temporaneo con `download`). */
export function triggerDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
  // Revoca differita: su Safari/Firefox revocare subito può annullare il download di blob grandi.
  setTimeout(() => URL.revokeObjectURL(url), 30_000);
}
