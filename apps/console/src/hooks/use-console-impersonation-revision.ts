import { useEffect, useState } from 'react'
import {
  CONSOLE_IMPERSONATION_CHANGED_EVENT,
  getConsoleAccountQueryRevision,
} from '@/lib/console-impersonation'

/** Bumps when impersonation starts/stops so account queries refetch with new headers. */
export function useConsoleImpersonationRevision() {
  const [rev, setRev] = useState(() => getConsoleAccountQueryRevision())
  useEffect(() => {
    const onChange = () => setRev(getConsoleAccountQueryRevision())
    window.addEventListener(CONSOLE_IMPERSONATION_CHANGED_EVENT, onChange)
    return () =>
      window.removeEventListener(CONSOLE_IMPERSONATION_CHANGED_EVENT, onChange)
  }, [])
  return rev
}
