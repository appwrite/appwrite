import { FileArchive, FileImage } from 'lucide-react'
import type { CSSProperties } from 'react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtConnector,
  ArtIconBadge,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const OUTPUTS = [
  { format: 'webp', file: 'hero.webp', size: '278 KB', ratio: 33, highlight: true },
  { format: 'avif', file: 'hero.avif', size: '214 KB', ratio: 25, highlight: false },
  { format: 'jpg', file: 'hero.jpg', size: '391 KB', ratio: 46, highlight: false },
] as const

const ALGORITHMS = ['gzip', 'zstd'] as const

function SizeBar({ ratio, delayMs, accent }: { ratio: number; delayMs: number; accent: boolean }) {
  return (
    <div className="mt-2 h-1 overflow-hidden rounded-full bg-muted">
      <div className="h-full" style={{ width: `${ratio}%` }}>
        <div
          className={cn(
            'product-hero-fill h-full rounded-full',
            accent ? 'bg-[var(--tone-ink)]' : 'bg-foreground/30',
          )}
          style={{ '--fill-delay': `${delayMs}ms`, '--fill-duration': '1.4s' } as CSSProperties}
        />
      </div>
    </div>
  )
}

export function StorageCompressionVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] pb-16 pt-14">
      <ArtChip className="start-0 top-0" delayMs={200}>
        <p className="text-[10px] font-medium text-muted-foreground">{t('Bucket compression')}</p>
        <div dir="ltr" className="mt-1.5 flex gap-1">
          {ALGORITHMS.map((algorithm) => (
            <span
              key={algorithm}
              className={cn(
                'rounded-md border px-2 py-0.5 font-mono text-[10px]',
                algorithm === 'zstd'
                  ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.1)] text-[var(--tone-ink)]'
                  : 'border-border bg-muted/30 text-muted-foreground',
              )}
            >
              {algorithm}
            </span>
          ))}
        </div>
      </ArtChip>

      <div className="flex flex-col items-stretch gap-0 sm:flex-row sm:items-center">
        <ArtPanel className="sm:w-[170px] sm:shrink-0" innerClassName="px-3 py-3" delayMs={60} float floatDelayMs={200}>
          <div className="flex items-center gap-2">
            <ArtIconBadge icon={FileImage} tone="neutral" />
            <div className="min-w-0">
              <p className="text-[10px] uppercase tracking-wider text-muted-foreground">{t('Original')}</p>
              <p dir="ltr" className="truncate text-[12px] font-medium text-foreground">
                hero.png
              </p>
            </div>
          </div>
          <p dir="ltr" className="mt-2 text-end font-mono text-[11px] text-muted-foreground">
            842 KB
          </p>
          <SizeBar ratio={100} delayMs={300} accent={false} />
        </ArtPanel>

        <div className="mx-auto h-6 sm:mx-0 sm:h-auto sm:w-8 sm:shrink-0" aria-hidden>
          <ArtConnector className="hidden sm:block" travel travelDelayMs={400} />
          <ArtConnector orientation="vertical" className="sm:hidden" />
        </div>

        <div className="product-hero-rise mx-auto shrink-0" style={riseStyle(250)}>
          <div className="product-tone-shadow flex size-[72px] flex-col items-center justify-center gap-1 rounded-2xl border border-[rgb(var(--tone-rgb)/0.45)] bg-background dark:bg-card">
            <FileArchive className="size-5 text-[var(--tone-ink)]" strokeWidth={1.75} aria-hidden />
            <span dir="ltr" className="font-mono text-[10px] font-medium text-foreground">
              zstd
            </span>
          </div>
        </div>

        <div className="mx-auto h-6 sm:mx-0 sm:h-auto sm:w-8 sm:shrink-0" aria-hidden>
          <ArtConnector className="hidden sm:block" travel travelDelayMs={1400} />
          <ArtConnector orientation="vertical" className="sm:hidden" />
        </div>

        <div className="relative min-w-0 flex-1 space-y-2 sm:ps-4">
          <span
            className="absolute bottom-[16%] start-0 top-[16%] hidden border-s border-dashed border-foreground/20 sm:block"
            aria-hidden
          />
          {OUTPUTS.map((output, index) => (
            <div key={output.format} className="relative">
              <span
                className="absolute -start-4 top-1/2 hidden w-4 border-t border-dashed border-foreground/20 sm:block"
                aria-hidden
              />
              <ArtPanel
                delayMs={500 + index * 140}
                innerClassName={cn(
                  'px-3 py-2',
                  output.highlight && 'border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
                )}
              >
                <div className="flex items-center gap-2">
                  <span
                    dir="ltr"
                    className={cn(
                      'rounded px-1.5 py-0.5 font-mono text-[10px] uppercase',
                      output.highlight
                        ? 'bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]'
                        : 'bg-muted text-muted-foreground',
                    )}
                  >
                    {output.format}
                  </span>
                  <span dir="ltr" className="min-w-0 truncate text-[11px] text-foreground">
                    {output.file}
                  </span>
                  <span dir="ltr" className="ms-auto shrink-0 font-mono text-[11px] text-muted-foreground">
                    {output.size}
                  </span>
                </div>
                <SizeBar ratio={output.ratio} delayMs={800 + index * 140} accent={output.highlight} />
              </ArtPanel>
            </div>
          ))}
        </div>
      </div>

      <ArtChip className="bottom-0 end-0 max-w-[min(360px,100%)]" delayMs={1300} floatDelayMs={700}>
        <div className="flex items-center gap-2">
          <Badge variant="success" className="shrink-0 text-[10px]">
            {t('67% smaller')}
          </Badge>
          <span className="text-[11px] text-muted-foreground">
            {t('Serve WebP or AVIF without storing duplicate files.')}
          </span>
        </div>
      </ArtChip>
    </div>
  )
}
