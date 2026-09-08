'use client'

import { useMemo, useState } from 'react'
import { Package } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { normalizeHostnameForFavicon } from '@/lib/hostname-favicon'
import { cn } from '@/lib/utils'

function appFaviconUrl(app: Models.App): string | null {
  const candidates = [app.clientUri, ...(app.redirectUris ?? [])]
  for (const candidate of candidates) {
    if (!candidate?.trim()) continue
    const host = normalizeHostnameForFavicon(candidate)
    if (!host) continue
    return sdk.forConsole.avatars.getFavicon({
      url: `https://${host}`,
    })
  }
  return null
}

type OAuth2AppAvatarProps = {
  app?: Models.App | null
  className?: string
}

/**
 * App mark for OAuth2 consent / outcome screens.
 * Prefers `logoUri`, then the Avatars favicon for the app domain, then a default icon.
 */
export function OAuth2AppAvatar({ app, className }: OAuth2AppAvatarProps) {
  const [logoFailed, setLogoFailed] = useState(false)
  const [faviconFailed, setFaviconFailed] = useState(false)

  const faviconSrc = useMemo(
    () => (app ? appFaviconUrl(app) : null),
    [app],
  )

  const frameClassName = cn(
    'bg-muted text-muted-foreground flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl ring-1 ring-border/50',
    className,
  )

  if (app?.logoUri && !logoFailed) {
    return (
      <img
        src={app.logoUri}
        alt={app.name}
        className={cn(
          'size-14 shrink-0 rounded-xl object-cover ring-1 ring-border/50',
          className,
        )}
        height={56}
        width={56}
        onError={() => setLogoFailed(true)}
      />
    )
  }

  if (faviconSrc && !faviconFailed) {
    return (
      <div className={frameClassName}>
        <img
          src={faviconSrc}
          alt={app?.name ?? ''}
          className="size-full object-contain p-2"
          height={56}
          width={56}
          onError={() => setFaviconFailed(true)}
        />
      </div>
    )
  }

  return (
    <div className={frameClassName} aria-hidden>
      <Package className="size-6" />
    </div>
  )
}
