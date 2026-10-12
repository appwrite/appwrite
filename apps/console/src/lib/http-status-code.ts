export type HttpStatusCodeBadgeVariant =
  | 'success'
  | 'warning'
  | 'error'
  | 'inactive'

/** Badge variant for HTTP response status codes (matches docs API reference). */
export function getHttpStatusCodeBadgeVariant(
  code: string | number,
): HttpStatusCodeBadgeVariant {
  const status =
    typeof code === 'number' ? code : Number.parseInt(String(code), 10)
  if (Number.isNaN(status)) return 'inactive'
  if (status >= 200 && status < 300) return 'success'
  if (status >= 400 && status < 500) return 'warning'
  if (status >= 500) return 'error'
  return 'inactive'
}
