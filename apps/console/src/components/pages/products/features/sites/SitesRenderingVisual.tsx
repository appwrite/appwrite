import { FileCode, Globe, Layers, Server } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtIconBadge,
  ArtPanel,
  floatStyle,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'

const MODES = [
  {
    id: 'static',
    title: 'Static, SPA, and PWA',
    description: 'Pages built at deploy time. Fast cold starts for Vite, Astro, React, and Vue.',
    icon: FileCode,
    tone: 'primary',
    request: { path: '/docs', time: '8ms', cache: 'Hit' },
    frameworks: [
      { id: 'astro', icon: '/icons/astro.svg' },
      { id: 'react', icon: '/icons/react.svg' },
      { id: 'vue', icon: '/icons/vue.svg' },
    ],
  },
  {
    id: 'ssr',
    title: 'Server-side rendering',
    description: 'Render on every request at the edge. Runtime env vars and framework-native 404 pages.',
    icon: Server,
    tone: 'secondary',
    request: { path: '/dashboard', time: '118ms', cache: 'Miss' },
    frameworks: [
      { id: 'nextjs', icon: '/icons/nextjs.svg' },
      { id: 'nuxt', icon: '/icons/nuxt.svg' },
      { id: 'svelte', icon: '/icons/svelte.svg' },
    ],
  },
] as const

export function SitesRenderingVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-2">
      <ArtPanel
        className="mx-auto w-fit"
        innerClassName="product-tone-shadow flex items-center gap-2.5 px-3.5 py-2.5"
        delayMs={60}
      >
        <ArtIconBadge icon={Globe} />
        <div>
          <p className="text-[12px] font-semibold text-foreground">{t('Rendering modes')}</p>
          <p dir="ltr" className="font-mono text-[11px] text-muted-foreground">acme.io/*</p>
        </div>
      </ArtPanel>

      <div className="relative mx-auto hidden h-9 w-1/2 sm:block" aria-hidden>
        <span className="absolute start-1/2 top-0 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute inset-x-0 top-1/2 border-t border-dashed border-foreground/25" />
        <span className="absolute start-0 top-1/2 h-1/2 border-s border-dashed border-foreground/25" />
        <span className="absolute end-0 top-1/2 h-1/2 border-e border-dashed border-foreground/25" />
      </div>

      <div className="mt-5 grid gap-6 sm:mt-0 sm:grid-cols-2 sm:gap-3">
        {MODES.map((mode, index) => (
          <div key={mode.id} className="min-w-0">
            <ArtPanel
              delayMs={300 + index * 160}
              float
              floatDelayMs={index * 700}
              innerClassName="px-3.5 py-3"
            >
              <div className="flex items-center gap-2">
                <ArtIconBadge icon={mode.icon} tone={mode.tone} />
                <p className="text-[12px] font-semibold leading-4 text-foreground">{t(mode.title)}</p>
              </div>
              <p className="mt-2 text-[11px] leading-[1.55] text-muted-foreground">{t(mode.description)}</p>
              <div className="mt-3 flex items-center gap-2 rounded-lg border border-border bg-muted/30 px-2.5 py-1.5">
                <span dir="ltr" className="min-w-0 flex-1 truncate font-mono text-[10px] text-foreground">
                  GET {mode.request.path}
                </span>
                <span dir="ltr" className="shrink-0 font-mono text-[10px] text-muted-foreground">
                  {mode.request.time}
                </span>
                <Badge variant={mode.request.cache === 'Hit' ? 'success' : 'inactive'} className="shrink-0 text-[10px]">
                  {t(mode.request.cache)}
                </Badge>
              </div>
            </ArtPanel>

            <div className="mt-3 flex justify-center gap-2" aria-hidden>
              {mode.frameworks.map((framework, frameworkIndex) => (
                <span
                  key={framework.id}
                  className="product-hero-rise"
                  style={riseStyle(650 + index * 200 + frameworkIndex * 90)}
                >
                  <span
                    className="product-hero-float flex size-10 items-center justify-center rounded-xl border border-border bg-background shadow-sm dark:bg-card"
                    style={floatStyle((index * 3 + frameworkIndex) * 420)}
                  >
                    <ProductFeaturePublicIcon src={framework.icon} className="size-4" />
                  </span>
                </span>
              ))}
            </div>
          </div>
        ))}
      </div>

      <ArtPanel
        className="mx-auto mt-6 max-w-[420px]"
        innerClassName="flex items-start gap-2.5 px-3 py-2.5"
        delayMs={1100}
      >
        <ArtIconBadge icon={Layers} tone="neutral" />
        <p className="text-[11px] leading-[1.55] text-muted-foreground">
          {t('Hybrid apps are supported too. Frameworks like Next.js, Nuxt, and SvelteKit can mix static pages with server-rendered routes in the same site.')}
        </p>
      </ArtPanel>
    </div>
  )
}
