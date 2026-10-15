import { FileVideo, Globe, Lock } from 'lucide-react'
import type { CSSProperties } from 'react'
import {
  ArtChip,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

function ArtworkPreview() {
  return (
    <div className="relative aspect-[16/10] overflow-hidden rounded-lg border border-border bg-[linear-gradient(175deg,rgb(var(--tone2-rgb)/0.9)_0%,rgb(var(--tone-rgb)/0.75)_60%,rgb(var(--tone-rgb)/0.45)_100%)]">
      <div className="absolute end-[24%] top-[16%] aspect-square w-[13%] rounded-full bg-white/95 shadow-[0_0_40px_12px_rgb(255_255_255/0.35)]" />
      <div
        className="absolute inset-x-0 bottom-0 h-[62%] bg-[rgb(var(--tone2-rgb))] opacity-80"
        style={{ clipPath: 'polygon(0 55%, 18% 30%, 34% 52%, 52% 18%, 70% 46%, 84% 30%, 100% 50%, 100% 100%, 0 100%)' }}
      />
      <div
        className="absolute inset-x-0 bottom-0 h-[44%] bg-[#19191d]/80"
        style={{ clipPath: 'polygon(0 45%, 22% 20%, 42% 50%, 62% 24%, 80% 52%, 100% 30%, 100% 100%, 0 100%)' }}
      />
      <div
        className="product-hero-crop absolute rounded-sm border border-white"
        style={{ boxShadow: '0 0 0 9999px rgb(0 0 0 / 0.38)' }}
      >
        <span className="absolute -start-1 -top-1 size-2 rounded-[2px] bg-white" />
        <span className="absolute -end-1 -top-1 size-2 rounded-[2px] bg-white" />
        <span className="absolute -bottom-1 -start-1 size-2 rounded-[2px] bg-white" />
        <span className="absolute -bottom-1 -end-1 size-2 rounded-[2px] bg-white" />
        <span className="absolute inset-x-0 top-1/3 h-px bg-white/40" />
        <span className="absolute inset-x-0 top-2/3 h-px bg-white/40" />
        <span className="absolute inset-y-0 start-1/3 w-px bg-white/40" />
        <span className="absolute inset-y-0 start-2/3 w-px bg-white/40" />
      </div>
    </div>
  )
}

export function StorageHeroArt() {
  const t = useT()

  return (
    <div className="relative px-3 py-10 sm:px-8">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(80)}
        title={<span dir="ltr">media / hero.png</span>}
        trailing={
          <span className="rounded bg-[rgb(var(--tone-rgb)/0.16)] px-1.5 py-0.5 text-[10px] font-medium text-[var(--tone-ink)]">
            {t('Preview')}
          </span>
        }
      >
        <ArtworkPreview />
        <div
          dir="ltr"
          className="mt-3 overflow-hidden whitespace-nowrap rounded-md border border-border bg-muted/30 px-2.5 py-2 font-mono text-[11px] text-muted-foreground"
        >
          /files/hero.png/preview?<span className="text-[var(--tone-ink)]">width</span>=800&amp;
          <span className="text-[var(--tone-ink)]">output</span>=webp&amp;
          <span className="text-[var(--tone-ink)]">quality</span>=80
        </div>
        <div className="mt-3 grid grid-cols-3 gap-2 text-start">
          {[
            { label: 'Original', value: '4.2 MB' },
            { label: 'Output', value: '186 KB' },
            { label: 'Format', value: 'WEBP' },
          ].map((item) => (
            <div key={item.label} className="rounded-md border border-border px-2.5 py-1.5">
              <p className="text-[10px] text-muted-foreground">{t(item.label)}</p>
              <p dir="ltr" className="font-mono text-[12px] font-medium text-foreground">{item.value}</p>
            </div>
          ))}
        </div>
      </ArtWindow>

      <ArtChip className="start-0 top-0 w-[200px]" delayMs={600}>
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md bg-[rgb(var(--tone2-rgb)/0.16)] text-foreground">
            <FileVideo className="size-3.5" aria-hidden />
          </span>
          <div className="min-w-0 flex-1">
            <div className="flex items-center justify-between gap-2">
              <p dir="ltr" className="truncate text-[11px] font-medium text-foreground">promo.mp4</p>
              <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">24 MB</span>
            </div>
            <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
              <div
                className="product-hero-fill h-full rounded-full bg-[var(--tone-ink)]"
                style={{ '--fill-delay': '900ms', '--fill-duration': '2.4s' } as CSSProperties}
              />
            </div>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="end-0 top-[38%] sm:-end-2" delayMs={900} floatDelayMs={800}>
        <div className="flex items-center gap-2">
          <Lock className="size-3.5 text-[var(--tone-ink)]" aria-hidden />
          <p className="text-[11px] font-medium text-foreground">{t('Encrypted')}</p>
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 end-[10%]" delayMs={1200} floatDelayMs={1500}>
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-md bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
            <Globe className="size-3.5" aria-hidden />
          </span>
          <div>
            <p className="text-[11px] font-medium text-foreground">{t('Cache hit')}</p>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">fra · 18 ms</p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
