import { ArrowRight } from 'lucide-react'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { docsContentPaddingX } from '@/lib/docs/docs-container'
import { DOCS_FRAMEWORK_STRIP } from '@/lib/docs/framework-strip'
import { DocsRouteLink } from './DocsRouteLink'
import { analyticsAttrs } from '@/lib/analytics-actions'

export function DocsHeroSection() {
  return (
    <section className="relative isolate overflow-hidden border-b border-border bg-background">
      <HomeSoftLights variant="docs" />
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
          Documentation
          <span className="text-[var(--brand-cta)]">_</span>
        </p>

        <div className="mt-5 flex justify-start @[480px]:mt-6">
          <Button
            variant="outline"
            size="sm"
            className="h-7 rounded-full px-3 text-[12px]"
            asChild
          >
            <DocsRouteLink href="/docs/tooling/mcp" {...analyticsAttrs('docs-mcp-cta')}>
              <McpIcon className="size-3.5 text-muted-foreground" />
              <span className="text-[var(--brand-cta)]">New</span>
              MCP servers for AI agents
              <ArrowRight className="size-3.5" />
            </DocsRouteLink>
          </Button>
        </div>

        <h1 className="font-aeonik-pro mt-6 max-w-[600px] text-balance text-[32px] font-normal leading-[1.08] tracking-tight text-foreground @[480px]:mt-8 @[480px]:text-[40px] @[900px]:text-[48px]">
          Ship faster with Appwrite
        </h1>

        <p className="mt-6 max-w-[600px] text-[14px] leading-7 text-muted-foreground @[480px]:mt-7 @[480px]:text-[15px] @[480px]:leading-8">
          Build secure and scalable apps with guides for Authentication, Databases, Storage,
          Functions, Messaging, Realtime, and hosting.
        </p>

        <div className="mt-8 flex flex-wrap items-center justify-start gap-2 @[480px]:mt-10">
          <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
            <DocsRouteLink
              href="/docs/quick-starts"
              {...analyticsAttrs('docs-get-started')}
            >
              Get started
              <ArrowRight className="ms-1.5 size-4" />
            </DocsRouteLink>
          </Button>
          <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
            <DocsRouteLink
              href="/docs/references"
              {...analyticsAttrs('docs-api-references')}
            >
              API references
            </DocsRouteLink>
          </Button>
        </div>

        <div className="mt-14 w-full min-w-0 @[480px]:mt-16 @[900px]:mt-20">
          <p className="font-aeonik-pro max-w-[600px] text-[16px] font-normal tracking-tight text-foreground @[480px]:text-[18px]">
            Quick starts for the frameworks you love
            <span className="text-[var(--brand-cta)]">_</span>
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-start gap-x-7 gap-y-6 @[480px]:mt-10 @[480px]:gap-x-8">
            {DOCS_FRAMEWORK_STRIP.map((tool) => (
              <DocsRouteLink
                key={tool.href}
                href={tool.href}
                aria-label={tool.name}
                title={tool.name}
                className="flex size-9 items-center justify-center"
              >
                <img
                  src={tool.iconSrc}
                  alt=""
                  className={cn('size-7', PUBLIC_ICON_MUTED_CLASSES)}
                />
              </DocsRouteLink>
            ))}
          </div>
          <div className="mt-8 flex justify-start @[480px]:mt-10">
            <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
              <DocsRouteLink
                href="/docs/quick-starts"
                {...analyticsAttrs('docs-all-quick-starts')}
              >
                All quick start guides
                <ArrowRight className="ms-1.5 size-4" />
              </DocsRouteLink>
            </Button>
          </div>
        </div>
      </div>
    </section>
  )
}
