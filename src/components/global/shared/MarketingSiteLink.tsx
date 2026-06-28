'use client'

import { Link } from '@tanstack/react-router'
import type { ComponentProps, ReactNode } from 'react'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  getSiteLinkInternalPath,
  isSiteLinkExternal,
  parseBlogPagePath,
  parseDocsPagePath,
  resolveSiteLinkUrl,
} from '@/lib/marketing/urls'

type MarketingSiteLinkProps = Omit<ComponentProps<'a'>, 'href' | 'children'> & {
  href: string
  children: ReactNode
}

export function MarketingSiteLink({
  href,
  children,
  className,
  ...props
}: MarketingSiteLinkProps) {
  const { features } = useConsoleProfile()

  if (parseDocsPagePath(href)) {
    return (
      <DocsRouteLink href={href} className={className} {...props}>
        {children}
      </DocsRouteLink>
    )
  }

  if (parseBlogPagePath(href)) {
    return (
      <BlogPageAnchor href={href} className={className} {...props}>
        {children}
      </BlogPageAnchor>
    )
  }

  const external = isSiteLinkExternal(href, features.marketing)
  const url = resolveSiteLinkUrl(href, features.marketing)
  const internalPath = getSiteLinkInternalPath(href)

  if (!external && internalPath) {
    return (
      <Link to={internalPath} className={className} {...props}>
        {children}
      </Link>
    )
  }

  return (
    <a
      href={url}
      className={className}
      {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      {...props}
    >
      {children}
    </a>
  )
}
