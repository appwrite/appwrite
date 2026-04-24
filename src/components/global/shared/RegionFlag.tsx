import { Globe } from 'lucide-react'
import { cn } from '@/lib/utils'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'

interface RegionFlagProps {
  region: string
  className?: string
  width?: number
  height?: number
}

// Map region names to ISO country codes for Appwrite flags API
const regionToCountryCode: Record<string, string> = {
  Frankfurt: 'de',
  'New York': 'us',
  Singapore: 'sg',
  London: 'gb',
  Tokyo: 'jp',
  Sydney: 'au',
  Toronto: 'ca',
  Amsterdam: 'nl',
  Mumbai: 'in',
  'São Paulo': 'br',
  Seoul: 'kr',
}

export function RegionFlag({
  region,
  className,
  width = 20,
  height = 20,
}: RegionFlagProps) {
  const countryCode = regionToCountryCode[region]?.toLowerCase()

  if (!countryCode) {
    // Fallback to Globe icon if region not found
    return (
      <div
        className={cn(
          'flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/50 bg-muted/30',
          className || 'h-4 w-4',
        )}
      >
        <Globe className="h-2.5 w-2.5 text-muted-foreground" />
      </div>
    )
  }

  const flagUrl = `${getBaseEndpoint()}/avatars/flags/${countryCode}?width=${width}&height=${height}&quality=100&project=console`

  return (
    <div
      className={cn(
        'flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/50 bg-background',
        className || 'h-4 w-4',
      )}
    >
      <img
        src={flagUrl}
        alt={`${region} flag`}
        className="h-full w-full object-cover"
        role="img"
        aria-label={`${region} flag`}
      />
    </div>
  )
}
