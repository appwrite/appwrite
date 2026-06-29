import { ShieldCheck } from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useIsLegacyTheme } from '@/hooks/use-is-legacy-theme'
import { useOptionalCookieConsent } from '@/components/global/providers/CookieConsent'
import { LegacyAppwriteIcon } from '@/components/global/shared/LegacyAppwriteBrand'
import { getFooterPolicyLinks } from '@/lib/legal/policies'
import {
  getBlogPageUrl,
  getDocsPageUrl,
  getMarketingPageUrl,
  isBlogPageExternal,
  isMarketingPageExternal,
} from '@/lib/marketing/urls'
import {
  FOOTER_CONTAINER,
  footerCompactPaddingX,
  footerShowLegalDot,
  footerShowLegalLinks,
  footerShowSeparatorLg,
  footerShowSeparatorMd,
  footerShowSocialIcons,
  footerShowTrustBadge,
} from '@/components/global/layout/footer-container'

type FooterLink = {
  label: string
  href: string
  external?: boolean
}

type ExpandedFooterGroup = {
  title: string
  links: readonly FooterLink[]
}

type ConsoleFooterProps = {
  expanded?: boolean
}

function docsFooterLink(
  label: string,
  path: string,
  marketing: boolean,
): FooterLink {
  return {
    label,
    href: getDocsPageUrl(path, marketing),
    external: isMarketingPageExternal(marketing),
  }
}

function blogFooterLink(
  label: string,
  slug: string,
  marketing: boolean,
): FooterLink {
  return {
    label,
    href: getBlogPageUrl(`/blog/post/${slug}`, marketing),
    external: isBlogPageExternal(marketing),
  }
}

