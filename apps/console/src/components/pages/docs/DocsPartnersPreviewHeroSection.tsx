'use client'

import { Button } from '@/components/ui/button'
import {
  docsContentPaddingX,
  docsPreviewPrimaryTitleClass,
  docsPreviewSectionPaddingY,
} from '@/lib/docs/docs-container'
import { DOCS_PARTNERS_HOME_HERO } from '@/lib/docs/partners-home-content'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from './DocsRouteLink'

export function DocsPartnersPreviewHeroSection() {
  return (
    <section className={cn('border-b border-border', docsPreviewSectionPaddingY)}>
      <div className={cn('mx-auto w-full max-w-6xl text-start', docsContentPaddingX)}>
        <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          Partner documentation
        </p>

        <h1 className={cn('mt-4 max-w-[640px] text-foreground', docsPreviewPrimaryTitleClass)}>
          {DOCS_PARTNERS_HOME_HERO.title}
        </h1>

        <p className="mt-6 max-w-[640px] text-[13px] leading-[1.6] text-muted-foreground @[480px]:mt-7 @[480px]:text-[14px]">
          {DOCS_PARTNERS_HOME_HERO.description}
        </p>

        <div className="mt-8 flex flex-wrap items-center justify-start gap-2 @[480px]:mt-10">
          <Button variant="outline" size="sm" className="h-8 text-[12px]" asChild>
            <DocsRouteLink href="/docs/partners/quick-start">Quick start</DocsRouteLink>
          </Button>
          <Button variant="outline" size="sm" className="h-8 text-[12px]" asChild>
            <DocsRouteLink href="/docs/partners/architecture">Architecture</DocsRouteLink>
          </Button>
        </div>
      </div>
    </section>
  )
}
