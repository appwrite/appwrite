import { createFileRoute, Link } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import {
  ArrowRight,
  BadgeCheck,
  ChevronRight,
  Database,
  DatabaseBackup,
  Folder,
  Globe,
  Globe2,
  HeartPulse,
  LockKeyhole,
  MessageSquare,
  Pentagon,
  Megaphone,
  Radio,
  Scale,
  Shield,
  ShieldCheck,
  Users,
  Zap,
} from 'lucide-react'
import { useMemo } from 'react'
import type { LucideIcon } from 'lucide-react'
import { MarketingProductPills } from '@/components/pages/marketing/MarketingProductPills'
import { AiSection } from '@/components/pages/home/AiSection'
import { InitSection } from '@/components/pages/home/InitSection'
import { NetworkSection } from '@/components/pages/home/NetworkSection'
import { PricingSection } from '@/components/pages/home/PricingSection'
import { ScaleSection } from '@/components/pages/home/ScaleSection'
import {
  HomeSoftLights,
  ProductBentoHoverLight,
  ProductBentoSoftLights,
} from '@/components/pages/home/HomeSoftLights'
import { HomeHashScroll } from '@/components/pages/home/HomeHashScroll'
import { TestimonialsSection } from '@/components/pages/home/TestimonialsSection'
import { ProductBentoVisualDeferred } from '@/components/pages/home/product-bento/ProductBentoVisualDeferred'
import { ProductBentoCardLink } from '@/components/pages/home/product-bento/ProductBentoCardLink'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { marketingProductToolkit } from '@/lib/marketing/product-toolkit'
import { PRODUCT_NAV_REGISTRY } from '@/lib/products/registry'
import type { ProductNavItemId } from '@/lib/products/types'
import {
  getMarketingHomeOgImage,
  getMarketingPageMetaTags,
} from '@/lib/marketing/route-meta'
import { getEnglishCatalog, useI18n } from '@/lib/i18n'

const HOME_COPY = getEnglishCatalog().website.home

const frameworkTools = [
  { name: HOME_COPY.frameworkTools.react, icon: '/icons/react.svg', href: '/docs/quick-starts/react' },
  { name: HOME_COPY.frameworkTools.tanstackStart, icon: '/icons/tanstack.svg', href: '/docs/quick-starts/tanstack-start' },
  { name: HOME_COPY.frameworkTools.nextjs, icon: '/icons/nextjs.svg', href: '/docs/quick-starts/nextjs' },
  { name: HOME_COPY.frameworkTools.vue, icon: '/icons/vue.svg', href: '/docs/quick-starts/vue' },
  { name: HOME_COPY.frameworkTools.sveltekit, icon: '/icons/svelte.svg', href: '/docs/quick-starts/sveltekit' },
  { name: HOME_COPY.frameworkTools.android, icon: '/icons/android.svg', href: '/docs/quick-starts/android' },
  { name: HOME_COPY.frameworkTools.ios, icon: '/icons/apple.svg', href: '/docs/quick-starts/apple' },
  { name: HOME_COPY.frameworkTools.flutter, icon: '/icons/flutter.svg', href: '/docs/quick-starts/flutter' },
  { name: HOME_COPY.frameworkTools.claude, icon: '/icons/claude.svg', href: '/docs/tooling/mcp/claude-code' },
  { name: HOME_COPY.frameworkTools.chatgpt, icon: '/icons/chatgpt.svg', href: '/docs/tooling/ai/agents/codex' },
  { name: HOME_COPY.frameworkTools.cursor, icon: '/icons/cursor-ai.svg', href: '/docs/tooling/mcp/cursor' },
  { name: HOME_COPY.frameworkTools.lovable, icon: '/icons/lovable.svg', href: '/docs/tooling/ai/vibe-coding/lovable' },
  { name: HOME_COPY.frameworkTools.opencode, icon: '/icons/opencode.svg', href: '/docs/tooling/mcp/opencode' },
  { name: HOME_COPY.frameworkTools.bun, icon: '/icons/bun.svg', href: '/docs/products/functions/runtimes' },
] as const

const aiDocLinks = [
  { label: HOME_COPY.aiDocLinks.mcpServers, href: '/docs/tooling/ai/mcp-servers' },
  { label: HOME_COPY.aiDocLinks.skills, href: '/docs/tooling/ai/skills' }, // pragma: allowlist secret
  { label: HOME_COPY.aiDocLinks.aiArena, href: 'https://arena.appwrite.io/', external: true }, // pragma: allowlist secret
] as const

