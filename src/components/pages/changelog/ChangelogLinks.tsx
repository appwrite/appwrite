import { Link } from '@tanstack/react-router'
import { ChevronRight, ExternalLink } from 'lucide-react'
import type { ReactNode } from 'react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import { MARKETING_SITE_ORIGIN, type MarketingPagePath } from '@/lib/marketing/urls'
import { cn } from '@/lib/utils'

const INTERNAL_MARKETING_ROUTES = new Set<MarketingPagePath>([
  '/terms',
  '/privacy',
  '/cookies',
  '/company',
  '/assets',
  '/pricing',
  '/partners',
  '/education',
  '/startups',
  '/community',
  '/changelog',
])

function normalizePath(href: string): string {
  return href.split('#')[0]?.replace(/\/+$/, '') || '/'
}

export function ChangelogLink({
  href,
  children,
  className,
}: {
  href?: string
  children: ReactNode
  className?: string
}) {
  const linkClassName = className ?? DOCS_PROSE_LINK_CLASS

  if (!href) return <span className={className}>{children}</span>

  const external =
    href.startsWith('http') ||
    href.startsWith('//') ||
    href.startsWith('mailto:') ||
    href.startsWith('tel:')

  if (external || href.startsWith('#')) {
    return (
      <a
        href={href}
        className={linkClassName}
        {...(external && !href.startsWith('#')
          ? { target: '_blank', rel: 'noopener noreferrer' }
          : {})}
      >
        {children}
      </a>
    )
  }

  if (href.startsWith('/docs')) {
    return (
      <DocsRouteLink href={href} className={linkClassName}>
        {children}
      </DocsRouteLink>
    )
  }

  if (href.startsWith('/changelog/entry/')) {
    const slug = href.slice('/changelog/entry/'.length)
    return (
      <Link
        to="/changelog/entry/$entry"
        params={{ entry: slug }}
        className={linkClassName}
      >
        {children}
      </Link>
    )
  }

  const normalizedPath = normalizePath(href)
  if (INTERNAL_MARKETING_ROUTES.has(normalizedPath as MarketingPagePath)) {
    return (
      <Link to={normalizedPath as MarketingPagePath} className={linkClassName}>
        {children}
      </Link>
    )
  }

  if (href.startsWith('/')) {
    return (
      <a
        href={`${MARKETING_SITE_ORIGIN}${href}`}
        className={linkClassName}
        target="_blank"
        rel="noopener noreferrer"
      >
        {children}
      </a>
    )
  }

  return (
    <a href={href} className={linkClassName}>
      {children}
    </a>
  )
}

function isExternalChangelogHref(href: string): boolean {
  return (
    href.startsWith('http') ||
    href.startsWith('//') ||
    (!href.startsWith('/docs') &&
      !href.startsWith('/changelog') &&
      !INTERNAL_MARKETING_ROUTES.has(normalizePath(href) as MarketingPagePath) &&
      href.startsWith('/'))
  )
}

export const CHANGELOG_RESOURCE_LINK_GROUP_CLASSES = [
  '[&_.changelog-resource-link]:mt-8',
  '[&_.changelog-resource-link]:overflow-hidden',
  '[&_.changelog-resource-link]:bg-card/50',
  // Single link
  '[&_.changelog-resource-link:not(:has(+_.changelog-resource-link)):not(.changelog-resource-link+_.changelog-resource-link)]:rounded-xl',
  '[&_.changelog-resource-link:not(:has(+_.changelog-resource-link)):not(.changelog-resource-link+_.changelog-resource-link)]:border',
  '[&_.changelog-resource-link:not(:has(+_.changelog-resource-link)):not(.changelog-resource-link+_.changelog-resource-link)]:border-border',
  // First link in a group
  '[&_.changelog-resource-link:has(+_.changelog-resource-link):not(.changelog-resource-link+_.changelog-resource-link)]:rounded-t-xl',
  '[&_.changelog-resource-link:has(+_.changelog-resource-link):not(.changelog-resource-link+_.changelog-resource-link)]:border',
  '[&_.changelog-resource-link:has(+_.changelog-resource-link):not(.changelog-resource-link+_.changelog-resource-link)]:border-border',
  '[&_.changelog-resource-link:has(+_.changelog-resource-link):not(.changelog-resource-link+_.changelog-resource-link)]:border-b-0',
  // Additional links in a group (separator + sides)
  '[&_.changelog-resource-link+.changelog-resource-link]:mt-0',
  '[&_.changelog-resource-link+.changelog-resource-link]:border-border',
  '[&_.changelog-resource-link+.changelog-resource-link]:border-x',
  '[&_.changelog-resource-link+.changelog-resource-link]:border-t',
  // Last link in a group
  '[&_.changelog-resource-link+.changelog-resource-link:not(:has(+_.changelog-resource-link))]:rounded-b-xl',
  '[&_.changelog-resource-link+.changelog-resource-link:not(:has(+_.changelog-resource-link))]:border-b',
] as const

export function ChangelogArrowLink({
  href,
  children,
}: {
  href?: string
  children?: ReactNode
}) {
  if (!href) return null

  const external = isExternalChangelogHref(href)

  return (
    <div className="changelog-resource-link group not-prose transition-colors duration-150 hover:bg-muted/40">
      <ChangelogLink
        href={href}
        className={cn(
          'flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left',
          'text-[13px] font-medium text-foreground',
        )}
      >
        <span
          className={cn(
            'min-w-0 leading-5',
            'transition-[text-decoration-thickness,text-decoration-color] duration-150',
            'group-hover:underline group-hover:decoration-dotted group-hover:decoration-muted-foreground/60',
            'group-hover:underline-offset-[3px] group-hover:[text-decoration-thickness:1px]',
            '[&_p]:m-0 [&_p]:inline [&_p]:text-inherit',
          )}
        >
          {children}
        </span>
        {external ? (
          <ExternalLink
            className="size-3.5 shrink-0 text-muted-foreground"
            aria-hidden
          />
        ) : (
          <ChevronRight
            className="size-4 shrink-0 text-muted-foreground opacity-70 transition-transform duration-150 group-hover:translate-x-0.5"
            aria-hidden
          />
        )}
      </ChangelogLink>
    </div>
  )
}
