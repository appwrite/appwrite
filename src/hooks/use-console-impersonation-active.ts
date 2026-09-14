import { useConsoleImpersonationRevision } from '@/hooks/use-console-impersonation-revision'
import { hasConsoleImpersonationSessionTarget } from '@/lib/console-impersonation'

/**
 * True while impersonating a console user. The server rejects account writes
 * during impersonation, so account UIs use this to render read-only.
 */
export function useConsoleImpersonationActive() {
  useConsoleImpersonationRevision()
  return hasConsoleImpersonationSessionTarget()
}
