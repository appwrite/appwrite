import { Globe, KeyRound, Server, Smartphone, type LucideIcon } from 'lucide-react'
import { ANALYTICS_PRODUCT_ICON } from '@/lib/analytics/product-icon'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  ArtToken,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SOURCES: { label: string; detail: string; icon: LucideIcon; credential: string; server?: boolean }[] = [
  { label: 'Website', detail: 'Web SDK', icon: Globe, credential: 'snp_3f1f…' },
  { label: 'Mobile app', detail: 'Mobile SDKs', icon: Smartphone, credential: 'snp_3f1f…' },
  { label: 'Backend', detail: 'Server SDKs or REST', icon: Server, credential: 'API key', server: true },
]

/** Fixed row height so the spine can start and end on the first and last row centers. */
const ROW_HEIGHT_CLASS = 'h-[64px]'
const SPINE_INSET_CLASS = 'top-[32px] bottom-[32px]'

/**
 * Three event sources joining one property. Client code carries only the
 * public snippet ID; the backend adds a user ID with an API key.
 */
export function AnalyticsServerSideVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[560px] flex-col py-10 sm:flex-row sm:items-center">
      <div className="relative min-w-0 flex-1 space-y-3">
        <span
          className={cn('absolute end-0 hidden border-e border-dashed border-foreground/25 sm:block', SPINE_INSET_CLASS)}
          aria-hidden
        />
        {SOURCES.map((source, index) => (
          <div key={source.label} className="flex items-center">
            <ArtPanel
              className="min-w-0 flex-1"
              innerClassName={cn(
                'flex items-center gap-2.5 px-3',
                ROW_HEIGHT_CLASS,
                source.server &&
                  'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.05)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
              )}
              delayMs={80 + index * 140}
              float
              floatDelayMs={index * 480}
            >
              <ArtIconBadge icon={source.icon} tone={source.server ? 'primary' : 'neutral'} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[12px] font-semibold text-foreground">{t(source.label)}</span>
                <span className="block truncate text-[10.5px] text-muted-foreground">{t(source.detail)}</span>
              </span>
              <span
                className={cn(
                  'inline-flex shrink-0 items-center gap-1 rounded-full border px-1.5 py-px font-mono text-[10px]',
                  source.server
                    ? 'border-[rgb(var(--tone-rgb)/0.4)] text-[var(--tone-ink)]'
                    : 'border-border text-muted-foreground',
                )}
              >
                {source.server ? <KeyRound className="size-2.5" aria-hidden /> : null}
                <span dir="ltr">{t(source.credential)}</span>
              </span>
            </ArtPanel>
            <ArtConnector
              className="hidden w-6 shrink-0 sm:block"
              travel={source.server}
              travelDelayMs={900}
            />
          </div>
        ))}
      </div>

      <div className="mx-auto h-6 w-0 border-s border-dashed border-foreground/25 sm:hidden" aria-hidden />
      <ArtConnector className="hidden w-8 shrink-0 sm:block" travel travelDelayMs={1300} />

      <ArtPanel
        className="z-[1] w-full shrink-0 sm:w-[190px]"
        innerClassName="product-tone-shadow border-[rgb(var(--tone-rgb)/0.4)] px-3.5 py-3 dark:border-[rgb(var(--tone-rgb)/0.4)]"
        delayMs={520}
      >
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={ANALYTICS_PRODUCT_ICON} />
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{t('Property')}</p>
        </div>
        <p className="mt-2.5 text-[13px] font-semibold text-foreground">{t('Marketing site')}</p>
        <div className="mt-2 flex flex-wrap gap-1.5">
          <Badge variant="success" className="text-[10px]">
            <span dir="ltr">202</span>
          </Badge>
          <Badge variant="inactive" className="text-[10px]">
            {t('One event stream')}
          </Badge>
        </div>
        <p className="mt-2.5 border-t border-border/70 pt-2.5 text-[11px] leading-4 text-muted-foreground">
          {t('Client and server events land in the same reports.')}
        </p>
      </ArtPanel>

      <ArtChip className="bottom-0 start-[4%]" delayMs={1000} floatDelayMs={200}>
        <code dir="ltr" className="font-mono text-[10.5px]">
          <ArtToken tone="property">userId</ArtToken>
          <ArtToken tone="punctuation">: </ArtToken>
          <ArtToken tone="identifier">user</ArtToken>
          <ArtToken tone="punctuation">.</ArtToken>
          <ArtToken tone="property">$id</ArtToken>
        </code>
      </ArtChip>
      <ArtChip className="end-[2%] top-0 hidden sm:block" delayMs={1150} floatDelayMs={800}>
        <code dir="ltr" className="font-mono text-[10.5px]">
          <ArtToken tone="identifier">analytics</ArtToken>
          <ArtToken tone="punctuation">.</ArtToken>
          <ArtToken tone="function">createEvent</ArtToken>
          <ArtToken tone="punctuation">()</ArtToken>
        </code>
      </ArtChip>
    </div>
  )
}
