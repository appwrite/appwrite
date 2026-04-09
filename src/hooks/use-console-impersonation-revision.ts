import { useEffect, useState } from 'react'
import { CONSOLE_IMPERSONATION_CHANGED_EVENT } from '@/lib/console-impersonation'

/** Bumps when impersonation starts/stops so account queries refetch with new headers. */
export function useConsoleImpersonationRevision() {
  const [rev, setRev] = useState(0)
  useEffect(() => {
    const onChange = () => setRev((r) => r + 1)
    window.addEventListener(CONSOLE_IMPERSONATION_CHANGED_EVENT, onChange)
    return () =>
      window.removeEventListener(CONSOLE_IMPERSONATION_CHANGED_EVENT, onChange)
  }, [])
  return rev
}
