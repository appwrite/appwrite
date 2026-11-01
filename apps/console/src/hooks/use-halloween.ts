import { useEffect, useState } from 'react'
import { useVisitorCountryCode } from '@/hooks/use-visitor-country'
import { isHalloween } from '@/lib/halloween'

/**
 * True during Halloween week for visitors in a celebrating country.
 * Always false on SSR and the hydrating paint, since the date is the visitor's local one.
 */
export function useHalloween(): boolean {
  const countryCode = useVisitorCountryCode()
  const [now, setNow] = useState<Date | null>(null)

  useEffect(() => {
    setNow(new Date())
  }, [])

  return now !== null && isHalloween(now, countryCode)
}
