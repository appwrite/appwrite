import { Link } from '@tanstack/react-router'
import { ArrowUpRight } from 'lucide-react'
import type { ReactNode } from 'react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import {
  getBlogPageUrl,
  MARKETING_SITE_ORIGIN,
  parseBlogPagePath,
  type MarketingPagePath,
} from '@/lib/marketing/urls'
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
  '/affiliates',
  '/community',
  '/changelog',
  '/blog',
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

  const blogPath = parseBlogPagePath(href)
  if (blogPath) {
    href = getBlogPageUrl(blogPath, getActiveProfileFeatures().marketing)
  }

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

  if (href.startsWith('/blog/post/')) {
    const slug = href.slice('/blog/post/'.length).replace(/\/+$/, '')
    return (
      <Link
        to="/blog/post/$slug"
        params={{ slug }}
        className={linkClassName}
      >
        {children}
      </Link>
    )
  }

  if (href.startsWith('/blog/category/')) {
    const category = href.slice('/blog/category/'.length).replace(/\/+$/, '')
    return (
      <Link
        to="/blog/category/$category"
        params={{ category }}
        className={linkClassName}
      >
        {children}
      </Link>
    )
  }

  if (href.startsWith('/blog/author/')) {
    const author = href.slice('/blog/author/'.length).replace(/\/+$/, '')
    return (
      <Link
        to="/blog/author/$author"
        params={{ author }}
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
  textClassName,
}: {
  href?: string
  children?: ReactNode
  textClassName?: string
}) {
  if (!href) return null

  return (
    <div className="changelog-resource-link not-prose">
      <ChangelogLink
        href={href}
        className={cn(
          'relative block w-full rounded-none px-4 py-4 pe-10 text-start transition-colors duration-150 hover:bg-muted/40',
          textClassName ??
            'text-[13px] font-medium leading-5 text-foreground',
        )}
      >
        <span
          className={cn(
            'min-w-0',
            '[&_p]:m-0 [&_p]:inline [&_p]:text-inherit',
          )}
        >
          {children}
        </span>
        <ArrowUpRight
          className="absolute top-4 end-4 size-3.5 shrink-0 text-muted-foreground"
          aria-hidden
        />
      </ChangelogLink>
    </div>
  )
}
