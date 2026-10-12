'use client'

import { useEffect, useMemo, useState } from 'react'
import { Package } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  MARKETPLACE_APP_LOGO_SIZE_CLASSNAMES,
  MarketplaceAppLogo,
  type MarketplaceAppLogoSize,
} from '@/components/pages/organizations/$orgId/marketplace/_components/MarketplaceAppLogo'
import { resolveAppLogoDisplayUrl } from '@/lib/appwrite/apps-logo'
import { sdk } from '@/lib/appwrite/sdk'
import { normalizeHostnameForFavicon } from '@/lib/hostname-favicon'
import { isOfficialMarketplaceApp } from '@/lib/marketplace/map-app'
import { matchKnownOAuthClient } from '@/lib/oauth-known-clients'
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

type LogoSource = { src: string; alt: string; monochrome: boolean }

function buildLogoSources(
  app: Models.App | null | undefined,
  cimdUrl?: string | null,
): LogoSource[] {
  if (!app) return []

  const catalogApp = isOfficialMarketplaceApp(app)
  const sources: LogoSource[] = []
  const uploadedLogo = resolveAppLogoDisplayUrl(app.logoUri, {
    width: 128,
    height: 128,
  })
  if (uploadedLogo) {
    sources.push({
      src: uploadedLogo,
      alt: app.name,
      monochrome: catalogApp,
    })
  }

  const knownClient = matchKnownOAuthClient(app, cimdUrl)
  if (knownClient) {
    sources.push({
      src: knownClient.iconPath,
      alt: knownClient.name,
      monochrome: true,
    })
  }

  const favicon = appFaviconUrl(app)
  if (favicon) {
    sources.push({
      src: favicon,
      alt: app.name,
      monochrome: catalogApp,
    })
  }

  return sources
}

const FALLBACK_ICON_CLASS: Record<MarketplaceAppLogoSize, string> = {
  sm: 'size-4',
  md: 'size-5',
  lg: 'size-6',
  consent: 'size-7',
  xl: 'size-8',
}

type OAuth2AppAvatarProps = {
  app?: Models.App | null
  className?: string
  size?: MarketplaceAppLogoSize
  /** CIMD document URL when the client is URL-form; improves known-client matching. */
  cimdUrl?: string | null
}

/**
 * App mark for OAuth2 consent / outcome screens.
 * Uses the same muted catalog tile as marketplace listings (uploaded logo,
 * bundled known-client icon, then domain favicon).
 */
export function OAuth2AppAvatar({
  app,
  className,
  size = 'consent',
  cimdUrl,
}: OAuth2AppAvatarProps) {
  const sources = useMemo(
    () => buildLogoSources(app, cimdUrl),
    [app, cimdUrl],
  )
  const [sourceIndex, setSourceIndex] = useState(0)

  useEffect(() => {
    setSourceIndex(0)
  }, [app?.$id, app?.logoUri, cimdUrl])

  const active = sources[sourceIndex]
  if (active) {
    return (
      <MarketplaceAppLogo
        src={active.src}
        alt={active.alt}
        size={size}
        className={className}
        monochrome={active.monochrome}
        onImageError={() => setSourceIndex((current) => current + 1)}
      />
    )
  }

  return (
    <div
      className={cn(
        'bg-muted text-muted-foreground flex shrink-0 items-center justify-center overflow-hidden border border-border/60',
        MARKETPLACE_APP_LOGO_SIZE_CLASSNAMES[size],
        className,
      )}
      aria-hidden
    >
      <Package className={FALLBACK_ICON_CLASS[size]} />
    </div>
  )
}