function getExpandedFooterGroups(marketing: boolean): readonly ExpandedFooterGroup[] {
  return [
  {
    title: 'Quick starts',
    links: [
      docsFooterLink('Web', '/docs/quick-starts/web', marketing),
      docsFooterLink('Next.js', '/docs/quick-starts/nextjs', marketing),
      docsFooterLink('React', '/docs/quick-starts/react', marketing),
      docsFooterLink('Vue.js', '/docs/quick-starts/vue', marketing),
      docsFooterLink('Nuxt', '/docs/quick-starts/nuxt', marketing),
      docsFooterLink('SvelteKit', '/docs/quick-starts/sveltekit', marketing),
      docsFooterLink('Refine', '/docs/quick-starts/refine', marketing),
      docsFooterLink('Angular', '/docs/quick-starts/angular', marketing),
      docsFooterLink('React Native', '/docs/quick-starts/react-native', marketing),
      docsFooterLink('Flutter', '/docs/quick-starts/flutter', marketing),
      docsFooterLink('Apple', '/docs/quick-starts/apple', marketing),
      docsFooterLink('Android', '/docs/quick-starts/android', marketing),
      docsFooterLink('Qwik', '/docs/quick-starts/qwik', marketing),
      docsFooterLink('Astro', '/docs/quick-starts/astro', marketing),
      docsFooterLink('Solid', '/docs/quick-starts/solid', marketing),
    ],
  },
  {
    title: 'Products',
    links: [
      {
        label: 'Auth',
        href: marketing ? '/products/auth' : 'https://appwrite.io/products/auth',
        external: !marketing,
      },
      {
        label: 'Databases',
        href: marketing ? '/products/databases' : 'https://appwrite.io/products/databases',
        external: !marketing,
      },
      {
        label: 'Storage',
        href: marketing ? '/products/storage' : 'https://appwrite.io/products/storage',
        external: !marketing,
      },
      {
        label: 'Functions',
        href: marketing ? '/products/functions' : 'https://appwrite.io/products/functions',
        external: !marketing,
      },
      {
        label: 'Messaging',
        href: marketing ? '/products/messaging' : 'https://appwrite.io/products/messaging',
        external: !marketing,
      },
      { label: 'Realtime', href: 'https://appwrite.io/products/realtime', external: true },
      {
        label: 'Hosting',
        href: marketing ? '/products/sites' : 'https://appwrite.io/products/sites',
        external: !marketing,
      },
      { label: 'Network', href: 'https://appwrite.io/products/network', external: true },
    ],
  },
  {
    title: 'Learn',
    links: [
      { label: 'Blog', href: getBlogPageUrl('/blog', marketing), external: isBlogPageExternal(marketing) },
      {
        label: 'Docs',
        href: getMarketingPageUrl('/docs', marketing),
        external: isMarketingPageExternal(marketing),
      },
      { label: 'Integrations', href: getMarketingPageUrl('/integrations', marketing), external: isMarketingPageExternal(marketing) },
      { label: 'Community', href: getMarketingPageUrl('/community', marketing), external: isMarketingPageExternal(marketing) },
      { label: 'Init', href: 'https://appwrite.io/init', external: true },
      { label: 'Threads', href: getMarketingPageUrl('/threads', marketing), external: isMarketingPageExternal(marketing) },
      { label: 'Changelog', href: getMarketingPageUrl('/changelog', marketing), external: isMarketingPageExternal(marketing) },
      { label: 'Roadmap', href: 'https://github.com/appwrite/appwrite/projects', external: true },
      { label: 'Source code', href: 'https://github.com/appwrite/appwrite', external: true },
      { label: 'Arena', href: 'https://arena.appwrite.io/', external: true },
      { label: 'Tech news', href: 'https://refetch.io/', external: true },
    ],
  },
  {
    title: 'Programs',
    links: [
      {
        label: 'Startups',
        href: getMarketingPageUrl('/startups', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: 'Education',
        href: getMarketingPageUrl('/education', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: 'Partners',
        href: getMarketingPageUrl('/partners', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: 'Enterprise',
        href: getMarketingPageUrl('/enterprise', marketing),
        external: isMarketingPageExternal(marketing),
      },
    ],
  },
  {
    title: 'About',
    links: [
      {
        label: 'Company',
        href: getMarketingPageUrl('/company', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: 'Pricing',
        href: getMarketingPageUrl('/pricing', marketing),
        external: isMarketingPageExternal(marketing),
      },
      { label: 'Careers', href: 'https://appwrite.io/careers', external: true },
      { label: 'Store', href: 'https://store.appwrite.io/', external: true },
      { label: 'Contact us', href: 'https://appwrite.io/contact-us', external: true },
      {
        label: 'Assets',
        href: getMarketingPageUrl('/assets', marketing),
        external: isMarketingPageExternal(marketing),
      },
      docsFooterLink('Security', '/docs/advanced/security', marketing),
    ],
  },
  {
    title: 'Compare',
    links: [
      blogFooterLink('Appwrite vs. Supabase', 'appwrite-compared-to-supabase', marketing),
      blogFooterLink('Appwrite vs. Firebase', 'open-source-firebase-alternative', marketing),
      blogFooterLink('Appwrite vs. Neon', 'appwrite-vs-neon-ai-backends', marketing),
      blogFooterLink('Appwrite vs. Vercel', 'open-source-vercel-alternative', marketing),
      blogFooterLink('Appwrite vs. Netlify', 'open-source-netlify-alternative', marketing),
      blogFooterLink('Appwrite vs. Cloudinary', 'appwrite-vs-cloudinary', marketing),
      blogFooterLink('Appwrite vs. Auth0', 'appwrite-vs-auth0', marketing),
      blogFooterLink('Backend as a service (BaaS)', 'backend-as-a-service', marketing),
    ],
  },
] as const
}

function FooterGroupLinks({ links }: { links: readonly FooterLink[] }) {
  return (
    <ul className="space-y-2.5">
      {links.map((link) => (
        <li key={link.label}>
          <a
            href={link.href}
            {...(link.external
              ? { target: '_blank', rel: 'noopener noreferrer' }
              : {})}
            className="link-unstyled text-[13px] leading-5 text-muted-foreground transition-colors hover:text-foreground"
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
  const cookieConsent = useOptionalCookieConsent()
  const isLegacyTheme = useIsLegacyTheme()
  const cloudStatusEnabled = isCloud && features.systemStatus
  const expandedFooterGroups = getExpandedFooterGroups(features.marketing)

  const resourceLinks = [
    {
      label: 'Docs',
      href: getMarketingPageUrl('/docs', features.marketing),
      external: isMarketingPageExternal(features.marketing),
    },
    { label: 'Store', href: 'https://store.appwrite.io/', external: true },
    ...(cloudStatusEnabled
      ? [
          {
            label: 'Status' as const,
            href: 'https://status.appwrite.online',
            external: true,
          },
        ]
      : []),
  ]

  const legalLinks = getFooterPolicyLinks(features.marketing)
  const showCookieSettings = cookieConsent?.bannerRequired ?? false

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
      label: 'LinkedIn',
      href: 'https://www.linkedin.com/company/appwrite/',
      icon: '/icons/linkedin.svg',
    },
    {
      label: 'Instagram',
      href: 'https://www.instagram.com/appwrite.io/',
      icon: '/icons/instagram.svg',
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
      <div
        className={cn(
          'mx-auto flex w-full max-w-7xl items-center justify-between gap-2',
          footerCompactPaddingX,
        )}
      >
        {/* Left: logo, resource links, social icons (social hidden on narrow containers) */}
        <div className="flex min-w-0 shrink items-center gap-2">
          <div
            className={
              isLegacyTheme
                ? 'flex shrink-0 items-center py-1.5 pe-2.5 ps-0'
                : 'flex shrink-0 items-center py-1.5 pe-2.5 ps-0 text-foreground opacity-60'
            }
          >
            {isLegacyTheme ? (
              <LegacyAppwriteIcon className="h-4 w-auto" />
            ) : (
              <AppwriteMark className="h-4 w-4" />
            )}
          </div>

          <div className={cn('h-4 w-px shrink-0 bg-border', footerShowSeparatorMd)} />

          <nav className="flex shrink-0 items-center">
            {resourceLinks.map((link, index) => (
              <div key={link.label} className="flex items-center">
                <a
                  href={link.href}
                  {...(link.external
                    ? { target: '_blank', rel: 'noopener noreferrer' }
                    : {})}
                  className="link-unstyled whitespace-nowrap rounded-md px-2.5 py-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                  {link.label}
                </a>
                {index < resourceLinks.length - 1 && (
                  <span className={cn('text-border', footerShowLegalDot)}>·</span>
                )}
              </div>
            ))}
          </nav>

          <div className={cn('h-4 w-px shrink-0 bg-border', footerShowSeparatorLg)} />

          <div className={cn('shrink-0 items-center gap-1', footerShowSocialIcons)}>
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

        {/* Right: SOC 2 (hidden on narrow containers), legal links, copyright */}
        <div className="flex shrink-0 items-center gap-2">
          <a
            href={getDocsPageUrl('/docs/advanced/security', features.marketing)}
            {...(isMarketingPageExternal(features.marketing)
              ? { target: '_blank', rel: 'noopener noreferrer' }
              : {})}
            className={cn(
              'link-unstyled rounded-md px-2.5 py-1.5 text-[12px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
              footerShowTrustBadge,
            )}
          >
            <ShieldCheck
              className="h-3.5 w-3.5 shrink-0 opacity-70"
              aria-hidden
            />
            <span className="whitespace-nowrap font-medium">
              SOC 2 Type II Certified
            </span>
          </a>

          <div className={cn('h-4 w-px shrink-0 bg-border', footerShowSeparatorLg)} />

          <nav className={cn('items-center', footerShowLegalLinks)}>
            {legalLinks.map((link, index) => (
              <div key={link.label} className="flex items-center">
                <a
                  href={link.href}
                  {...(link.external
                    ? { target: '_blank', rel: 'noopener noreferrer' }
                    : {})}
                  className="link-unstyled whitespace-nowrap rounded-md px-2.5 py-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                  {link.label}
                </a>
                {index < legalLinks.length - 1 && (
                  <span className="text-border">·</span>
                )}
              </div>
            ))}
            {showCookieSettings ? (
              <>
                <span className="text-border">·</span>
                <button
                  type="button"
                  onClick={() => cookieConsent?.openPreferences()}
                  className="link-unstyled whitespace-nowrap rounded-md px-2.5 py-1.5 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                >
                  Cookie settings
                </button>
              </>
            ) : null}
          </nav>

          <div className={cn('h-4 w-px shrink-0 bg-border', footerShowSeparatorMd)} />

          <span className="whitespace-nowrap py-1.5 pe-0 ps-2.5 text-[13px] text-muted-foreground">
            © {currentYear} Appwrite
          </span>
        </div>
      </div>
    </div>
  )

  if (!expanded) {
    return (
      <footer
        className={cn(
          FOOTER_CONTAINER,
          'w-full shrink-0 border-t border-border',
        )}
      >
        {compactFooter}
      </footer>
    )
  }

  return (
    <footer
      className={cn(
        FOOTER_CONTAINER,
        'w-full shrink-0 border-t border-border bg-background',
      )}
    >
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
