import { createFileRoute, Link } from '@tanstack/react-router'
import { useRef, useState } from 'react'
import {
  ArrowRight,
  ChevronRight,
  Database,
  Globe2,
  HardDrive,
  MessageSquare,
  Radio,
  Shield,
  ShieldCheck,
  Zap,
} from 'lucide-react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { InitHeroBackground } from '@/components/pages/init/_components/InitHeroBackground'
import { Button } from '@/components/ui/button'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
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
  { src: '/images/logos/trusted-by/gm.svg', alt: 'GM', width: 41, height: 41, inverseMask: true },
  { src: '/images/logos/trusted-by/ey.svg', alt: 'EY', width: 39, height: 41 },
  { src: '/images/logos/trusted-by/k-collect.svg', alt: 'K-Collect', width: 110, height: 35, mask: true },
  { src: '/images/logos/trusted-by/bosch.svg', alt: 'BOSCH', width: 94, height: 31 },
  {
    src: '/images/logos/trusted-by/decathlon.svg',
    maskSrc: '/images/logos/trusted-by/decathlon-text-mask.svg',
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
    className: 'lg:col-span-5',
  },
  {
    title: 'Databases',
    description:
      'Model, query, and scale application data with fast tables and robust permissions.',
    icon: Database,
    className: 'lg:col-span-7',
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
    className: 'lg:col-span-4',
  },
  {
    title: 'Firewall',
    label: 'New',
    description:
      'Protect apps with traffic rules, abuse controls, and edge security for every project.',
    icon: Shield,
    className: 'lg:col-span-4',
  },
] as const

export const Route = createFileRoute('/home')({
  ssr: false,
  head: () => ({ meta: [{ title: pageTitle('Home') }] }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    void context.queryClient
      .prefetchQuery(consoleAccountQueryOptions())
      .catch(() => {})
  },
  component: HomePage,
})

