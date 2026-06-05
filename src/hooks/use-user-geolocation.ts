import { useMemo } from 'react'
import { getCountryCoordinates } from '@/lib/country-coordinates'
import { useLocale } from '@/lib/react-query/hooks/locale'

export type UserGeolocation = {
  lat: number
  lng: number
}

/** Approximate map position from the console locale API country code. */
export function useUserGeolocation(): UserGeolocation | null {
  const { data: locale } = useLocale()

  return useMemo(() => {
    const coords = getCountryCoordinates(locale?.countryCode)
    if (!coords) return null

    const [lng, lat] = coords
    return { lat, lng }
  }, [locale?.countryCode])
}
