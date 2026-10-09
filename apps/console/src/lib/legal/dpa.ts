export const DPA_PDF_PATH = '/legal/dpa.pdf'

/**
 * Console and CDN cache `/legal/dpa.pdf` for up to an hour, so the download
 * button appends a unique `v` query so each click fetches the current file.
 */
export function getDpaDownloadUrl(now: number = Date.now()): string {
  return `${DPA_PDF_PATH}?v=${now}`
}
