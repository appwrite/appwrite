'use client'

import { Button } from '@/components/ui/button'
import {
  docsContentPaddingX,
  docsPreviewPrimaryTitleClass,
  docsPreviewSectionPaddingY,
} from '@/lib/docs/docs-container'
import { DOCS_FRAMEWORK_STRIP } from '@/lib/docs/framework-strip'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from './DocsRouteLink'

const PREVIEW_HERO_FRAMEWORK_COUNT = 8

const FRAMEWORK_CHIP_CLASS = cn(
  'flex size-8 items-center justify-center rounded-lg border border-border bg-card/45',
  'transition-colors hover:bg-accent/15',
)

export function DocsPreviewHeroSection() {
  const frameworks = DOCS_FRAMEWORK_STRIP.slice(0, PREVIEW_HERO_FRAMEWORK_COUNT)

  return (
    <section className={cn('border-b border-border', docsPreviewSectionPaddingY)}>
      <div className={cn('mx-auto w-full max-w-6xl text-start', docsContentPaddingX)}>
        <h1 className={cn('max-w-[600px]', docsPreviewPrimaryTitleClass)}>
          Ship faster with Appwrite
        </h1>

        <p className="mt-6 max-w-[600px] text-[13px] leading-[1.6] text-muted-foreground @[480px]:mt-7 @[480px]:text-[14px]">
          Build secure and scalable apps with guides for Authentication, Databases, Storage,
          Functions, Messaging, Realtime, and hosting.
        </p>

        <div className="mt-8 flex flex-wrap items-center justify-start gap-2 @[480px]:mt-10">
          <Button variant="outline" size="sm" className="h-8 text-[12px]" asChild>
            <DocsRouteLink href="/docs/quick-starts" previewView="menu">
              Quick starts
            </DocsRouteLink>
          </Button>
          <Button variant="outline" size="sm" className="h-8 text-[12px]" asChild>
            <DocsRouteLink href="/docs/references">API references</DocsRouteLink>
          </Button>
        </div>

        <div className="mt-14 w-full min-w-0 @[480px]:mt-16">
          <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            Popular frameworks
          </p>
          <div className="mt-8 flex flex-wrap items-center gap-2 @[480px]:mt-10">
            {frameworks.map((tool) => (
              <DocsRouteLink
                key={tool.href}
                href={tool.href}
                aria-label={tool.name}
                title={tool.name}
                className={FRAMEWORK_CHIP_CLASS}
              >
                <img
                  src={tool.iconSrc}
                  alt=""
                  className={cn('size-4', PUBLIC_ICON_MUTED_CLASSES)}
                />
              </DocsRouteLink>
            ))}
          </div>
          <div className="mt-8 flex justify-start @[480px]:mt-10">
            <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
              <DocsRouteLink href="/docs/quick-starts" previewView="menu">
                View all quick start guides
              </DocsRouteLink>
            </Button>
          </div>
        </div>
      </div>
    </section>
  )
}
