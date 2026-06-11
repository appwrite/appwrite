import { ArrowRight, Sparkles } from 'lucide-react'
import { HomeSoftLights } from '@/components/pages/home/HomeSoftLights'
import { Button } from '@/components/ui/button'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { DOCS_FRAMEWORK_STRIP } from '@/lib/docs/framework-strip'
import { DocsRouteLink } from './DocsRouteLink'

export function DocsHeroSection() {
  return (
    <section className="relative isolate overflow-x-hidden border-b border-border bg-background">
      <HomeSoftLights variant="docs" />
      <div
        className="absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
        aria-hidden
      />

      <div className="relative z-[1] mx-auto w-full max-w-7xl px-4 pb-16 pt-12 text-left sm:px-6 sm:pb-20 sm:pt-16 lg:pb-24 lg:pt-20">
        <p className="text-[11px] font-semibold uppercase tracking-[0.25em] text-muted-foreground">
          Documentation
          <span className="text-[var(--brand-cta)]">_</span>
        </p>

        <div className="mt-5 flex justify-start sm:mt-6">
          <Button
            variant="outline"
            size="sm"
            className="h-7 rounded-full px-3 text-[12px]"
            asChild
          >
            <DocsRouteLink href="/docs/tooling/mcp">
              <Sparkles className="size-3.5" />
              <span className="text-[var(--brand-cta)]">New</span>
              MCP servers for AI agents
              <ArrowRight className="size-3.5" />
            </DocsRouteLink>
          </Button>
        </div>

        <h1 className="font-aeonik-pro mt-6 max-w-[600px] text-balance bg-[linear-gradient(145deg,#e8a8b6_0%,#c97d92_18%,var(--foreground)_46%)] bg-clip-text text-[32px] font-normal leading-[1.08] tracking-tight text-transparent dark:bg-[linear-gradient(145deg,#f8a1ba_0%,#ff7fa5_28%,#fff_62%)] sm:mt-8 sm:text-[40px] lg:text-[48px]">
          Ship faster with Appwrite
        </h1>

        <p className="mt-6 max-w-[600px] text-[14px] leading-7 text-muted-foreground sm:mt-7 sm:text-[15px] sm:leading-8">
          Build secure and scalable apps with guides for Authentication, Databases, Storage,
          Functions, Messaging, Realtime, and hosting.
        </p>

        <div className="mt-8 flex flex-wrap items-center justify-start gap-2 sm:mt-10">
          <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
            <DocsRouteLink href="/docs/quick-starts">
              Get started
              <ArrowRight className="ml-1.5 size-4" />
            </DocsRouteLink>
          </Button>
          <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
            <DocsRouteLink href="/docs/references">API references</DocsRouteLink>
          </Button>
        </div>

        <div className="mt-14 w-full min-w-0 sm:mt-16 lg:mt-20">
          <p className="font-aeonik-pro max-w-[600px] text-[16px] font-normal tracking-tight text-foreground sm:text-[18px]">
            Quick starts for the frameworks you love
            <span className="text-[var(--brand-cta)]">_</span>
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-start gap-x-7 gap-y-6 sm:mt-10 sm:gap-x-8">
            {DOCS_FRAMEWORK_STRIP.map((tool) => (
              <DocsRouteLink
                key={tool.href}
                href={tool.href}
                aria-label={tool.name}
                title={tool.name}
                className="group flex size-9 items-center justify-center transition-transform duration-200 hover:scale-110"
              >
                <img
                  src={tool.iconSrc}
                  alt=""
                  className={cn('size-7', PUBLIC_ICON_MUTED_CLASSES, 'group-hover:opacity-80')}
                />
              </DocsRouteLink>
            ))}
          </div>
          <div className="mt-8 flex justify-start sm:mt-10">
            <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
              <DocsRouteLink href="/docs/quick-starts">
                All quick start guides
                <ArrowRight className="ml-1.5 size-4" />
              </DocsRouteLink>
            </Button>
          </div>
        </div>
      </div>
    </section>
  )
}