function HomePage() {
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const heroRef = useRef<HTMLDivElement>(null)

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
        <section
          ref={heroRef}
          className="relative isolate overflow-hidden border-b border-border bg-muted-foreground/10 dark:bg-background"
        >
          <div className="absolute inset-0 opacity-25" aria-hidden>
            <InitHeroBackground containerRef={heroRef} />
          </div>

          <div
            className="pointer-events-none absolute inset-x-0 bottom-0 z-[1] h-[360px] overflow-hidden motion-reduce:hidden"
            aria-hidden
          >
            <div className="absolute left-1/2 top-28 h-52 w-[520px] -translate-x-1/2 rounded-full bg-[radial-gradient(circle,color-mix(in_srgb,var(--brand-cta)_38%,transparent)_0%,transparent_68%)] blur-3xl [animation:home-hero-light-drift_12s_ease-in-out_infinite]" />
            <div className="absolute left-[18%] top-40 h-44 w-[360px] rounded-full bg-[radial-gradient(circle,rgba(248,161,186,0.18)_0%,transparent_70%)] blur-3xl [animation:home-hero-light-drift-alt_16s_ease-in-out_infinite]" />
            <div className="absolute right-[12%] top-36 h-48 w-[420px] rounded-full bg-[radial-gradient(circle,rgba(255,255,255,0.1)_0%,transparent_68%)] blur-3xl [animation:home-hero-light-drift-slow_18s_ease-in-out_infinite]" />
          </div>

          <div className="relative z-10 mx-auto flex min-h-[640px] w-full max-w-7xl flex-col items-center px-4 pb-0 pt-14 text-center sm:px-6 sm:pt-20">
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

            <h1 className="font-aeonik-pro mt-6 max-w-6xl bg-[linear-gradient(145deg,#c7255f_0%,#e43f78_30%,var(--foreground)_68%)] bg-clip-text pb-3 text-balance text-[48px] font-normal leading-[1.04] tracking-[-0.022em] text-transparent dark:bg-[linear-gradient(145deg,#f8a1ba_0%,#ff7fa5_28%,#fff_62%)] lg:text-[76px]">
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

            <div className="mt-8 max-h-[380px] w-full max-w-[80rem] overflow-hidden rounded-t-[28px] border-x-2 border-t-2 border-b-0 border-muted-foreground/8 bg-muted-foreground/[0.035] px-4 pb-0 pt-1 backdrop-blur-md dark:border-muted/30 dark:bg-muted/10 sm:mt-10 sm:max-h-[480px]">
              <div className="flex h-10 items-center gap-2 text-left">
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
                className="block w-full rounded-t-lg opacity-95 dark:hidden"
              />
              <img
                src="/images/heroes/console-app-dark.png"
                alt="Appwrite console overview with usage charts, apps, and API keys"
                className="hidden w-full rounded-t-lg opacity-95 dark:block"
              />
            </div>
          </div>
          <style>
            {`
              @keyframes home-hero-light-drift {
                0%, 100% { transform: translate3d(-50%, 16px, 0) scale(1); opacity: 0.72; }
                50% { transform: translate3d(calc(-50% + 80px), -24px, 0) scale(1.08); opacity: 1; }
              }

              @keyframes home-hero-light-drift-alt {
                0%, 100% { transform: translate3d(-40px, 18px, 0) scale(0.94); opacity: 0.5; }
                50% { transform: translate3d(120px, -16px, 0) scale(1.08); opacity: 0.82; }
              }

              @keyframes home-hero-light-drift-slow {
                0%, 100% { transform: translate3d(60px, 12px, 0) scale(1); opacity: 0.42; }
                50% { transform: translate3d(-120px, -20px, 0) scale(1.12); opacity: 0.75; }
              }
            `}
          </style>
        </section>

        <section className="border-b border-border bg-muted-foreground/10 py-14 dark:bg-background sm:py-16">
          <div className="mx-auto w-full max-w-6xl px-4 text-center sm:px-6">
            <h2 className="font-aeonik-pro text-[16px] font-normal tracking-tight text-foreground sm:text-[18px]">
              Optimized for the frameworks, languages and agents you love
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
                    className="size-8 object-contain opacity-90 [filter:grayscale(1)_brightness(0.38)] transition duration-200 group-hover:opacity-100 group-hover:[filter:none] dark:opacity-55 dark:[filter:grayscale(1)]"
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

        <section className="border-b border-border bg-muted-foreground/10 py-16 dark:bg-background sm:py-20">
          <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
            <h2 className="font-aeonik-pro text-center text-[15px] font-normal text-foreground sm:text-[16px]">
              Trusted by developer teams worldwide
            </h2>

            <div className="grid grid-cols-2 gap-x-8 gap-y-9 py-10 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 lg:gap-x-12">
              {customerLogos.map((logo) => {
                const maskSrc =
                  'mask' in logo && 'maskSrc' in logo ? logo.maskSrc : logo.src
                const inverseMaskSrc =
                  'inverseMask' in logo && 'maskSrc' in logo
                    ? logo.maskSrc
                    : logo.src

                return (
                  <div key={logo.src} className="group flex min-h-10 items-center justify-center">
                    {'inverseMask' in logo ? (
                      <span
                        role="img"
                        aria-label={logo.alt}
                        className="relative block bg-foreground/75 transition duration-200 group-hover:scale-105 group-hover:bg-foreground dark:bg-muted-foreground dark:group-hover:bg-foreground"
                        style={{
                          width: logo.width,
                          height: logo.height,
                        }}
                      >
                        <span
                          className="absolute inset-0 bg-muted-foreground/10 dark:bg-background"
                          style={{
                            maskImage: `url(${inverseMaskSrc})`,
                            maskPosition: 'center',
                            maskRepeat: 'no-repeat',
                            maskSize: 'contain',
                            WebkitMaskImage: `url(${inverseMaskSrc})`,
                            WebkitMaskPosition: 'center',
                            WebkitMaskRepeat: 'no-repeat',
                            WebkitMaskSize: 'contain',
                          }}
                        />
                      </span>
                    ) : 'mask' in logo ? (
                      <span
                        role="img"
                        aria-label={logo.alt}
                        className="block bg-foreground/75 transition duration-200 group-hover:scale-105 group-hover:bg-foreground dark:bg-muted-foreground dark:group-hover:bg-foreground"
                        style={{
                          width: logo.width,
                          height: logo.height,
                          maskImage: `url(${maskSrc})`,
                          maskPosition: 'center',
                          maskRepeat: 'no-repeat',
                          maskSize: 'contain',
                          WebkitMaskImage: `url(${maskSrc})`,
                          WebkitMaskPosition: 'center',
                          WebkitMaskRepeat: 'no-repeat',
                          WebkitMaskSize: 'contain',
                        }}
                      />
                    ) : (
                    <img
                      src={logo.src}
                      alt={logo.alt}
                      width={logo.width}
                      height={logo.height}
                      loading="lazy"
                      className="max-h-10 max-w-[120px] opacity-90 [filter:brightness(0.42)] transition duration-200 group-hover:scale-105 group-hover:opacity-100 group-hover:[filter:none] dark:opacity-80 dark:[filter:none]"
                    />
                    )}
                  </div>
                )
              })}
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

        <section className="bg-muted-foreground/10 py-16 dark:bg-background sm:py-20">
          <div className="mx-auto w-full max-w-7xl px-4 sm:px-6">
            <div className="mx-auto max-w-3xl text-center">
              <h2 className="font-aeonik-pro text-[40px] font-normal leading-none tracking-tight text-foreground sm:text-[48px]">
                All the services you need
                <br />
                in one platform
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
                    className={`${item.className} group border-b border-border transition-colors hover:bg-accent/15 lg:border-r [&:nth-last-child(-n+1)]:border-b-0 lg:[&:nth-child(2)]:border-r-0 lg:[&:nth-child(5)]:border-r-0 lg:[&:nth-child(8)]:border-r-0 lg:[&:nth-child(n+6)]:border-b-0`}
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
      </ConsoleLayout>

      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={setCommandCenterOpen}
        context="account"
      />
    </>
  )
}
