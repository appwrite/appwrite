import { Copy, FileText, Globe, Link2 } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtChip, ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ACCESS_MODES = ['Preview', 'View', 'Download'] as const

const CHART_BARS = [42, 64, 50, 78, 88] as const

function ExpiryRing() {
  const radius = 9
  const circumference = 2 * Math.PI * radius
  return (
    <svg viewBox="0 0 24 24" className="size-7 shrink-0 -rotate-90" aria-hidden>
      <circle cx="12" cy="12" r={radius} fill="none" strokeWidth="2.5" className="stroke-muted" />
      <circle
        cx="12"
        cy="12"
        r={radius}
        fill="none"
        strokeWidth="2.5"
        strokeLinecap="round"
        className="product-art-countdown stroke-[var(--tone-ink)]"
        strokeDasharray={circumference}
        style={{ strokeDashoffset: circumference }}
      />
    </svg>
  )
}

function ReportSheet() {
  return (
    <div className="product-hero-rise absolute start-[2%] top-[4%] w-[min(220px,54%)]" style={riseStyle(60)}>
      <div className="-rotate-3 rounded-lg border border-border bg-background p-3.5 shadow-[0_16px_40px_-20px_rgb(0_0_0/0.35)] dark:bg-card">
        <div className="flex items-center gap-1.5">
          <FileText className="size-3.5 text-muted-foreground" aria-hidden />
          <span dir="ltr" className="truncate text-[11px] font-medium text-foreground">
            report-q4.pdf
          </span>
        </div>
        <div className="mt-3 space-y-1.5" aria-hidden>
          <span className="block h-1.5 w-4/5 rounded-full bg-foreground/15" />
          <span className="block h-1.5 w-full rounded-full bg-foreground/10" />
          <span className="block h-1.5 w-3/5 rounded-full bg-foreground/10" />
        </div>
        <div className="mt-3 flex h-16 items-end gap-1.5 rounded-md bg-muted/40 px-2 pb-2" aria-hidden>
          {CHART_BARS.map((height, index) => (
            <span
              key={index}
              className={cn(
                'flex-1 rounded-sm',
                index === CHART_BARS.length - 1 ? 'bg-[rgb(var(--tone-rgb)/0.6)]' : 'bg-foreground/15',
              )}
              style={{ height: `${height}%` }}
            />
          ))}
        </div>
        <div className="mt-3 space-y-1.5" aria-hidden>
          <span className="block h-1.5 w-full rounded-full bg-foreground/10" />
          <span className="block h-1.5 w-2/3 rounded-full bg-foreground/10" />
        </div>
      </div>
    </div>
  )
}

export function StorageFileTokensVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[420px] w-full max-w-[540px]">
      <ReportSheet />

      <ArtChip className="end-0 top-[3%]" delayMs={700}>
        <div className="flex items-center gap-2">
          <ExpiryRing />
          <div>
            <p className="text-[10px] text-muted-foreground">{t('Expires')}</p>
            <p className="text-[12px] font-semibold text-foreground">{t('Jan 31, 2027')}</p>
          </div>
        </div>
      </ArtChip>

      <ArtPanel
        className="absolute end-0 top-[36%] z-[1] w-[min(330px,92%)] sm:end-[3%]"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={300}
      >
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="text-[12px] font-semibold text-foreground">{t('File tokens')}</p>
            <p className="mt-0.5 text-[11px] leading-4 text-muted-foreground">
              {t('Share without session cookies or bucket permissions.')}
            </p>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('Public link')}
          </Badge>
        </div>
        <div className="mt-3 flex items-center gap-1.5">
          <Link2 className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          <p className="text-[11px] font-medium text-foreground">{t('Preview URL')}</p>
        </div>
        <div className="mt-1.5 flex items-center gap-2 rounded-md border border-border bg-muted/30 px-2.5 py-2">
          <p dir="ltr" className="min-w-0 flex-1 truncate text-start font-mono text-[10px] text-muted-foreground">
            …/files/report-q4/preview?<span className="text-[var(--tone-ink)]">token=8f3a…c21d</span>
          </p>
          <Copy className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
        </div>
        <div className="mt-3 flex items-center gap-1.5">
          <span className="text-[10px] text-muted-foreground">{t('Access')}</span>
          {ACCESS_MODES.map((mode, index) => (
            <span
              key={mode}
              className={cn(
                'product-hero-rise rounded-full border px-2 py-0.5 text-[10px] font-medium',
                index === 0
                  ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.1)] text-[var(--tone-ink)]'
                  : 'border-border bg-background text-foreground dark:bg-card',
              )}
              style={riseStyle(700 + index * 110)}
            >
              {t(mode)}
            </span>
          ))}
        </div>
      </ArtPanel>

      <ArtChip className="bottom-0 start-0 w-[min(230px,80%)]" delayMs={1100} floatDelayMs={900}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={Globe} tone="secondary" />
          <p className="text-[10px] leading-4 text-muted-foreground">
            {t('Works for external viewers without third-party cookie issues.')}
          </p>
        </div>
      </ArtChip>
    </div>
  )
}
