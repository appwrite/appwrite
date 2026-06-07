import { ShieldCheck } from 'lucide-react'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { useConsoleProfile } from '@/hooks/use-console-profile'

type FooterLink = {
  label: string
  href: string
}

type ExpandedFooterGroup = {
  title: string
  links: readonly FooterLink[]
}

type ConsoleFooterProps = {
  expanded?: boolean
}

const expandedFooterGroups: readonly ExpandedFooterGroup[] = [
  {
    title: 'Quick starts',
    links: [
      { label: 'Web', href: 'https://appwrite.io/docs/quick-starts/web' },
      { label: 'Next.js', href: 'https://appwrite.io/docs/quick-starts/nextjs' },
      { label: 'React', href: 'https://appwrite.io/docs/quick-starts/react' },
      { label: 'Vue.js', href: 'https://appwrite.io/docs/quick-starts/vue' },
      { label: 'Nuxt', href: 'https://appwrite.io/docs/quick-starts/nuxt' },
      { label: 'SvelteKit', href: 'https://appwrite.io/docs/quick-starts/sveltekit' },
      { label: 'Refine', href: 'https://appwrite.io/docs/quick-starts/refine' },
      { label: 'Angular', href: 'https://appwrite.io/docs/quick-starts/angular' },
      { label: 'React Native', href: 'https://appwrite.io/docs/quick-starts/react-native' },
      { label: 'Flutter', href: 'https://appwrite.io/docs/quick-starts/flutter' },
      { label: 'Apple', href: 'https://appwrite.io/docs/quick-starts/apple' },
      { label: 'Android', href: 'https://appwrite.io/docs/quick-starts/android' },
      { label: 'Qwik', href: 'https://appwrite.io/docs/quick-starts/qwik' },
      { label: 'Astro', href: 'https://appwrite.io/docs/quick-starts/astro' },
      { label: 'Solid', href: 'https://appwrite.io/docs/quick-starts/solid' },
    ],
  },
  {
    title: 'Products',
    links: [
      { label: 'Auth', href: 'https://appwrite.io/products/auth' },
      { label: 'Databases', href: 'https://appwrite.io/products/databases' },
      { label: 'Storage', href: 'https://appwrite.io/products/storage' },
      { label: 'Functions', href: 'https://appwrite.io/products/functions' },
      { label: 'Messaging', href: 'https://appwrite.io/products/messaging' },
      { label: 'Realtime', href: 'https://appwrite.io/products/realtime' },
      { label: 'Hosting', href: 'https://appwrite.io/products/sites' },
      { label: 'Network', href: 'https://appwrite.io/products/network' },
    ],
  },
  {
    title: 'Learn',
    links: [
      { label: 'Blog', href: 'https://appwrite.io/blog' },
      { label: 'Docs', href: 'https://appwrite.io/docs' },
      { label: 'Integrations', href: 'https://appwrite.io/integrations' },
      { label: 'Community', href: 'https://appwrite.io/discord' },
      { label: 'Init', href: 'https://appwrite.io/init' },
      { label: 'Threads', href: 'https://threads.appwrite.io/' },
      { label: 'Changelog', href: 'https://appwrite.io/changelog' },
      { label: 'Roadmap', href: 'https://github.com/appwrite/appwrite/projects' },
      { label: 'Source code', href: 'https://github.com/appwrite/appwrite' },
      { label: 'Arena', href: 'https://arena.appwrite.io/' },
      { label: 'Tech news', href: 'https://appwrite.io/blog/category/tech-news' },
    ],
  },
  {
    title: 'Programs',
    links: [
      { label: 'Startups', href: 'https://appwrite.io/startups' },
      { label: 'Education', href: 'https://appwrite.io/education' },
      { label: 'Partners', href: 'https://appwrite.io/partners' },
    ],
  },
  {
    title: 'About',
    links: [
      { label: 'Company', href: 'https://appwrite.io/company' },
      { label: 'Pricing', href: '/pricing' },
      { label: 'Careers', href: 'https://appwrite.io/careers' },
      { label: 'Store', href: 'https://store.appwrite.io/' },
      { label: 'Contact us', href: 'https://appwrite.io/contact-us' },
      { label: 'Assets', href: 'https://appwrite.io/assets' },
      { label: 'Security', href: 'https://appwrite.io/security' },
    ],
  },
  {
    title: 'Compare',
    links: [
      { label: 'Appwrite vs. Supabase', href: 'https://appwrite.io/compare/supabase' },
      { label: 'Appwrite vs. Firebase', href: 'https://appwrite.io/compare/firebase' },
      { label: 'Appwrite vs. Neon', href: 'https://appwrite.io/compare/neon' },
      { label: 'Appwrite vs. Vercel', href: 'https://appwrite.io/compare/vercel' },
      { label: 'Appwrite vs. Netlify', href: 'https://appwrite.io/compare/netlify' },
      { label: 'Appwrite vs. Cloudinary', href: 'https://appwrite.io/compare/cloudinary' },
      { label: 'Appwrite vs. Auth0', href: 'https://appwrite.io/compare/auth0' },
      { label: 'Backend as a service (BaaS)', href: 'https://appwrite.io/compare/backend-as-a-service' },
    ],
  },
] as const

