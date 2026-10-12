import { Globe, Lock, ShoppingCart } from 'lucide-react'
import { Fragment, type ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { ArtConnector, ArtIconBadge, ArtPanel } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const DNS_RECORDS = [
  { type: 'A', name: '@', value: '76.76.21.21' },
  { type: 'CNAME', name: 'www', value: 'acme.dev' },
] as const

type SitesDomainManagementProps = {
  className?: string
}

function CapabilityTile({
  icon,
  tone,
  title,
  description,
  delayMs,
  floatDelayMs,
  children,
}: {
  icon: typeof Globe
  tone: 'primary' | 'secondary' | 'success'
  title: string
  description: string
  delayMs: number
  floatDelayMs: number
  children: ReactNode
}) {
  const t = useT()
  return (
    <ArtPanel
      className="h-full min-w-0"
      innerClassName="flex h-full flex-col p-4 sm:p-5"
      delayMs={delayMs}
      float
      floatDelayMs={floatDelayMs}
    >
      <ArtIconBadge icon={icon} tone={tone} />
      <h3 className="mt-3 text-[14px] font-semibold text-foreground">{t(title)}</h3>
      <p className="mt-1.5 flex-1 text-[13px] leading-5 text-muted-foreground">{t(description)}</p>
      <div className="mt-4">{children}</div>
    </ArtPanel>
  )
}

export function SitesDomainManagement({ className }: SitesDomainManagementProps) {
  const t = useT()

  const tiles = [
    <CapabilityTile
      key="buy"
      icon={ShoppingCart}
      tone="primary"
      title="Buy domain"
      description="Register a domain in Appwrite without leaving the Console."
      delayMs={100}
      floatDelayMs={0}
    >
      <div className="flex items-center justify-between gap-2 rounded-lg border border-border bg-muted/30 px-2.5 py-1.5">
        <span dir="ltr" className="truncate font-mono text-[11px] text-foreground">acme.dev</span>
        <Badge variant="success" className="shrink-0 text-[10px]">{t('Available')}</Badge>
      </div>
    </CapabilityTile>,
    <CapabilityTile
      key="dns"
      icon={Globe}
      tone="secondary"
      title="Appwrite DNS"
      description="Manage A, CNAME, TXT, and other records in one place."
      delayMs={260}
      floatDelayMs={600}
    >
      <div dir="ltr" className="space-y-1 rounded-lg border border-border bg-muted/30 px-2.5 py-1.5">
        {DNS_RECORDS.map((record) => (
          <p key={record.type} className="flex gap-2 font-mono text-[11px]">
            <span className="w-11 shrink-0 text-[var(--tone-ink)]">{record.type}</span>
            <span className="w-8 shrink-0 text-muted-foreground">{record.name}</span>
            <span className="truncate text-foreground">{record.value}</span>
          </p>
        ))}
      </div>
    </CapabilityTile>,
    <CapabilityTile
      key="tls"
      icon={Lock}
      tone="success"
      title="Automatic TLS"
      description="Certificates are issued when a hostname is verified."
      delayMs={420}
      floatDelayMs={1200}
    >
      <div className="flex items-center justify-between gap-2 rounded-lg border border-border bg-muted/30 px-2.5 py-1.5">
        <span className="flex min-w-0 items-center gap-1.5">
          <Lock className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
          <span dir="ltr" className="truncate font-mono text-[11px] text-foreground">acme.dev</span>
        </span>
        <Badge variant="verified" className="shrink-0 text-[10px]">{t('Verified')}</Badge>
      </div>
    </CapabilityTile>,
  ]

  return (
    <div
      className={cn(
        'grid gap-4 sm:grid-cols-[minmax(0,1fr)_1.5rem_minmax(0,1fr)_1.5rem_minmax(0,1fr)] sm:gap-0',
        className,
      )}
    >
      {tiles.map((tile, index) => (
        <Fragment key={index}>
          {tile}
          {index < tiles.length - 1 ? (
            <ArtConnector travel travelDelayMs={800 + index * 900} className="hidden self-center sm:block" />
          ) : null}
        </Fragment>
      ))}
    </div>
  )
}
