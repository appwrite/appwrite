export type HttpMethodBadgeVariant =
  | 'info'
  | 'success'
  | 'warning'
  | 'error'
  | 'secondary'
  | 'processing'

/** Badge variant for HTTP methods (matches docs API reference and explorer). */
export function getHttpMethodBadgeVariant(
  method: string,
): HttpMethodBadgeVariant {
  switch (method.toLowerCase()) {
    case 'get':
      return 'processing'
    case 'post':
      return 'success'
    case 'put':
    case 'patch':
      return 'warning'
    case 'delete':
      return 'error'
    default:
      return 'secondary'
  }
}

export function formatHttpMethodBadgeLabel(method: string): string {
  return method.trim().toUpperCase() || 'UNKNOWN'
}
