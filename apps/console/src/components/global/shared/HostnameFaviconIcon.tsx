'use client'

import { useState } from 'react'
import { Globe } from 'lucide-react'
import { sdk } from '@/lib/appwrite/sdk'
import { normalizeHostnameForFavicon } from '@/lib/hostname-favicon'
import { cn } from '@/lib/utils'

const frameSizeClasses = {
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
} as const

const globeSizeClasses = {
  sm: 'h-3.5 w-3.5',
  md: 'h-3.5 w-3.5',
} as const

type HostnameFaviconIconProps = {
  hostname: string
  size?: keyof typeof frameSizeClasses
  className?: string
}

export function HostnameFaviconIcon({
  hostname,
  size = 'sm',
  className,
}: HostnameFaviconIconProps) {
  const [failed, setFailed] = useState(false)
  const normalizedHostname = normalizeHostnameForFavicon(hostname)
  const frameClass = cn(
    'flex shrink-0 items-center justify-center overflow-hidden rounded border border-border/50 bg-background',
    frameSizeClasses[size],
    className,
  )

  if (!normalizedHostname || failed) {
    return (
      <div
        className={cn(frameClass, 'border-transparent bg-transparent')}
        aria-hidden
      >
        <Globe
          className={cn(
            globeSizeClasses[size],
            'shrink-0 text-muted-foreground',
          )}
          aria-hidden
        />
      </div>
    )
  }

  return (
    <div className={frameClass} aria-hidden>
      <img
        src={sdk.forConsole.avatars.getFavicon({
          url: `https://${normalizedHostname}`,
        })}
        alt=""
        className="h-full w-full object-contain p-0.5"
        onError={() => setFailed(true)}
      />
    </div>
  )
}
