import { FileJson, RefreshCw, UserRound } from 'lucide-react'
import {
  ArtChip,
  ArtIconBadge,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TERMINAL_LINES = [
  { text: 'Building function using Docker…', tone: 'muted' },
  { text: 'Starting function using Docker…', tone: 'muted' },
  { text: 'Visit http://localhost:3000/ to execute your function.', tone: 'success' },
  { text: 'File changed: src/main.js', tone: 'muted' },
  { text: 'Function restarted in 412ms', tone: 'accent' },
] as const

export function FunctionsLocalVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] pb-16 pt-14 sm:px-8">
      <ArtChip className="start-0 top-0" delayMs={600}>
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
            <ProductFeaturePublicIcon src="/icons/docker.svg" className="size-4" />
          </span>
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Local dev')}</p>
            <p dir="ltr" className="text-start font-mono text-[10px] text-muted-foreground">
              localhost:3000
            </p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-3 hidden sm:block" delayMs={800} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={FileJson} tone="secondary" />
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Config')}
            </p>
            <p dir="ltr" className="text-start font-mono text-[11px] text-foreground">
              appwrite.config.json
            </p>
          </div>
        </div>
      </ArtChip>

      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(60)}
        title={t('Terminal')}
        trailing={
          <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
            appwrite-cli
          </span>
        }
      >
        <div dir="ltr" className="space-y-1.5 text-start font-mono text-[10.5px] leading-relaxed sm:text-[11.5px]">
          <p className="text-foreground">
            <span className="text-[var(--tone-ink)]">$</span> appwrite run functions --function-id
            stripe-webhook
          </p>
          {TERMINAL_LINES.map((line, index) => (
            <p
              key={line.text}
              className={cn(
                'product-hero-rise',
                line.tone === 'success' && 'text-emerald-600 dark:text-emerald-400',
                line.tone === 'accent' && 'text-[var(--tone-ink)]',
                line.tone === 'muted' && 'text-muted-foreground',
              )}
              style={riseStyle(400 + index * 260)}
            >
              {line.text}
            </p>
          ))}
          <p className="text-foreground">
            <span className="text-[var(--tone-ink)]">$</span>{' '}
            <span
              className="inline-block h-3.5 w-1.5 translate-y-0.5 animate-[ai-mock-cursor-blink_1s_step-end_infinite] bg-foreground/70 motion-reduce:animate-none"
              aria-hidden
            />
          </p>
        </div>
      </ArtWindow>

      <ArtChip className="bottom-1 start-[4%] hidden sm:block" delayMs={1300} floatDelayMs={1200}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={UserRound} tone="neutral" />
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Impersonate user')}</p>
            <p dir="ltr" className="text-start font-mono text-[10px] text-muted-foreground">
              --user-id &lt;id&gt;
            </p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="bottom-3 end-0" delayMs={1500} floatDelayMs={400}>
        <div className="flex items-center gap-2">
          <ArtIconBadge icon={RefreshCw} tone="success" />
          <p className="text-[12px] font-medium text-foreground">{t('Hot reload')}</p>
        </div>
      </ArtChip>
    </div>
  )
}