type ProductBentoProductId = Extract<
  ProductNavItemId,
  | 'auth'
  | 'databases'
  | 'storage'
  | 'functions'
  | 'sites'
  | 'messaging'
  | 'firewall'
  | 'realtime'
>

type ProductBentoLayoutItem = {
  id: ProductBentoProductId
  icon: LucideIcon
  className: string
  tall?: boolean
  /** Tighter copy block so the visual gets more room (bottom row). */
  compact?: boolean
  /** Taller article + visual min-heights below lg (complex stacked visuals). */
  mobileVisualTall?: boolean
  badgeLabelKey?: 'firewallNewLabel'
}

const productBentoLayout: ProductBentoLayoutItem[] = [
  {
    id: 'auth',
    icon: Users,
    className:
      'lg:col-span-4 lg:col-start-1 lg:row-start-1 lg:row-span-2',
  },
  {
    id: 'databases',
    icon: Database,
    className:
      'lg:col-span-8 lg:col-start-5 lg:row-start-1 lg:row-span-3',
    tall: true,
    mobileVisualTall: true,
  },
  {
    id: 'storage',
    icon: Folder,
    className:
      'lg:col-span-4 lg:col-start-1 lg:row-start-3 lg:row-span-2',
  },
  {
    id: 'functions',
    icon: Zap,
    className:
      'lg:col-span-4 lg:col-start-1 lg:row-start-5 lg:row-span-2',
  },
  {
    id: 'sites',
    icon: Globe,
    className:
      'lg:col-span-8 lg:col-start-5 lg:row-start-4 lg:row-span-3',
    tall: true,
  },
  {
    id: 'messaging',
    icon: MessageSquare,
    className:
      'lg:col-span-4 lg:col-start-1 lg:row-start-7 lg:row-span-2',
    compact: true,
  },
  {
    id: 'firewall',
    icon: Shield,
    className:
      'lg:col-span-4 lg:col-start-5 lg:row-start-7 lg:row-span-2',
    compact: true,
    badgeLabelKey: 'firewallNewLabel',
  },
  {
    id: 'realtime',
    icon: Radio,
    className:
      'lg:col-span-4 lg:col-start-9 lg:row-start-7 lg:row-span-2',
    compact: true,
  },
]

type HomeCopy = ReturnType<typeof getEnglishCatalog>['website']['home']

function getProductBentoItems(homeCopy: HomeCopy) {
  const productBento = homeCopy.productBento

  return productBentoLayout.map((item) => ({
    ...item,
    title: productBento[`${item.id}Title` as keyof typeof productBento] as string,
    description: productBento[
      `${item.id}Description` as keyof typeof productBento
    ] as string,
    label: item.badgeLabelKey ? productBento[item.badgeLabelKey] : undefined,
    href: PRODUCT_NAV_REGISTRY[item.id].href,
  }))
}

function getSecurityItems(homeCopy: HomeCopy) {
  const items = homeCopy.securityItems

  return [
    {
      id: 'ddos',
      title: items.ddosTitle,
      description: items.ddosDescription,
      icon: ShieldCheck,
    },
    {
      id: 'encryption',
      title: items.encryptionTitle,
      description: items.encryptionDescription,
      icon: LockKeyhole,
    },
    {
      id: 'abuse',
      title: items.abuseTitle,
      description: items.abuseDescription,
      icon: BadgeCheck,
    },
    {
      id: 'migrations',
      title: items.migrationsTitle,
      description: items.migrationsDescription,
      icon: DatabaseBackup,
    },
    {
      id: 'gdpr',
      title: items.gdprTitle,
      description: items.gdprDescription,
      icon: Globe2,
    },
    {
      id: 'soc2',
      title: items.soc2Title,
      description: items.soc2Description,
      icon: Pentagon,
    },
    {
      id: 'hipaa',
      title: items.hipaaTitle,
      description: items.hipaaDescription,
      icon: HeartPulse,
    },
    {
      id: 'ccpa',
      title: items.ccpaTitle,
      description: items.ccpaDescription,
      icon: Scale,
    },
  ] as const
}

/** Bump when replacing homepage hero dashboard screenshots so caches refetch. */
const HOME_HERO_IMAGE_CACHE_BUST = '20260729'
const HOME_HERO_LIGHT_SRC = `/images/heroes/console-app-light.avif?v=${HOME_HERO_IMAGE_CACHE_BUST}`
const HOME_HERO_DARK_SRC = `/images/heroes/console-app-dark.avif?v=${HOME_HERO_IMAGE_CACHE_BUST}`

