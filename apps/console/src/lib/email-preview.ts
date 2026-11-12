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

/**
 * Preview document for an auth email template. The API stores the inner
 * template only; at send time the mail worker substitutes `{{b}}` / `{{/b}}`
 * with `<strong>` tags and wraps the result in its base layout. Mirror both so
 * the preview matches what recipients get instead of showing tokens in the
 * browser's default serif.
 */
export function emailTemplatePreviewDocument(template: string): string {
  const body = template
    .replace(/\{\{b\}\}/g, '<strong>')
    .replace(/\{\{\/b\}\}/g, '</strong>')
  return `<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;background:#f5f5f7}.main{max-width:650px;margin:32px auto;padding:32px;line-height:1.5;color:#616b7c;font-size:15px;font-family:Inter,sans-serif;background:#fff}.main a{color:currentColor;word-break:break-all}.main a.button{box-sizing:border-box;display:inline-block;text-align:center;text-decoration:none;padding:9px 14px;color:#fff;background:#2d2d31;border:1px solid #414146;border-radius:8px}table{width:100%;border-spacing:0}table,tr,th,td{margin:0;padding:0}td{vertical-align:top}</style></head><body><div class="main">${body}</div></body></html>`
}