function FooterGroupLinks({ links }: { links: readonly FooterLink[] }) {
  return (
    <ul className="space-y-2.5">
      {links.map((link) => (
        <li key={link.label}>
          <a
            href={link.href}
            target="_blank"
            rel="noopener noreferrer"
            className="text-[13px] leading-5 text-muted-foreground transition-colors hover:text-foreground"
          >
            {link.label}
          </a>
        </li>
      ))}
    </ul>
  )
}

/**
 * ConsoleFooter Component
 *
 * A professional footer component for the console layout displaying:
 * - Dynamic copyright year with Appwrite branding
 * - Navigation links (Docs, Store, Status, Legal) and social icons
 * - Trust/compliance badges (SOC 2)
 * - Social media icons and daily.dev Squad link
 *
 * Props: Optional expanded link directory
 * State: None (stateless component)
 *
 * Usage:
 * <ConsoleFooter />
 */
export function ConsoleFooter({ expanded = false }: ConsoleFooterProps) {
  const currentYear = new Date().getFullYear()
  const { isCloud, features } = useConsoleProfile()
  const cloudStatusEnabled = isCloud && features.systemStatus

  const resourceLinks = [
    { label: 'Docs', href: 'https://appwrite.io/docs' },
    { label: 'Store', href: 'https://store.appwrite.io/' },
    ...(cloudStatusEnabled
      ? [{ label: 'Status' as const, href: 'https://status.appwrite.online' }]
      : []),
  ]

  const legalLinks = [
    { label: 'Terms', href: 'https://appwrite.io/terms' },
    { label: 'Privacy', href: 'https://appwrite.io/privacy' },
  ]

  const socialLinks = [
    {
      label: 'GitHub',
      href: 'https://github.com/appwrite',
      icon: '/icons/github.svg',
    },
    { label: 'X', href: 'https://x.com/appwrite', icon: '/icons/x.svg' },
    {
      label: 'YouTube',
      href: 'https://youtube.com/@appwrite',
      icon: '/icons/youtube.svg',
    },
    {
      label: 'Discord',
      href: 'https://appwrite.io/discord',
      icon: '/icons/discord-simple.svg',
    },
    {
      label: 'daily.dev Squad',
      href: 'https://apwr.dev/dailydev',
      icon: '/icons/daily-dev.svg',
    },
  ]

  const getSocialIconMaskStyle = (iconPath: string) => ({
    maskImage: `url(${iconPath})`,
    maskRepeat: 'no-repeat',
    maskPosition: 'center',
    maskSize: 'contain',
    WebkitMaskImage: `url(${iconPath})`,
    WebkitMaskRepeat: 'no-repeat',
    WebkitMaskPosition: 'center',
    WebkitMaskSize: 'contain',
  })

  const compactFooter = (
    <div className="flex min-h-[54px] items-center">
      <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-2 overflow-visible px-4 sm:px-6">
        {/* Left section: Logo, Resource Links, and Social Icons */}
        <div className="flex min-w-0 flex-shrink items-center gap-2">
          {/* Logo - inline SVG with currentColor so theme (black/white) works on Safari/iOS */}
          <div className="flex flex-shrink-0 items-center py-1.5 pe-2.5 ps-0 text-foreground opacity-60">
            <AppwriteMark className="h-4 w-4" />
          </div>

          {/* Separator */}
          <div className="hidden h-4 w-px flex-shrink-0 bg-border @[520px]:block" />

          {/* Resource Links */}
          <nav className="flex flex-shrink-0 items-center">
            {resourceLinks.map((link, index) => (
              <div key={link.label} className="flex items-center">
                <a
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="whitespace-nowrap rounded-md px-2.5 py-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                  {link.label}
                </a>
                {index < resourceLinks.length - 1 && (
                  <span className="hidden text-border @[520px]:inline">·</span>
                )}
              </div>
            ))}
          </nav>

          {/* Separator */}
          <div className="hidden h-4 w-px flex-shrink-0 bg-border @[680px]:block" />

          {/* Social Icons */}
          <div className="hidden flex-shrink-0 items-center gap-1 @[680px]:flex">
            {socialLinks.map((social) => (
              <a
                key={social.label}
                href={social.href}
                target="_blank"
                rel="noopener noreferrer"
                className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                aria-label={social.label}
              >
                <span
                  className="h-4 w-4 bg-current"
                  style={getSocialIconMaskStyle(social.icon)}
                />
              </a>
            ))}
          </div>
        </div>

        {/* Right section: Trust badge, Legal Links, and Copyright */}
        <div className="flex flex-shrink-0 items-center gap-2">
          {/* Trust / Compliance badge */}
          <a
            href="https://appwrite.io/docs/advanced/security"
            target="_blank"
            rel="noopener noreferrer"
            className="hidden items-center gap-1.5 rounded-md px-2.5 py-1.5 text-[12px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground @[920px]:flex"
          >
            <ShieldCheck
              className="h-3.5 w-3.5 shrink-0 opacity-70"
              aria-hidden
            />
            <span className="whitespace-nowrap font-medium">
              SOC 2 Type II Certified
            </span>
          </a>

          {/* Separator */}
          <div className="hidden h-4 w-px flex-shrink-0 bg-border @[920px]:block" />

          {/* Legal Links */}
          <nav className="hidden items-center @[520px]:flex">
            {legalLinks.map((link, index) => (
              <div key={link.label} className="flex items-center">
                <a
                  href={link.href}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="whitespace-nowrap rounded-md px-2.5 py-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                  {link.label}
                </a>
                {index < legalLinks.length - 1 && (
                  <span className="text-border">·</span>
                )}
              </div>
            ))}
          </nav>

          {/* Separator */}
          <div className="hidden h-4 w-px flex-shrink-0 bg-border @[520px]:block" />

          {/* Copyright */}
          <span className="whitespace-nowrap py-1.5 pe-0 ps-2.5 text-[13px] text-muted-foreground">
            © {currentYear} Appwrite
          </span>
        </div>
      </div>
    </div>
  )

  if (!expanded) {
    return (
      <footer className="@container shrink-0 border-t border-border">
        {compactFooter}
      </footer>
    )
  }

  return (
    <footer className="@container shrink-0 border-t border-border bg-background">
      <div className="mx-auto w-full max-w-7xl px-4 py-10 sm:px-6 sm:py-12">
        <Accordion type="multiple" className="md:hidden">
          {expandedFooterGroups.map((group) => (
            <AccordionItem
              key={group.title}
              value={group.title}
              className="border-border"
            >
              <AccordionTrigger className="py-3.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground hover:no-underline">
                {group.title}
              </AccordionTrigger>
              <AccordionContent className="pb-1">
                <nav aria-label={group.title}>
                  <FooterGroupLinks links={group.links} />
                </nav>
              </AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>

        <div className="hidden gap-x-8 gap-y-10 md:grid md:grid-cols-3 lg:grid-cols-6">
          {expandedFooterGroups.map((group) => (
            <nav key={group.title} aria-label={group.title}>
              <h2 className="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {group.title}
              </h2>
              <FooterGroupLinks links={group.links} />
            </nav>
          ))}
        </div>
      </div>
      <div className="border-t border-border">{compactFooter}</div>
    </footer>
  )
}

function AppwriteMark({ className }: { className?: string }) {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
      aria-hidden
    >
      <path
        fill="currentColor"
        d="M24.4429 16.4322V21.9096H10.7519C6.76318 21.9096 3.28044 19.7067 1.4171 16.4322C1.14622 15.9561 0.909137 15.4567 0.710264 14.9383C0.319864 13.9225 0.0744552 12.8325 0 11.6952V10.2143C0.0161646 9.96089 0.0416361 9.70942 0.0749451 9.46095C0.143032 8.95105 0.245898 8.45211 0.381093 7.96711C1.66006 3.36909 5.81877 0 10.7519 0C15.6851 0 19.8433 3.36909 21.1223 7.96711H15.2682C14.3072 6.4683 12.6437 5.4774 10.7519 5.4774C8.86017 5.4774 7.19668 6.4683 6.23562 7.96711C5.9427 8.42274 5.71542 8.92516 5.56651 9.46095C5.43425 9.93599 5.36371 10.4369 5.36371 10.9548C5.36371 12.5248 6.01324 13.94 7.05463 14.9383C8.01961 15.865 9.32061 16.4322 10.7519 16.4322H24.4429Z"
      />
      <path
        fill="currentColor"
        d="M24.4429 9.46094V14.9383H14.4492C15.4906 13.94 16.1401 12.5248 16.1401 10.9548C16.1401 10.4369 16.0696 9.93598 15.9373 9.46094H24.4429Z"
      />
    </svg>
  )
}
