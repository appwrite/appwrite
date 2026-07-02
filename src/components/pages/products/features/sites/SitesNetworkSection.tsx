'use client'

import { ArrowUpRight } from 'lucide-react'
import { NetworkGlobeMount } from '@/components/pages/home/NetworkGlobeMount'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { SitesNetworkProtections } from '@/components/pages/products/features/sites/SitesNetworkProtections'
import { useT } from '@/lib/i18n/translate'

export function SitesNetworkSection() {
  const t = useT()
  return (
    <div className="flex w-full flex-col items-center">
      <SitesNetworkProtections />
      <DocsRouteLink
        href="/docs/products/network"
        className="relative z-30 mt-10 inline-flex items-center gap-1.5 text-[13px] font-medium text-muted-foreground transition-colors hover:text-foreground sm:mt-12"
      >
        {t('Appwrite Network docs')} {/* pragma: allowlist secret */}
        <ArrowUpRight className="size-3.5" aria-hidden />
      </DocsRouteLink>
      <NetworkGlobeMount className="relative z-20 -mt-8 w-full sm:-mt-12 lg:-mt-16" />
    </div>
  )
}
