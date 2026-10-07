import type { CSSProperties } from 'react'
import { Globe, Lock, ShieldCheck } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtChip,
  ArtPanel,
  ArtToken as T,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const REQUEST_HEADERS = [
  { name: 'Content-Type', value: 'application/json', accent: false },
  { name: 'x-appwrite-user-jwt', value: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…', accent: true },
] as const

const ORBIT_DOTS = [0, 120, 240] as const

function DomainEmblem() {
  return (
    <div className="relative aspect-square w-[190px]" aria-hidden>
      <div className="absolute inset-0 rounded-full border border-dashed border-foreground/15" />
      <div className="product-hero-orbit absolute inset-[14%] rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.4)]">
        {ORBIT_DOTS.map((angle) => (
          <span
            key={angle}
            className="absolute size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[var(--tone-ink)] shadow-[0_0_10px_rgb(var(--tone-rgb))]"
            style={
              {
                left: `${50 + 50 * Math.cos((angle * Math.PI) / 180)}%`,
                top: `${50 + 50 * Math.sin((angle * Math.PI) / 180)}%`,
              } as CSSProperties
            }
          />
        ))}
      </div>
      <div className="absolute inset-[28%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.25),transparent_70%)]" />
      <div className="product-tone-shadow absolute inset-[34%] flex items-center justify-center rounded-[28%] border border-[rgb(var(--tone-rgb)/0.45)] bg-background dark:bg-card">
        <Globe className="size-8 text-[var(--tone-ink)]" strokeWidth={1.5} />
      </div>
    </div>
  )
}

export function FunctionsHttpVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] flex-col gap-3 sm:block sm:h-[430px]">
      <div
        className="product-hero-rise absolute end-[8%] top-[24%] hidden sm:block"
        style={riseStyle(0)}
      >
        <DomainEmblem />
      </div>

      <ArtPanel
        className="z-[1] sm:absolute sm:start-0 sm:top-[3%] sm:w-[300px]"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={120}
      >
        <div className="flex items-center justify-between gap-2">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Request')}
          </p>
          <span
            dir="ltr"
            className="rounded-md bg-[rgb(var(--tone-rgb)/0.12)] px-1.5 py-0.5 font-mono text-[10px] font-medium text-[var(--tone-ink)]"
          >
            POST /
          </span>
        </div>
        <p dir="ltr" className="mt-1.5 truncate text-start font-mono text-[12px] text-foreground">
          https://api.acme.io/
        </p>
        <div className="mt-3 space-y-1.5" dir="ltr">
          {REQUEST_HEADERS.map((header, index) => (
            <div
              key={header.name}
              className="product-hero-rise rounded-lg border border-border bg-muted/30 px-2.5 py-1.5"
              style={riseStyle(400 + index * 150)}
            >
              <p className="text-start font-mono text-[10px] text-muted-foreground">{header.name}</p>
              <p
                className={
                  header.accent
                    ? 'truncate text-start font-mono text-[10px] text-[var(--tone-ink)]'
                    : 'truncate text-start font-mono text-[10px] text-foreground'
                }
              >
                {header.value}
              </p>
            </div>
          ))}
        </div>
      </ArtPanel>

      <ArtPanel
        className="z-[1] sm:absolute sm:bottom-[3%] sm:start-[5%] sm:w-[280px]"
        innerClassName="px-3.5 py-3"
        delayMs={450}
        float
        floatDelayMs={700}
      >
        <p className="text-[12px] font-semibold text-foreground">{t('Function domain')}</p>
        <div className="mt-2.5 space-y-2">
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Generated domain')}
            </p>
            <p dir="ltr" className="mt-0.5 truncate text-start font-mono text-[11px] text-foreground">
              65f1a2b3.appwrite.run
            </p>
          </div>
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Custom domains')}
            </p>
            <div className="mt-0.5 flex items-center justify-between gap-2">
              <p dir="ltr" className="truncate text-start font-mono text-[11px] text-foreground">
                api.acme.io
              </p>
              <Badge variant="verified" className="gap-1 text-[10px]">
                <ShieldCheck className="size-3" aria-hidden />
                {t('Verified')}
              </Badge>
            </div>
          </div>
        </div>
      </ArtPanel>

      <ArtPanel
        className="z-[1] sm:absolute sm:bottom-[16%] sm:end-0 sm:w-[200px]"
        innerClassName="px-3 py-2.5"
        delayMs={800}
        float
        floatDelayMs={1300}
      >
        <div dir="ltr" className="flex items-center gap-2">
          <Badge variant="success" className="font-mono text-[10px]">
            200
          </Badge>
          <span className="font-mono text-[10px] text-muted-foreground">89ms</span>
        </div>
        <pre dir="ltr" className="mt-2 text-start font-mono text-[11px] leading-5">
          <code>
            <T tone="punctuation">{'{ '}</T>
            <T tone="property">&quot;received&quot;</T>
            <T tone="punctuation">: </T>
            <T tone="keyword">true</T>
            <T tone="punctuation">{' }'}</T>
          </code>
        </pre>
      </ArtPanel>

      <ArtChip className="end-0 top-0 hidden w-[210px] sm:block" delayMs={1050} floatDelayMs={400}>
        <div className="flex items-start gap-2">
          <Lock className="mt-0.5 size-3.5 shrink-0 text-[var(--tone-ink)]" aria-hidden />
          <p className="text-[11px] leading-4 text-muted-foreground">
            {t('Pass a user JWT so Server SDKs inside the function respect Auth permissions.')}
          </p>
        </div>
      </ArtChip>
    </div>
  )
}
