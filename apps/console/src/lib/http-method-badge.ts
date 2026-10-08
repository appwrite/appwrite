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

/** Subtle method-colored accents for endpoint boxes and nav selection. */
export function getHttpMethodAccentClasses(method: string): {
  endpointBox: string
  methodText: string
} {
  switch (method.toLowerCase()) {
    case 'get':
      return {
        endpointBox: 'bg-blue-500/[0.06]',
        methodText: 'text-blue-600 dark:text-blue-400',
      }
    case 'post':
      return {
        endpointBox: 'bg-emerald-500/[0.06]',
        methodText: 'text-emerald-600 dark:text-emerald-400',
      }
    case 'put':
    case 'patch':
      return {
        endpointBox: 'bg-amber-500/[0.06]',
        methodText: 'text-amber-600 dark:text-amber-400',
      }
    case 'delete':
      return {
        endpointBox: 'bg-red-500/[0.06]',
        methodText: 'text-red-600 dark:text-red-400',
      }
    default:
      return {
        endpointBox: 'bg-muted/40',
        methodText: 'text-foreground',
      }
  }
}
