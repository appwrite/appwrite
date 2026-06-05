import { createFileRoute, Link } from '@tanstack/react-router'
import { useState } from 'react'
import {
  ArrowRight,
  BadgeCheck,
  ChevronRight,
  Database,
  DatabaseBackup,
  Globe2,
  HardDrive,
  HeartPulse,
  LockKeyhole,
  MessageSquare,
  Pentagon,
  Radio,
  Scale,
  Shield,
  ShieldCheck,
  Sparkles,
  Zap,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { TrustedByLogo } from '@/components/global/shared/TrustedByLogo'
import { NetworkSection } from '@/components/pages/home/NetworkSection'
import { PricingSection } from '@/components/pages/home/PricingSection'
import { ScaleSection } from '@/components/pages/home/ScaleSection'
import { TestimonialsSection } from '@/components/pages/home/TestimonialsSection'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { Button } from '@/components/ui/button'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { localeQueryOptions } from '@/lib/react-query/hooks/locale'
import { pageTitle } from '@/lib/utils/page-title'

const frameworkTools = [
  { name: 'React', icon: '/icons/react.svg', href: 'https://appwrite.io/docs/quick-starts/react' },
  { name: 'Next.js', icon: '/icons/nextjs.svg', href: 'https://appwrite.io/docs/quick-starts/nextjs' },
  { name: 'Vue', icon: '/icons/vue.svg', href: 'https://appwrite.io/docs/quick-starts/vue' },
  { name: 'SvelteKit', icon: '/icons/svelte.svg', href: 'https://appwrite.io/docs/quick-starts/sveltekit' },
  { name: 'Astro', icon: '/icons/astro.svg', href: 'https://appwrite.io/docs/quick-starts/astro' },
  { name: 'Android', icon: '/icons/android.svg', href: 'https://appwrite.io/docs/quick-starts/android' },
  { name: 'iOS', icon: '/icons/apple.svg', href: 'https://appwrite.io/docs/quick-starts/apple' },
  { name: 'Flutter', icon: '/icons/flutter.svg', href: 'https://appwrite.io/docs/quick-starts/flutter' },
  { name: 'Claude', icon: '/icons/claude.svg', href: 'https://appwrite.io/docs/tooling/mcp/claude-code' },
  { name: 'ChatGPT', icon: '/icons/chatgpt.svg', href: 'https://appwrite.io/docs/tooling/ai/agents/codex' },
  { name: 'Cursor', icon: '/icons/cursor-ai.svg', href: 'https://appwrite.io/docs/tooling/mcp/cursor' },
  { name: 'Lovable', icon: '/icons/lovable.svg', href: 'https://appwrite.io/docs/tooling/ai/vibe-coding/lovable' },
  { name: 'OpenCode', icon: '/icons/opencode.svg', href: 'https://appwrite.io/docs/tooling/mcp/opencode' },
  { name: 'Bun', icon: '/icons/bun.svg', href: 'https://appwrite.io/docs/products/functions/runtimes' },
] as const

const aiDocLinks = [
  { label: 'MCP servers', href: 'https://appwrite.io/docs/tooling/ai/mcp-servers' },
  { label: 'Appwrite Skills', href: 'https://appwrite.io/docs/tooling/ai/skills' },
  { label: 'AI Arena', href: 'https://arena.appwrite.io/' },
] as const

const customerLogos = [
  { src: '/images/logos/trusted-by/times-of-india.svg', alt: 'The Times of India', width: 123, height: 45 },
  { src: '/images/logos/trusted-by/devkind.svg', alt: 'DevKind', width: 91, height: 27 },
  { src: '/images/logos/trusted-by/first-media.svg', alt: 'First Media', width: 139, height: 37 },
  { src: '/images/logos/trusted-by/acer.svg', alt: 'Acer', width: 90, height: 22 },
  { src: '/images/logos/trusted-by/ibm.svg', alt: 'IBM', width: 63, height: 26 },
  { src: '/images/logos/trusted-by/american-airlines.svg', alt: 'American Airlines', width: 125, height: 20 },
  { src: '/images/logos/trusted-by/langx.svg', alt: 'LangX', width: 114, height: 25 },
  {
    src: '/images/logos/trusted-by/gm.svg',
    maskSrc: '/images/logos/trusted-by/gm-inverse-mask.svg',
    alt: 'GM',
    width: 41,
    height: 41,
    inverseMask: true,
  },
  { src: '/images/logos/trusted-by/ey.svg', alt: 'EY', width: 39, height: 41 },
  { src: '/images/logos/trusted-by/k-collect.svg', alt: 'K-Collect', width: 110, height: 35, mask: true },
  { src: '/images/logos/trusted-by/bosch.svg', alt: 'BOSCH', width: 94, height: 31 },
  {
    src: '/images/logos/trusted-by/decathlon.svg',
    maskSrc: '/images/logos/trusted-by/decathlon-inverse-mask.svg',
    alt: 'DECATHLON',
    width: 108,
    height: 27,
    inverseMask: true,
  },
] as const

const productBentoItems = [
  {
    title: 'Auth',
    description:
      'Authenticate users securely with email, SMS, OAuth, anonymous sessions, and magic URLs.',
    icon: ShieldCheck,
    className: 'lg:col-span-4',
  },
  {
    title: 'Databases',
    description:
      'Model, query, and scale application data with fast tables and robust permissions.',
    icon: Database,
    className: 'lg:col-span-8',
  },
  {
    title: 'Storage',
    description:
      'Store files with compression, encryption, image transformations, and access control.',
    icon: HardDrive,
    className: 'lg:col-span-4',
  },
  {
    title: 'Functions',
    description:
      'Deploy serverless functions with secure isolated runtimes and event-driven execution.',
    icon: Zap,
    className: 'lg:col-span-4',
  },
  {
    title: 'Messaging',
    description:
      'Send email, SMS, and push messages through a unified messaging service.',
    icon: MessageSquare,
    className: 'lg:col-span-4',
  },
  {
    title: 'Realtime',
    description:
      'Subscribe and react to events across your project as they happen.',
    icon: Radio,
    className: 'lg:col-span-4',
  },
  {
    title: 'Sites',
    description:
      'Deploy static, SSR, and CSR frontends with Appwrite behind them.',
    icon: Globe2,
    className: 'lg:col-span-8',
  },
  {
    title: 'Firewall',
    label: 'New',
    description:
      'Protect apps with traffic rules, abuse controls, and edge security for every project.',
    icon: Shield,
    className: 'lg:col-span-6',
  },
  {
    title: 'Advisor',
    description:
      'Get actionable recommendations to improve security, performance, and project configuration.',
    icon: Sparkles,
    className: 'lg:col-span-6',
  },
] as const

const securityItems: {
  title: string
  description: string
  icon: LucideIcon
}[] = [
  {
    title: 'DDoS protection',
    description:
      'Automatically detect and mitigate distributed denial-of-service attacks.',
    icon: ShieldCheck,
  },
  {
    title: 'Encryption',
    description:
      'Built-in data encryption for sensitive workloads in rest and in transit.',
    icon: LockKeyhole,
  },
  {
    title: 'Abuse protection',
    description:
      'Protect your APIs from abuse with built-in platform safeguards.',
    icon: BadgeCheck,
  },
  {
    title: 'Data migrations',
    description:
      'Move data from third parties or between Cloud and self-hosted environments.',
    icon: DatabaseBackup,
  },
  {
    title: 'GDPR',
    description:
      'Support data privacy workflows and safeguards for GDPR requirements.',
    icon: Globe2,
  },
  {
    title: 'SOC 2',
    description:
      'Operate on infrastructure designed for high security and privacy standards.',
    icon: Pentagon,
  },
  {
    title: 'HIPAA',
    description:
      'Protect sensitive health data with security-first product controls.',
    icon: HeartPulse,
  },
  {
    title: 'CCPA',
    description:
      'Build with controls that help protect sensitive user data.',
    icon: Scale,
  },
]

export const Route = createFileRoute('/home')({
  ssr: false,
  head: () => ({ meta: [{ title: pageTitle('Home') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    void Promise.all([
      context.queryClient.prefetchQuery(consoleAccountQueryOptions()),
      context.queryClient.prefetchQuery(localeQueryOptions()),
    ]).catch(() => {})
  },
  component: HomePage,
})

function HomePage() {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)

  useKeyboardShortcut('meta+k', () => setCommandCenterOpen(true))
  useKeyboardShortcut('control+k', () => setCommandCenterOpen(true))

  return (
    <>
      <ConsoleLayout
        header={{
          onCommandCenterOpen: () => setCommandCenterOpen(true),
          marketingNav: true,
        }}
        showFooter
        footer={{ expanded: true }}
      >
        <section className="relative isolate overflow-x-clip border-b border-border bg-background">
          <div
            className="pointer-events-none absolute inset-0 overflow-hidden motion-reduce:hidden"
            aria-hidden
          >
            <div className="absolute -left-[42%] bottom-16 h-[220px] w-[440px] rounded-[999px] bg-[radial-gradient(ellipse,rgba(253,54,110,0.34)_0%,rgba(253,54,110,0.12)_44%,transparent_76%)] blur-3xl [animation:home-hero-soft-light-left-light_11s_ease-in-out_infinite] dark:bg-[radial-gradient(ellipse,rgba(253,54,110,0.15)_0%,rgba(253,54,110,0.055)_44%,transparent_76%)] dark:[animation:home-hero-soft-light-left-dark_11s_ease-in-out_infinite] sm:-left-[34%] sm:bottom-8 sm:h-[360px] sm:w-[760px] lg:-left-[32%] lg:bottom-6 lg:h-[460px] lg:w-[980px]" />
            <div className="absolute -right-[44%] bottom-12 h-[240px] w-[460px] rounded-[999px] bg-[radial-gradient(ellipse,rgba(124,103,254,0.3)_0%,rgba(124,103,254,0.1)_46%,transparent_78%)] blur-3xl [animation:home-hero-soft-light-right-light_13s_ease-in-out_infinite] dark:bg-[radial-gradient(ellipse,rgba(99,102,241,0.14)_0%,rgba(99,102,241,0.05)_46%,transparent_78%)] dark:[animation:home-hero-soft-light-right-dark_13s_ease-in-out_infinite] sm:-right-[36%] sm:bottom-4 sm:h-[380px] sm:w-[800px] lg:-right-[34%] lg:bottom-0 lg:h-[500px] lg:w-[1040px]" />
          </div>
          <div
            className="absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px]"
            aria-hidden
          />
          <div className="relative z-10 mx-auto flex w-full max-w-7xl flex-col items-center px-4 pb-0 pt-14 text-center sm:px-6 sm:pt-20">
            <Button
              variant="outline"
              size="sm"
              className="h-7 rounded-full px-3 text-[12px]"
              asChild
            >
              <a
                href="https://appwrite.io/docs/products/realtime/presence"
                target="_blank"
                rel="noopener noreferrer"
              >
                <Radio className="size-3.5" />
                <span className="text-[var(--brand-cta)]">New</span>
                Announcing the Presences API
                <ArrowRight className="size-3.5" />
              </a>
            </Button>

            <h1 className="font-aeonik-pro mt-6 max-w-6xl bg-[linear-gradient(145deg,#e8a8b6_0%,#c97d92_18%,var(--foreground)_46%)] bg-clip-text pb-3 text-balance text-[48px] font-normal leading-[1.04] tracking-[-0.022em] text-transparent dark:bg-[linear-gradient(145deg,#f8a1ba_0%,#ff7fa5_28%,#fff_62%)] lg:text-[76px]">
              Build faster and scale bigger than ever
              <span className="text-[var(--brand-cta)]">_</span>
            </h1>

            <p className="mx-auto mt-5 max-w-2xl text-balance text-[15px] leading-6 text-muted-foreground sm:text-[16px] sm:leading-7">
              Appwrite is an open-source platform for building and scaling applications
              faster, offering Auth, Databases, Storage, Functions, Messaging, Realtime,
              and web hosting. All in one place.
            </p>

            <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
              <Button variant="brandCta" size="lg" className="h-10 text-[14px]" asChild>
                <Link to="/sign-up" search={{ redirect: '/' }}>
                  Start project
                </Link>
              </Button>
              <Button variant="outline" size="lg" className="h-10 text-[14px]" asChild>
                <a
                  href="https://appwrite.io/contact"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Request a demo
                </a>
              </Button>
            </div>
          </div>

          <div className="relative z-10 mt-8 w-full sm:mt-10">
            <div className="mx-auto w-full max-w-[min(100vw-2rem,76rem)] px-4 sm:max-w-[min(100vw-3rem,80rem)] sm:px-6 lg:max-w-[min(100vw-4rem,84rem)]">
              <div className="relative isolate max-h-[400px] w-full overflow-hidden rounded-t-[28px] border-x-2 border-t-2 border-b-0 border-muted-foreground/8 bg-muted-foreground/[0.035] px-4 pb-0 pt-1 backdrop-blur-md dark:border-muted/30 dark:bg-muted/10 sm:max-h-[500px] lg:max-h-[560px]">
                <div className="relative z-10 flex h-10 items-center gap-2 text-left">
                  <div className="ml-2 flex items-center gap-1.5" aria-hidden>
                    <span className="size-2.5 rounded-full bg-muted-foreground/30" />
                    <span className="size-2.5 rounded-full bg-muted-foreground/30" />
                    <span className="size-2.5 rounded-full bg-muted-foreground/30" />
                  </div>
                  <div className="ml-2 flex min-w-0 items-center gap-1.5 pr-4 text-[12px] text-muted-foreground">
                    <span className="font-medium text-foreground">appwrite</span>
                    <ChevronRight className="size-3" />
                    <span className="truncate">Acme Corp</span>
                    <ChevronRight className="size-3" />
                    <span className="truncate">First Appwrite project</span>
                  </div>
                </div>
                <img
                  src="/images/heroes/console-app-light.png"
                  alt="Appwrite console overview with usage charts, apps, and API keys"
                  className="relative z-10 block w-full rounded-t-lg opacity-95 dark:hidden"
                />
                <img
                  src="/images/heroes/console-app-dark.png"
                  alt="Appwrite console overview with usage charts, apps, and API keys"
                  className="relative z-10 hidden w-full rounded-t-lg opacity-95 dark:block"
                />
              </div>
            </div>
          </div>
          <style>
            {`
              @keyframes home-hero-soft-light-left-light {
                0%, 100% { transform: translate3d(0, 0, 0) rotate(-3deg) scale(1); opacity: 0.88; }
                50% { transform: translate3d(18%, -7%, 0) rotate(4deg) scale(1.1); opacity: 1; }
              }

              @keyframes home-hero-soft-light-right-light {
                0%, 100% { transform: translate3d(0, 0, 0) rotate(3deg) scale(1); opacity: 0.85; }
                50% { transform: translate3d(-18%, -8%, 0) rotate(-4deg) scale(1.09); opacity: 1; }
              }

              @keyframes home-hero-soft-light-left-dark {
                0%, 100% { transform: translate3d(0, 0, 0) rotate(-3deg) scale(1); opacity: 0.72; }
                50% { transform: translate3d(18%, -7%, 0) rotate(4deg) scale(1.1); opacity: 1; }
              }

              @keyframes home-hero-soft-light-right-dark {
                0%, 100% { transform: translate3d(0, 0, 0) rotate(3deg) scale(1); opacity: 0.68; }
                50% { transform: translate3d(-18%, -8%, 0) rotate(-4deg) scale(1.09); opacity: 0.98; }
              }
            `}
          </style>
        </section>

        <section className="border-b border-border bg-background py-14 sm:py-16">
          <div className="mx-auto w-full max-w-6xl px-4 text-center sm:px-6">
            <h2 className="font-aeonik-pro text-[16px] font-normal tracking-tight text-foreground sm:text-[18px]">
              Optimized for the frameworks, languages and agents you love
              <span className="text-[var(--brand-cta)]">_</span>
            </h2>

            <div className="mx-auto mt-8 flex max-w-5xl flex-wrap items-center justify-center gap-x-8 gap-y-7 sm:gap-x-10">
              {frameworkTools.map((tool) => (
                <a
                  key={tool.name}
                  href={tool.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label={tool.name}
                  className="group flex size-9 items-center justify-center transition-transform duration-200 hover:scale-110"
                >
                  <img
                    src={tool.icon}
                    alt=""
                    className="size-8 object-contain opacity-90 [filter:grayscale(1)_brightness(0.38)] transition duration-200 group-hover:opacity-100 group-hover:[filter:grayscale(1)_brightness(0)] dark:opacity-55 dark:[filter:grayscale(1)] dark:group-hover:opacity-100 dark:group-hover:[filter:grayscale(1)]"
                  />
                </a>
              ))}
            </div>
          </div>

          <nav
            className="mt-7 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-[12px] font-medium text-muted-foreground"
            aria-label="AI and MCP documentation"
          >
            {aiDocLinks.map((link, index) => (
              <span key={link.href} className="flex items-center gap-2">
                {index > 0 ? (
                  <span className="text-muted-foreground/40" aria-hidden>
                    ·
                  </span>
                ) : null}
                <a
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="transition-colors hover:text-foreground hover:underline"
                >
                  {link.label}
                </a>
              </span>
            ))}
          </nav>
        </section>

        <section className="border-b border-border bg-background py-16 sm:py-20">
          <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
            <h2 className="font-aeonik-pro text-center text-[15px] font-normal text-foreground sm:text-[16px]">
              Trusted by developer teams worldwide
              <span className="text-[var(--brand-cta)]">_</span>
            </h2>

            <div className="grid grid-cols-2 gap-x-8 gap-y-9 py-10 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 lg:gap-x-12">
              {customerLogos.map((logo) => (
                <div
                  key={logo.src}
                  className="group flex min-h-10 items-center justify-center"
                >
                  <TrustedByLogo
                    src={logo.src}
                    alt={logo.alt}
                    width={logo.width}
                    height={logo.height}
                    mask={'mask' in logo}
                    maskSrc={'maskSrc' in logo ? logo.maskSrc : undefined}
                    inverseMask={'inverseMask' in logo}
                    className="max-w-[120px]"
                  />
                </div>
              ))}
            </div>

            <div className="text-center">
              <a
                href="https://appwrite.io/blog/category/customer-stories"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-[13px] font-medium text-foreground transition-colors hover:text-[var(--brand-cta)]"
              >
                Read our case studies
                <ArrowRight className="size-3.5" />
              </a>
            </div>
          </div>
        </section>

        <section className="bg-background py-16 sm:py-20">
          <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
            <div className="mx-auto max-w-3xl text-center">
              <h2 className="font-aeonik-pro text-[40px] font-normal leading-none tracking-tight text-foreground sm:text-[48px]">
                All the services you need
                <br />
                in one platform
                <span className="text-[var(--brand-cta)]">_</span>
              </h2>
              <p className="mx-auto mt-4 max-w-2xl text-[14px] leading-6 text-muted-foreground">
                Build with modular products that feel unified from the first prototype
                to production scale.
              </p>
            </div>

            <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/50 lg:grid-cols-12">
              {productBentoItems.map((item) => {
                const Icon = item.icon

                return (
                  <article
                    key={item.title}
                    className={`${item.className} group border-b border-border transition-colors hover:bg-accent/15 lg:border-r [&:nth-last-child(-n+1)]:border-b-0 lg:[&:nth-child(2)]:border-r-0 lg:[&:nth-child(5)]:border-r-0 lg:[&:nth-child(7)]:border-r-0 lg:[&:nth-child(9)]:border-r-0 lg:[&:nth-child(n+8)]:border-b-0`}
                  >
                    <div className="flex min-h-[320px] flex-col">
                      <div className="space-y-3 px-5 pt-5">
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
                          {'label' in item ? (
                            <span className="rounded-full border border-[var(--brand-cta)]/20 bg-[var(--brand-cta)]/10 px-2 py-0.5 text-[10px] font-medium text-[var(--brand-cta)]">
                              {item.label}
                            </span>
                          ) : null}
                        </div>
                        <p className="min-h-10 max-w-xl text-[13px] leading-5 text-muted-foreground">
                          {item.description}
                        </p>
                      </div>

                      <div className="p-5 pt-4">
                        <div
                          className="relative min-h-[240px] overflow-hidden rounded-lg border border-border bg-muted/20"
                          aria-hidden
                        >
                          <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_30%,color-mix(in_srgb,var(--brand-cta)_10%,transparent),transparent_62%)] opacity-80" />
                          <div className="absolute inset-x-8 bottom-6 h-px bg-border/70" />
                        </div>
                      </div>
                    </div>
                  </article>
                )
              })}
            </div>
          </div>
        </section>

        <section className="border-t border-border bg-background py-16 sm:py-20">
          <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
            <div className="grid gap-8 lg:grid-cols-[1fr_0.9fr] lg:items-start">
              <h2 className="font-aeonik-pro max-w-xl text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
                Safely scale with built-in security and compliance
                <span className="text-[var(--brand-cta)]">_</span>
              </h2>
              <p className="max-w-xl text-[14px] leading-6 text-muted-foreground sm:text-[15px]">
                With a security-first approach, Appwrite helps keep products and
                users safe by default, making it easier to adhere to strict
                safety policies.
              </p>
            </div>

            <div className="mt-10 grid overflow-hidden rounded-xl border border-border bg-card/45 sm:grid-cols-2 lg:grid-cols-4">
              {securityItems.map((item) => {
                const Icon = item.icon

                return (
                  <article
                    key={item.title}
                    className="group border-b border-border p-5 transition-colors hover:bg-accent/15 sm:border-r sm:[&:nth-child(2n)]:border-r-0 sm:[&:nth-child(n+7)]:border-b-0 lg:[&:nth-child(2n)]:border-r lg:[&:nth-child(4n)]:border-r-0 lg:[&:nth-child(n+5)]:border-b-0"
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

        <TestimonialsSection />

        <NetworkSection />

        <ScaleSection />

        <PricingSection />
      </ConsoleLayout>

      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={setCommandCenterOpen}
        context="account"
      />
    </>
  )
}
