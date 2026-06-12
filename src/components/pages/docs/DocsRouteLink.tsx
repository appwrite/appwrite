'use client'

import { Link } from '@tanstack/react-router'
import type { MouseEvent, ReactNode } from 'react'
import { useDocsPreviewNavigation } from '@/lib/docs/docs-preview-navigation'
import { buildConsoleUrl, openInNewTab } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'

type DocsRouteLinkProps = {
  href: string
  children: ReactNode
  className?: string
  onClick?: () => void
}

export function docsHrefToRoute(href: string) {
  if (href === '/docs' || href === '/docs/') {
    return { to: '/docs' as const, params: undefined }
  }

  if (href.startsWith('/docs/')) {
    return {
      to: '/docs/$' as const,
      params: { _splat: href.slice('/docs/'.length) },
    }
  }

  return null
}

export function DocsRouteLink({
  href,
  children,
  className,
  onClick,
}: DocsRouteLinkProps) {
  const previewNav = useDocsPreviewNavigation()
  const route = docsHrefToRoute(href)

  if (previewNav && route) {
    const handlePreviewClick = (event: MouseEvent<HTMLAnchorElement>) => {
      event.preventDefault()
      event.stopPropagation()
      onClick?.()
      if (!route.params) {
        openInNewTab(buildConsoleUrl('/docs/'))
        return
      }
      previewNav.navigateToSlug(route.params._splat)
    }

    return (
      <a href={href} className={className} onClick={handlePreviewClick}>
        {children}
      </a>
    )
  }

  if (!route) {
    return (
      <a href={href} className={className} onClick={onClick}>
        {children}
      </a>
    )
  }

  if (!route.params) {
    return (
      <Link to={route.to} className={className} onClick={onClick}>
        {children}
      </Link>
    )
  }

  return (
    <Link
      to={route.to}
      params={route.params}
      className={cn(className)}
      onClick={onClick}
    >
      {children}
    </Link>
  )
}
