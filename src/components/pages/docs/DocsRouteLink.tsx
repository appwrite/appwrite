'use client'

import { Link, useLocation } from '@tanstack/react-router'
import type { MouseEvent, ReactNode } from 'react'
import { useDocsPreview } from '@/components/global/providers/DocsPreview'
import { isConsoleDocsPreviewPath } from '@/lib/docs/docs-preview-context'
import { docsHrefToPreviewSlug } from '@/lib/docs/docs-href'
import type { DocsPreviewView } from '@/lib/docs/docs-preview-menu'
import { useDocsPreviewNavigation } from '@/lib/docs/docs-preview-navigation'
import { cn } from '@/lib/utils'

type DocsRouteLinkProps = {
  href: string
  children: ReactNode
  className?: string
  onClick?: () => void
  /** Preview pane view when opened from the console (defaults to article). */
  previewView?: DocsPreviewView
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
  previewView = 'article',
}: DocsRouteLinkProps) {
  const location = useLocation()
  const previewNav = useDocsPreviewNavigation()
  const { openDocsPreview } = useDocsPreview()
  const route = docsHrefToRoute(href)
  const previewSlug = docsHrefToPreviewSlug(href)
  const canUsePreviewPane = isConsoleDocsPreviewPath(location.pathname)

  if (previewSlug !== null && (canUsePreviewPane || previewNav)) {
    const handlePreviewClick = (event: MouseEvent<HTMLAnchorElement>) => {
      event.preventDefault()
      event.stopPropagation()
      onClick?.()
      if (previewNav) {
        previewNav.navigateToSlug(previewSlug, previewView)
        return
      }
      openDocsPreview(previewSlug, { view: previewView })
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
