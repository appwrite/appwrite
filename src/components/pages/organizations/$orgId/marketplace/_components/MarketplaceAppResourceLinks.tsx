import { assetUrl } from '@/lib/asset-url'
import type { LucideIcon } from 'lucide-react'
import {
  ExternalLink,
  Globe,
  LifeBuoy,
  ScrollText,
  ShieldCheck,
  Trash2,
} from 'lucide-react'
import type { MarketplaceApp } from '@/lib/marketplace/types'
import { useT } from '@/lib/i18n/translate'

type MarketplaceAppResourceLinksProps = {
  app: Pick<
    MarketplaceApp,
    | 'clientUri'
    | 'privacyPolicyUrl'
    | 'termsUrl'
    | 'supportUrl'
    | 'dataDeletionUrl'
  >
}

function hostnameOf(url: string): string {
  try {
    return new URL(url).hostname
  } catch {
    return url
  }
}

export function MarketplaceAppResourceLinks({
  app,
}: MarketplaceAppResourceLinksProps) {
  const t = useT()
  const links = [
    { label: t('Homepage'), url: app.clientUri, icon: Globe },
    {
      label: t('Privacy policy'),
      url: app.privacyPolicyUrl,
      icon: ShieldCheck,
    },
    { label: t('Terms of service'), url: app.termsUrl, icon: ScrollText },
    { label: t('Support'), url: app.supportUrl, icon: LifeBuoy },
    { label: t('Data deletion'), url: app.dataDeletionUrl, icon: Trash2 },
  ].filter((link): link is { label: string; url: string; icon: LucideIcon } =>
    Boolean(link.url),
  )

  if (links.length === 0) return null

  return (
    <section className="space-y-2.5">
      <h4 className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
        {t('Resources')}
      </h4>
      <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
        {links.map((link) => (
          <a
            key={link.label}
            href={assetUrl(link.url)}
            target="_blank"
            rel="noopener noreferrer"
            className="group flex items-center gap-3 rounded-lg border border-border bg-card px-4 py-3 transition-colors hover:border-foreground/20 hover:bg-accent/40"
          >
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors group-hover:text-foreground">
              <link.icon className="h-4 w-4" aria-hidden />
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-[13px] font-medium text-foreground truncate">
                {link.label}
              </p>
              <p className="text-[12px] text-muted-foreground truncate">
                {hostnameOf(link.url)}
              </p>
            </div>
            <ExternalLink
              className="h-3.5 w-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
              aria-hidden
            />
          </a>
        ))}
      </div>
    </section>
  )
}
