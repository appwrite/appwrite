const INIT_TICKET_CAPTURE_FONT_SMOOTHING_CSS = `
* {
  -webkit-font-smoothing: antialiased !important;
  -moz-osx-font-smoothing: grayscale !important;
  text-rendering: geometricPrecision !important;
}
`

/** Rendering hints for html-to-image canvas snapshots. */
export function buildInitTicketCaptureFontEmbedCss(): string {
  return INIT_TICKET_CAPTURE_FONT_SMOOTHING_CSS
}

export async function preloadInitTicketCaptureFonts() {
  if (typeof document === 'undefined') return
  await document.fonts.ready
  await document.fonts.load('400 16px system-ui').catch(() => undefined)
  await document.fonts.load('600 12px ui-monospace').catch(() => undefined)
  await document.fonts.ready
}
