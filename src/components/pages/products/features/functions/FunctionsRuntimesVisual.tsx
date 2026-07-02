import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const FEATURED_RUNTIMES = [
  { id: 'node', name: 'Node.js', version: 'node-22' },
  { id: 'bun', name: 'Bun', version: 'bun-1.3' },
  { id: 'python', name: 'Python', version: 'python-3.12' },
  { id: 'go', name: 'Go', version: 'go-1.23' },
  { id: 'dart', name: 'Dart', version: 'dart-3.11' },
  { id: 'php', name: 'PHP', version: 'php-8.3' },
  { id: 'ruby', name: 'Ruby', version: 'ruby-3.3' },
  { id: 'rust', name: 'Rust', version: 'rust-1.83' },
  { id: 'deno', name: 'Deno', version: 'deno-2.0' },
] as const

const MORE_RUNTIMES = [
  { id: 'dotnet', name: '.NET' },
  { id: 'java', name: 'Java' },
  { id: 'swift', name: 'Swift' },
  { id: 'kotlin', name: 'Kotlin' },
  { id: 'flutter', name: 'Flutter' },
  { id: 'cpp', name: 'C++' },
] as const

export function FunctionsRuntimesVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame
      eyebrow="Runtime"
      title="node-22"
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="text-[13px] font-semibold text-foreground">{t('Your language, your runtime')}</p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {t('Pin your version. Deploy isolated. Built for production.')}
            </p>
          </div>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('13+ runtimes')}
          </Badge>
        </div>

        <div>
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Ready to deploy')}
          </p>
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-3">
            {FEATURED_RUNTIMES.map((runtime, index) => (
              <div
                key={runtime.id}
                className="flex flex-col gap-2 rounded-lg border border-border bg-background/80 p-2.5 transition-[border-color,background-color] duration-300 group-hover/visual:border-foreground/10 group-hover/visual:bg-muted/40"
                style={{ transitionDelay: `${index * 40}ms` }}
              >
                <div className="flex items-center justify-between gap-2">
                  <LanguageIcon language={runtime.id} size="sm" />
                  <span className="truncate font-mono text-[9px] text-muted-foreground">
                    {runtime.version}
                  </span>
                </div>
                <span className="text-[11px] font-medium text-foreground">{runtime.name}</span>
              </div>
            ))}
          </div>
        </div>

        <div>
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('And more')}
          </p>
          <div className="grid grid-cols-3 gap-1.5 sm:grid-cols-6">
            {MORE_RUNTIMES.map((runtime) => (
              <div
                key={runtime.id}
                className="flex flex-col items-center gap-1 rounded-md border border-border bg-background/60 px-1.5 py-2 opacity-70 transition-opacity duration-300 group-hover/visual:opacity-100 motion-reduce:opacity-100"
              >
                <LanguageIcon language={runtime.id} size="sm" />
                <span className="truncate text-[9px] text-muted-foreground">{runtime.name}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
