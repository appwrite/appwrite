import { ArrowRight } from 'lucide-react'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import { docsContentPaddingX } from '@/lib/docs/docs-container'
import { DOCS_PARTNERS_HOME_HERO } from '@/lib/docs/partners-home-content'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from './DocsRouteLink'

export function DocsPartnersHeroSection() {
  return (
    <section className="relative isolate overflow-hidden border-b border-border bg-background">
      <HomeSoftLights variant="partners" />
      <div
        className="absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
        aria-hidden
      />

      <div
        className={cn(
          'relative z-[1] mx-auto w-full max-w-6xl pb-16 pt-12 text-start',
          docsContentPaddingX,
          '@[480px]:pb-20 @[480px]:pt-16 @[900px]:pb-24 @[900px]:pt-20',
        )}
      >
        <p className="text-[11px] font-semibold uppercase tracking-[0.25em] text-muted-foreground">
          Partner documentation
          <span className="text-[var(--brand-cta)]">_</span>
        </p>

        <h1 className="font-aeonik-pro mt-5 max-w-[640px] text-balance text-[32px] font-normal leading-[1.08] tracking-tight text-foreground @[480px]:mt-6 @[480px]:text-[40px] @[900px]:text-[48px]">
          {DOCS_PARTNERS_HOME_HERO.title}
        </h1>

        <p className="mt-6 max-w-[640px] text-[14px] leading-7 text-muted-foreground @[480px]:mt-7 @[480px]:text-[15px] @[480px]:leading-8">
          {DOCS_PARTNERS_HOME_HERO.description}
        </p>

        <div className="mt-8 flex flex-wrap items-center justify-start gap-2 @[480px]:mt-10">
          <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
            <DocsRouteLink href="/docs/partners/quick-start">
              Quick start
              <ArrowRight className="ms-1.5 size-4" />
            </DocsRouteLink>
          </Button>
          <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
            <DocsRouteLink href="/docs/partners/architecture">Architecture</DocsRouteLink>
          </Button>
        </div>
      </div>
    </section>
  )
}
