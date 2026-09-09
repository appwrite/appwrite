/**
 * Document for the email body preview frame.
 *
 * HTML bodies render exactly as sent. Plain-text bodies are escaped and wrapped
 * so the frame shows them verbatim, the way a mail client renders a text/plain
 * part.
 */
export function emailPreviewDocument(content: string, html: boolean): string {
  if (html) return content
  const text = content
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
  return `<!doctype html><html><head><meta charset="utf-8"><style>body{margin:16px;color:#111;background:#fff;font:14px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;white-space:pre-wrap;overflow-wrap:anywhere}</style></head><body>${text}</body></html>`
}
