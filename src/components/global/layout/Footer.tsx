import { Settings2, ShieldCheck } from 'lucide-react'
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
  getProductPageUrl,
  isBlogPageExternal,
  isMarketingPageExternal,
  isProductPageExternal,
  type MarketingPagePath,
} from '@/lib/marketing/urls'
import { ProductNewBadge } from '@/components/global/shared/ProductNewBadge'
import { isProductNavItemNew } from '@/lib/products/new-badge'
import type { ProductNavItemId } from '@/lib/products/types'
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
import { useI18n } from '@/lib/i18n'
import { useT } from '@/lib/i18n/translate'
import {
  analyticsAttrs,
  getMarketingProductAnalyticsAction,
  type AnalyticsActionId,
} from '@/lib/analytics-actions'

type FooterLink = {
  label: string
  href: string
  external?: boolean
  analyticsAction?: AnalyticsActionId
  isNew?: boolean
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

function productFooterLink(
  label: string,
  path: string,
  marketing: boolean,
  productId?: ProductNavItemId,
): FooterLink {
  return {
    label,
    href: getProductPageUrl(path, marketing),
    external: isProductPageExternal(marketing),
    analyticsAction: productId
      ? getMarketingProductAnalyticsAction(productId)
      : undefined,
    isNew: productId ? isProductNavItemNew(productId) : false,
  }
}

function marketingProductFooterLink(
  label: string,
  path: MarketingPagePath,
  marketing: boolean,
  productId: ProductNavItemId,
): FooterLink {
  return {
    label,
    href: getMarketingPageUrl(path, marketing),
    external: isMarketingPageExternal(marketing),
    analyticsAction: getMarketingProductAnalyticsAction(productId),
    isNew: isProductNavItemNew(productId),
  }
}

function getExpandedFooterGroups(
  marketing: boolean,
  footerCopy: ReturnType<typeof useI18n>['catalog']['app']['footer'],
  features: { agent: boolean },
): readonly ExpandedFooterGroup[] {
  return [
  {
    title: footerCopy.groups.quickStarts,
    links: [
      docsFooterLink(footerCopy.expanded.quickStarts.web, '/docs/quick-starts/web', marketing),
      docsFooterLink(
        footerCopy.expanded.quickStarts.tanstackStart,
        '/docs/quick-starts/tanstack-start',
        marketing,
      ),
      docsFooterLink(footerCopy.expanded.quickStarts.nextjs, '/docs/quick-starts/nextjs', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.react, '/docs/quick-starts/react', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.vue, '/docs/quick-starts/vue', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.nuxt, '/docs/quick-starts/nuxt', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.svelteKit, '/docs/quick-starts/sveltekit', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.refine, '/docs/quick-starts/refine', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.angular, '/docs/quick-starts/angular', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.reactNative, '/docs/quick-starts/react-native', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.flutter, '/docs/quick-starts/flutter', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.apple, '/docs/quick-starts/apple', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.android, '/docs/quick-starts/android', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.qwik, '/docs/quick-starts/qwik', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.solid, '/docs/quick-starts/solid', marketing),
      docsFooterLink(footerCopy.expanded.quickStarts.astro, '/docs/quick-starts/astro', marketing),
    ],
  },
  {
    title: footerCopy.groups.products,
    links: [
      productFooterLink(footerCopy.expanded.products.auth, '/products/auth', marketing, 'auth'),
      productFooterLink(footerCopy.expanded.products.databases, '/products/databases', marketing, 'databases'),
      productFooterLink(footerCopy.expanded.products.storage, '/products/storage', marketing, 'storage'),
      productFooterLink(footerCopy.expanded.products.functions, '/products/functions', marketing, 'functions'),
      productFooterLink(footerCopy.expanded.products.messaging, '/products/messaging', marketing, 'messaging'),
      {
        ...docsFooterLink(footerCopy.expanded.products.realtime, '/docs/apis/realtime', marketing),
        analyticsAction: getMarketingProductAnalyticsAction('realtime'),
      },
      ...(features.agent
        ? [
            {
              ...docsFooterLink(
                footerCopy.expanded.products.agent,
                '/docs/products/agent',
                marketing,
              ),
              analyticsAction: getMarketingProductAnalyticsAction('agent'),
              isNew: isProductNavItemNew('agent'),
            } satisfies FooterLink,
          ]
        : []),
      productFooterLink(footerCopy.expanded.products.hosting, '/products/sites', marketing, 'sites'),
      marketingProductFooterLink(
        footerCopy.expanded.products.domains,
        '/domains',
        marketing,
        'domains',
      ),
      docsFooterLink(footerCopy.expanded.products.network, '/docs/products/network', marketing),
      productFooterLink(footerCopy.expanded.products.firewall, '/products/firewall', marketing, 'firewall'),
    ],
  },
  {
    title: footerCopy.groups.learn,
    links: [
      { label: footerCopy.expanded.learn.blog, href: getBlogPageUrl('/blog', marketing), external: isBlogPageExternal(marketing) },
      {
        label: footerCopy.expanded.learn.docs,
        href: getMarketingPageUrl('/docs', marketing),
        external: isMarketingPageExternal(marketing),
      },
      { label: footerCopy.expanded.learn.integrations, href: getMarketingPageUrl('/integrations', marketing), external: isMarketingPageExternal(marketing) },
      { label: footerCopy.expanded.learn.community, href: getMarketingPageUrl('/community', marketing), external: isMarketingPageExternal(marketing) },
      { label: footerCopy.expanded.learn.init, href: '/init', external: false },
      { label: footerCopy.expanded.learn.threads, href: getMarketingPageUrl('/threads', marketing), external: isMarketingPageExternal(marketing) },
      { label: footerCopy.expanded.learn.changelog, href: getMarketingPageUrl('/changelog', marketing), external: isMarketingPageExternal(marketing) },
      { label: footerCopy.expanded.learn.roadmap, href: 'https://github.com/appwrite/appwrite/projects', external: true }, // pragma: allowlist secret
      { label: footerCopy.expanded.learn.sourceCode, href: 'https://github.com/appwrite/appwrite', external: true }, // pragma: allowlist secret
      { label: footerCopy.expanded.learn.arena, href: 'https://arena.appwrite.io/', external: true }, // pragma: allowlist secret
      { label: footerCopy.expanded.learn.techNews, href: 'https://refetch.io/', external: true },
    ],
  },
  {
    title: footerCopy.groups.programs,
    links: [
      {
        label: footerCopy.expanded.programs.startups,
        href: getMarketingPageUrl('/startups', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: footerCopy.expanded.programs.education,
        href: getMarketingPageUrl('/education', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: footerCopy.expanded.programs.partners,
        href: getMarketingPageUrl('/partners', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: footerCopy.expanded.programs.enterprise,
        href: getMarketingPageUrl('/enterprise', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: footerCopy.expanded.programs.affiliates,
        href: getMarketingPageUrl('/affiliates', marketing),
        external: isMarketingPageExternal(marketing),
      },
    ],
  },
  {
    title: footerCopy.groups.about,
    links: [
      {
        label: footerCopy.expanded.about.company,
        href: getMarketingPageUrl('/company', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: footerCopy.expanded.about.pricing,
        href: getMarketingPageUrl('/pricing', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: footerCopy.expanded.about.careers,
        href: `${getMarketingPageUrl('/company', marketing)}#careers`,
        external: isMarketingPageExternal(marketing),
      },
      { label: footerCopy.links.store, href: 'https://store.appwrite.io/', external: true }, // pragma: allowlist secret
      {
        label: footerCopy.expanded.about.contactUs,
        href: getMarketingPageUrl('/enterprise', marketing),
        external: isMarketingPageExternal(marketing),
      },
      {
        label: footerCopy.expanded.about.assets,
        href: getMarketingPageUrl('/assets', marketing),
        external: isMarketingPageExternal(marketing),
      },
      docsFooterLink(footerCopy.expanded.about.security, '/docs/advanced/security', marketing),
    ],
  },
  {
    title: footerCopy.groups.compare,
    links: [
      blogFooterLink(footerCopy.expanded.compare.vsSupabase, 'appwrite-compared-to-supabase', marketing), // pragma: allowlist secret
      blogFooterLink(footerCopy.expanded.compare.vsFirebase, 'open-source-firebase-alternative', marketing),
      blogFooterLink(footerCopy.expanded.compare.vsNeon, 'appwrite-vs-neon-ai-backends', marketing), // pragma: allowlist secret
      blogFooterLink(footerCopy.expanded.compare.vsVercel, 'open-source-vercel-alternative', marketing),
      blogFooterLink(footerCopy.expanded.compare.vsNetlify, 'open-source-netlify-alternative', marketing),
      blogFooterLink(footerCopy.expanded.compare.vsCloudinary, 'appwrite-vs-cloudinary', marketing), // pragma: allowlist secret
      blogFooterLink(footerCopy.expanded.compare.vsAuth0, 'appwrite-vs-auth0', marketing), // pragma: allowlist secret
      blogFooterLink(footerCopy.expanded.compare.nextjsHosting, 'free-nextjs-hosting', marketing),
      blogFooterLink(footerCopy.expanded.compare.reactHosting, 'free-react-hosting', marketing),
      blogFooterLink(footerCopy.expanded.compare.vueHosting, 'free-vuejs-hosting', marketing),
      blogFooterLink(footerCopy.expanded.compare.baas, 'backend-as-a-service', marketing),
    ],
  },
] as const
}

function FooterGroupLinks({
  links,
  newLabel,
}: {
  links: readonly FooterLink[]
  newLabel: string
}) {
  return (
    <ul className="space-y-2.5">
      {links.map((link) => (
        <li key={link.label}>
          <a
            href={link.href}
            {...(link.external
              ? { target: '_blank', rel: 'noopener noreferrer' }
              : {})}
            {...(link.analyticsAction
              ? analyticsAttrs(link.analyticsAction)
              : {})}
            className="link-unstyled inline-flex items-center gap-1.5 text-[13px] leading-5 text-muted-foreground transition-colors hover:text-foreground"
          >
            {link.label}
            {link.isNew ? <ProductNewBadge label={newLabel} /> : null}
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
  const t = useT()
  const currentYear = new Date().getFullYear()
  const { isCloud, features } = useConsoleProfile()
  const { catalog } = useI18n()
  const footerCopy = catalog.app.footer
  const newLabel = catalog.website.products.navigation.newLabel
  const cookieConsent = useOptionalCookieConsent()
  const isLegacyTheme = useIsLegacyTheme()
  const cloudStatusEnabled = isCloud && features.systemStatus
  const expandedFooterGroups = getExpandedFooterGroups(
    features.marketing,
    footerCopy,
    features,
  )

  const resourceLinks = [
    {
      label: footerCopy.links.docs,
      href: getMarketingPageUrl('/docs', features.marketing),
      external: isMarketingPageExternal(features.marketing),
    },
    { label: footerCopy.links.store, href: 'https://store.appwrite.io/', external: true }, // pragma: allowlist secret
    ...(cloudStatusEnabled
      ? [
          {
            label: footerCopy.links.status,
            href: 'https://status.appwrite.online', // pragma: allowlist secret
            external: true,
          },
        ]
      : []),
  ]

  const legalLinks = getFooterPolicyLinks(features.marketing)
  const showCookieSettings = cookieConsent?.bannerRequired ?? false

  const socialLinks = [
    {
      label: footerCopy.social.github,
      href: 'https://github.com/appwrite', // pragma: allowlist secret
      icon: '/icons/github.svg',
    },
    { label: footerCopy.social.x, href: 'https://x.com/appwrite', icon: '/icons/x.svg' }, // pragma: allowlist secret
    {
      label: footerCopy.social.youtube,
      href: 'https://youtube.com/@appwrite', // pragma: allowlist secret
      icon: '/icons/youtube.svg',
    },
    {
      label: footerCopy.social.linkedIn,
      href: 'https://www.linkedin.com/company/appwrite/', // pragma: allowlist secret
      icon: '/icons/linkedin.svg',
    },
    {
      label: footerCopy.social.instagram,
      href: 'https://www.instagram.com/appwrite.io/', // pragma: allowlist secret
      icon: '/icons/instagram.svg',
    },
    {
      label: footerCopy.social.discord,
      href: '/discord',
      icon: '/icons/discord-simple.svg',
    },
    {
      label: footerCopy.social.dailyDevSquad,
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
              {footerCopy.links.soc2}
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
                  {t(link.label)}
                </a>
                {link.label === 'Cookies' && showCookieSettings ? (
                  <button
                    type="button"
                    onClick={() => cookieConsent?.openPreferences()}
                    className="-ms-1 flex h-7 w-7 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    aria-label={footerCopy.links.cookieSettings}
                  >
                    <Settings2 className="h-3.5 w-3.5" aria-hidden />
                  </button>
                ) : null}
                {index < legalLinks.length - 1 && (
                  <span className="text-border">·</span>
                )}
              </div>
            ))}
          </nav>

          <div className={cn('h-4 w-px shrink-0 bg-border', footerShowSeparatorMd)} />

          <span className="whitespace-nowrap py-1.5 pe-0 ps-2.5 text-[13px] text-muted-foreground">
            © {currentYear} {footerCopy.links.copyrightBrand}
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
                  <FooterGroupLinks links={group.links} newLabel={newLabel} />
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
              <FooterGroupLinks links={group.links} newLabel={newLabel} />
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
