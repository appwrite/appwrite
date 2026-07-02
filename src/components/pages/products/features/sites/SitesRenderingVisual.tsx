import { FileCode, Server } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ProductFeatureVisualFrame } from '@/components/pages/products/features/_components/ProductFeatureVisualFrame'
import { useT } from '@/lib/i18n/translate'

const MODES = [
  {
    id: 'static',
    title: 'Static, SPA, and PWA',
    description: 'Pages built at deploy time. Fast cold starts for Vite, Astro, React, and Vue.',
    icon: FileCode,
    examples: ['Astro', 'React', 'Vue'],
  },
  {
    id: 'ssr',
    title: 'Server-side rendering',
    description: 'Render on every request at the edge. Runtime env vars and framework-native 404 pages.',
    icon: Server,
    examples: ['Next.js', 'Nuxt', 'SvelteKit'],
  },
] as const

export function SitesRenderingVisual() {
  const t = useT()
  return (
    <ProductFeatureVisualFrame eyebrow={t('Rendering modes')} title={t('Static and SSR hosting')}>
      <div className="space-y-4">
        <div className="grid gap-2 sm:grid-cols-2">
          {MODES.map((mode) => (
            <div
              key={mode.id}
              className="rounded-lg border border-border bg-background/80 p-3 transition-[border-color,background-color] duration-300 group-hover/visual:border-foreground/10 group-hover/visual:bg-muted/30"
            >
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
                <mode.icon className="size-3.5 text-muted-foreground" aria-hidden />
              </span>
              <p className="mt-2 text-[12px] font-semibold text-foreground">{t(mode.title)}</p>
              <p className="mt-1 text-[11px] leading-5 text-muted-foreground">{t(mode.description)}</p>
              <div className="mt-2.5 flex flex-wrap gap-1.5">
                {mode.examples.map((example) => (
                  <Badge key={example} variant="outline" className="text-[10px]">
                    {example}
                  </Badge>
                ))}
              </div>
            </div>
          ))}
        </div>

        <div className="rounded-lg border border-border bg-muted/15 px-3 py-2.5">
          <p className="text-[11px] leading-5 text-muted-foreground">
            {t('Hybrid apps are supported too. Frameworks like Next.js, Nuxt, and SvelteKit can mix static pages with server-rendered routes in the same site.')}
          </p>
        </div>
      </div>
    </ProductFeatureVisualFrame>
  )
}
