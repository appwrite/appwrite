import { riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductVisualAura } from '@/components/pages/products/_components/ProductTone'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { AppwriteMark, CompetitorMonogram } from './ComparisonParts'

export const APPWRITE_RUNTIMES = [
  { name: 'Node.js', icon: '/icons/node.svg' },
  { name: 'Python', icon: '/icons/python.svg' },
  { name: 'Go', icon: '/icons/go.svg' },
  { name: 'Bun', icon: '/icons/bun.svg' },
  { name: 'Deno', icon: '/icons/deno.svg' },
  { name: 'Dart', icon: '/icons/dart.svg' },
  { name: 'PHP', icon: '/icons/php.svg' },
  { name: 'Ruby', icon: '/icons/ruby.svg' },
  { name: 'Rust', icon: '/icons/rust.svg' },
  { name: '.NET', icon: '/icons/dotnet.svg' },
  { name: 'Java', icon: '/icons/java.svg' },
  { name: 'Kotlin', icon: '/icons/kotlin.svg' },
  { name: 'Swift', icon: '/icons/swift.svg' },
] as const

/** An open wall of Appwrite runtimes, with the other platform's single language as a footnote. */
export function RuntimeWall({
  competitorName,
  competitorRuntime,
  competitorIcon = '/icons/ts.svg',
  competitorNote,
}: {
  competitorName: string
  competitorRuntime: string
  competitorIcon?: string
  /** Replaces the "1 language" count when the other platform has a caveat worth stating. */
  competitorNote?: string
}) {
  const t = useT()
  return (
    <ProductVisualAura>
      <div className="flex flex-wrap items-end justify-between gap-3 gap-y-2">
        <p className="flex min-w-0 items-center gap-2 text-[13px] font-medium text-foreground">
          <AppwriteMark className="size-4 shrink-0" />
          Appwrite
        </p>
        <p className="flex shrink-0 items-baseline gap-2">
          <span className="font-aeonik-pro text-[32px] leading-none tracking-tight text-[var(--tone-ink)] sm:text-[40px]">
            {APPWRITE_RUNTIMES.length}
          </span>
          <span className="text-[12px] text-muted-foreground">{t('runtimes')}</span>
        </p>
      </div>

      <ul className="mt-6 grid grid-cols-4 gap-x-2 gap-y-5 sm:mt-7 sm:grid-cols-7 sm:gap-x-3 sm:gap-y-6">
        {APPWRITE_RUNTIMES.map((runtime, index) => (
          <li
            key={runtime.name}
            className="product-hero-rise group flex flex-col items-center gap-2.5"
            style={riseStyle(160 + index * 45)}
          >
            <span className="flex size-12 items-center justify-center rounded-2xl border border-border bg-background shadow-sm transition-[transform,border-color] duration-200 group-hover:-translate-y-0.5 group-hover:border-[rgb(var(--tone-rgb)/0.5)] dark:bg-card">
              <ProductFeaturePublicIcon src={runtime.icon} className="size-6" />
            </span>
            <span dir="ltr" className="text-[11px] font-medium text-foreground/80">
              {runtime.name}
            </span>
          </li>
        ))}
      </ul>

      <div
        className="product-hero-rise mt-8 flex flex-col gap-3 border-t border-dashed border-foreground/20 pt-5 sm:mt-9 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
        style={riseStyle(860)}
      >
        <p className="flex min-w-0 flex-wrap items-center gap-2.5 text-[13px] text-muted-foreground">
          <CompetitorMonogram name={competitorName} />
          {competitorName}
          <span className="inline-flex items-center gap-1.5 text-foreground/70">
            <ProductFeaturePublicIcon src={competitorIcon} tone="muted-foreground" className="size-3.5" />
            {t(competitorRuntime)}
          </span>
        </p>
        {competitorNote ? (
          <p className="max-w-xs text-[12px] leading-5 text-muted-foreground sm:text-end">{t(competitorNote)}</p>
        ) : (
          <p className="flex shrink-0 items-baseline gap-2">
            <span className="font-aeonik-pro text-[28px] leading-none text-foreground/40">1</span>
            <span className="text-[12px] text-muted-foreground">{t('language')}</span>
          </p>
        )}
      </div>
    </ProductVisualAura>
  )
}