export const Route = createFileRoute('/_marketing/home')({
  staticData: {
    ...MARKETING_PAGE_ROUTE_STATIC_DATA,
    headerBanner: 'init-org-promo',
  },
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Home',
      description: HOME_COPY.seoDescription,
      ogImage: getMarketingHomeOgImage(),
    }),
    links: [
      {
        rel: 'preload',
        as: 'image',
        href: HOME_HERO_LIGHT_SRC,
        media: '(prefers-color-scheme: light)',
      },
      {
        rel: 'preload',
        as: 'image',
        href: HOME_HERO_DARK_SRC,
        media: '(prefers-color-scheme: dark)',
      },
    ],
  }),
  loader: async ({ context }) => {
  },
  component: HomePage,
})

function HomePage() {
  const { catalog } = useI18n()
  const homeCopy = catalog.website.home
  const productBentoItems = useMemo(
    () => getProductBentoItems(homeCopy),
    [homeCopy],
  )
  const securityItems = useMemo(
    () => getSecurityItems(homeCopy),
    [homeCopy],
  )

  return (
    <>
      <HomeHashScroll />
        <section className="relative isolate overflow-hidden border-b border-border bg-background">
          <HomeSoftLights />
          <div
            className="absolute inset-0 z-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
            aria-hidden
          />
          <div className="relative z-[1] mx-auto flex w-full max-w-7xl flex-col items-center px-4 pb-0 pt-14 text-center sm:px-6 sm:pt-20">
            <Button
              variant="outline"
              size="sm"
              className="h-7 rounded-full px-3 text-[12px]"
              asChild
            >
              <MarketingSiteLink href="/init">
                <Megaphone className="size-3.5" />
                <span className="text-[var(--brand-cta)]">{homeCopy.announcementNew}</span>
                {homeCopy.announcementText}
                <ArrowRight className="size-3.5" />
              </MarketingSiteLink>
            </Button>

            <h1 className="font-aeonik-pro text-gradient-brand mt-6 max-w-6xl pb-3 text-balance text-[48px] font-normal leading-[1.04] tracking-[-0.022em] lg:text-[76px]">
              {homeCopy.heroTitleLineOne}
              <br />
              {homeCopy.heroTitleLineTwo}
              <span className="text-[var(--brand-cta)]">_</span>
            </h1>

            <p className="mx-auto mt-5 max-w-2xl text-balance text-[15px] leading-6 text-muted-foreground sm:text-[16px] sm:leading-7">
              {homeCopy.heroDescription}
            </p>

            <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
              <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
                <Link
                  to="/sign-up"
                  search={{ redirect: '/' }}
                  {...analyticsAttrs('home-start-project')}
                >
                  {homeCopy.startProject}
                </Link>
              </Button>
              <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
                <MarketingSiteLink
                  href="/enterprise"
                  {...analyticsAttrs('home-request-demo')}
                >
                  {homeCopy.requestDemo}
                </MarketingSiteLink>
              </Button>
            </div>
          </div>

          <div className="relative z-[1] mt-8 w-full sm:mt-10">
            <div className="mx-auto w-full max-w-full px-3 sm:max-w-[min(100vw-3rem,84rem)] sm:px-6 lg:max-w-[min(100vw-4rem,88rem)]">
              <div className="relative isolate z-[1] flex w-full flex-col overflow-hidden rounded-t-[20px] border-x-2 border-t-2 border-b-0 border-muted-foreground/8 bg-muted-foreground/[0.035] px-1.5 pb-0 pt-1 sm:rounded-t-[28px] sm:px-4 dark:border-muted/30 dark:bg-muted/10">
                <div className="relative z-10 flex h-8 shrink-0 items-center gap-2 text-start sm:h-10">
                  <div className="ms-1.5 flex items-center gap-1.5 sm:ms-2" aria-hidden>
                    <span className="size-2 rounded-full bg-muted-foreground/30 sm:size-2.5" />
                    <span className="size-2 rounded-full bg-muted-foreground/30 sm:size-2.5" />
                    <span className="size-2 rounded-full bg-muted-foreground/30 sm:size-2.5" />
                  </div>
                  <div className="ms-1.5 flex min-w-0 items-center gap-1.5 pe-2 text-[11px] text-muted-foreground sm:ms-2 sm:pe-4 sm:text-[12px]">
                    <span className="font-medium text-foreground">
                      {homeCopy.heroPreviewWorkspace}
                    </span>
                    <ChevronRight className="size-3" />
                    <span className="truncate">
                      {homeCopy.heroPreviewOrganization}
                    </span>
                    <ChevronRight className="size-3" />
                    <span className="truncate">{homeCopy.heroPreviewProject}</span>
                  </div>
                </div>
                <div className="relative z-10 aspect-[148/65] w-full overflow-hidden">
                  <img
                    src={HOME_HERO_LIGHT_SRC}
                    alt={homeCopy.heroImageAlt}
                    width={1920}
                    height={1234}
                    fetchPriority="high"
                    decoding="async"
                    className="block h-full w-full rounded-t-md object-cover object-top opacity-95 dark:hidden sm:rounded-t-lg"
                  />
                  <img
                    src={HOME_HERO_DARK_SRC}
                    alt={homeCopy.heroImageAlt}
                    width={1920}
                    height={1234}
                    fetchPriority="high"
                    decoding="async"
                    className="hidden h-full w-full rounded-t-md object-cover object-top opacity-95 dark:block sm:rounded-t-lg"
                  />
                </div>
              </div>
            </div>
          </div>
        </section>

        <InitSection />

        <section className="border-b border-border bg-background py-14 sm:py-16">
          <div className="mx-auto w-full max-w-6xl px-4 text-center sm:px-6">
            <h2 className="font-aeonik-pro text-[16px] font-normal tracking-tight text-foreground sm:text-[18px]">
              {homeCopy.toolsHeading}
              <span className="text-[var(--brand-cta)]">_</span>
            </h2>

            <div className="mx-auto mt-8 flex max-w-5xl flex-wrap items-center justify-center gap-x-8 gap-y-7 sm:gap-x-10">
              {frameworkTools.map((tool) => (
                <MarketingSiteLink
                  key={tool.name}
                  href={tool.href}
                  aria-label={tool.name}
                  className="group flex size-9 items-center justify-center transition-transform duration-200 hover:scale-110"
                >
                  <img
                    src={tool.icon}
                    alt=""
                    className="size-8 object-contain opacity-45 transition-opacity duration-200 group-hover:opacity-100 dark:opacity-40 dark:group-hover:opacity-100"
                  />
                </MarketingSiteLink>
              ))}
            </div>
          </div>

          <nav
            className="mt-7 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-[12px] font-medium text-muted-foreground"
            aria-label={homeCopy.aiDocsNavLabel}
          >
            {aiDocLinks.map((link, index) => (
              <span key={link.href} className="flex items-center gap-2">
                {index > 0 ? (
                  <span className="text-muted-foreground/40" aria-hidden>
                    ·
                  </span>
                ) : null}
                {'external' in link && link.external ? (
                  <a
                    href={link.href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="link-neutral text-[12px]"
                  >
                    {link.label}
                  </a>
                ) : (
                  <MarketingSiteLink href={link.href} className="link-neutral text-[12px]">
                    {link.label}
                  </MarketingSiteLink>
                )}
              </span>
            ))}
          </nav>
        </section>

        {/* Top customer logos - hidden for now. Restore from git history when needed. */}

        <section className="bg-background py-16 sm:py-20">
          <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
            <div className="mx-auto max-w-3xl text-center">
              <h2 className="font-aeonik-pro text-[40px] font-normal leading-none tracking-tight text-foreground sm:text-[48px]">
                {homeCopy.productsHeadingLineOne}
                <br />
                {homeCopy.productsHeadingLineTwo}
                <span className="text-[var(--brand-cta)]">_</span>
              </h2>
              <p className="mx-auto mt-4 max-w-2xl text-[14px] leading-6 text-muted-foreground">
                {homeCopy.productsDescription}
              </p>
            </div>

            <MarketingProductPills
              build={marketingProductToolkit.build}
              deploy={marketingProductToolkit.deploy}
              protect={marketingProductToolkit.protect}
              scale={{ href: '#scale' }}
            />

            <div className="product-bento-grid mt-10 grid gap-3 sm:gap-4 lg:grid-cols-12 lg:grid-rows-[repeat(8,minmax(0,1fr))] lg:min-h-[960px]">
              {productBentoItems.map((item) => {
                const Icon = item.icon
                const href = item.href

                return (
                  <article
                    key={item.id}
                    className={`${item.className} group relative flex cursor-pointer flex-col overflow-hidden rounded-xl border border-border bg-card/50 hover:bg-accent/10 ${item.mobileVisualTall ? 'min-h-[540px]' : 'min-h-[380px]'} ${item.tall ? 'lg:min-h-0' : ''}`}
                  >
                    {href ? (
                      <ProductBentoCardLink href={href} title={item.title} />
                    ) : null}
                    <ProductBentoHoverLight tall={item.tall} />
                    <div
                      className={`pointer-events-none relative z-[2] flex min-h-0 flex-1 flex-col lg:h-full ${item.tall ? 'lg:min-h-full' : ''}`}
                    >
                      <div
                        className={`relative z-10 shrink-0 px-4 pt-4 ${item.compact ? 'pb-2' : 'pb-2.5'}`}
                      >
                        <div className="flex items-center gap-2">
                          <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
                            <Icon
                              className="size-3.5 text-[var(--brand-cta)]"
                              aria-hidden
                            />
                          </span>
                          <h3 className="font-aeonik-pro text-[16px] font-normal text-foreground">
                            {item.title}
                          </h3>
                          {item.label ? (
                            <span className="rounded-full bg-[var(--brand-cta)]/10 px-2 py-0.5 text-[10px] font-medium text-[var(--brand-cta)]">
                              {item.label}
                            </span>
                          ) : null}
                        </div>
                        <p
                          className={`mt-2 min-h-10 max-w-xl text-[13px] leading-5 text-muted-foreground ${item.compact ? 'line-clamp-2' : 'line-clamp-3'}`}
                        >
                          {item.description}
                        </p>
                      </div>

                      <div
                        className={`relative flex min-h-0 flex-1 flex-col px-3 pb-3 sm:px-3.5 sm:pb-3.5 ${item.mobileVisualTall ? 'max-lg:min-h-[460px]' : ''}`}
                      >
                        <div
                          className={`relative isolate min-h-0 flex-1 overflow-hidden rounded-lg border border-border bg-muted/20 contain-paint ${
                            item.mobileVisualTall
                              ? 'min-h-[280px] lg:min-h-[15rem]'
                              : item.compact
                                ? 'min-h-[240px] lg:min-h-[11rem]'
                                : item.tall
                                  ? 'min-h-[260px] lg:min-h-[14rem]'
                                  : 'min-h-[260px] lg:min-h-[12rem]'
                          }`}
                          aria-hidden
                        >
                          <ProductBentoSoftLights />
                          <div className="absolute inset-0 p-2 sm:p-2.5">
                            <ProductBentoVisualDeferred productId={item.id} />
                          </div>
                        </div>
                      </div>
                    </div>
                  </article>
                )
              })}
            </div>
          </div>
        </section>

        <AiSection />

        <TestimonialsSection />

        <section className="border-t border-border bg-background py-16 sm:py-20">
          <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
            <div className="mx-auto max-w-5xl text-center">
              <h2 className="font-aeonik-pro mx-auto max-w-5xl text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
                {homeCopy.securityHeading}
                <span className="text-[var(--brand-cta)]">_</span>
              </h2>
              <p className="mx-auto mt-5 max-w-2xl text-balance text-[14px] leading-6 text-muted-foreground sm:text-[15px] sm:leading-7">
                {homeCopy.securityDescription}
              </p>
            </div>

            <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/45 sm:grid-cols-2 lg:grid-cols-4">
              {securityItems.map((item) => {
                const Icon = item.icon

                return (
                  <article
                    key={item.id}
                    className="group border-b border-border p-5 transition-colors hover:bg-accent/15 sm:border-e sm:[&:nth-child(2n)]:border-e-0 sm:[&:nth-child(n+7)]:border-b-0 lg:[&:nth-child(2n)]:border-e lg:[&:nth-child(4n)]:border-e-0 lg:[&:nth-child(n+5)]:border-b-0"
                  >
                    <div className="flex flex-col gap-3">
                      <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40">
                        <Icon
                          className="size-3.5 text-[var(--brand-cta)]"
                          aria-hidden
                        />
                      </span>
                      <div>
                        <h3 className="text-[14px] font-semibold text-foreground">
                          {item.title}
                        </h3>
                        <p className="mt-2 text-[13px] leading-5 text-muted-foreground">
                          {item.description}
                        </p>
                      </div>
                    </div>
                  </article>
                )
              })}
            </div>
          </div>
        </section>

        <NetworkSection />

        <ScaleSection />

        <PricingSection />
    </>
  )
}
