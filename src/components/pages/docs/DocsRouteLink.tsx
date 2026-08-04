'use client'

import { Link, useLocation } from '@tanstack/react-router'
import type { ComponentProps, MouseEvent, ReactNode } from 'react'
import { useDocsPreview } from '@/components/global/providers/DocsPreview'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { isConsoleDocsPreviewPath } from '@/lib/docs/docs-preview-context'
import { docsHrefToPreviewSlug } from '@/lib/docs/docs-href'
import type { DocsPreviewView } from '@/lib/docs/docs-preview-menu'
import { useDocsPreviewNavigation } from '@/lib/docs/docs-preview-navigation'
import { getDocsPageUrl, splitHrefHash } from '@/lib/marketing/urls'
import { buildConsoleUrl, openInNewWindow } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'

/** Modal / drawer overlays where the right-pane docs preview would open behind the overlay. */
const OVERLAY_CONTENT_SELECTOR = [
  '[data-slot="dialog-content"]',
  '[data-slot="alert-dialog-content"]',
  '[data-slot="sheet-content"]',
  '[data-slot="drawer-content"]',
].join(', ')

function isInsideOverlay(target: EventTarget | null): boolean {
  return (
    target instanceof Element &&
    Boolean(target.closest(OVERLAY_CONTENT_SELECTOR))
  )
}

type DocsRouteLinkProps = Omit<ComponentProps<'a'>, 'href' | 'children'> & {
  href: string
  children: ReactNode
  /** Preview pane view when opened from the console (defaults to article). */
  previewView?: DocsPreviewView
}

export function docsHrefToRoute(href: string) {
  const { pathname, hash } = splitHrefHash(href)

  if (pathname === '/docs' || pathname === '/docs/') {
    return { to: '/docs' as const, params: undefined, hash: hash || undefined }
  }

  if (pathname.startsWith('/docs/')) {
    return {
      to: '/docs/$' as const,
      params: { _splat: pathname.slice('/docs/'.length) },
      hash: hash || undefined,
    }
  }

  return null
}

function openDocsInNewWindow(href: string, marketingEnabled: boolean) {
  const docsUrl = getDocsPageUrl(href, marketingEnabled)
  openInNewWindow(
    marketingEnabled ? buildConsoleUrl(docsUrl) : docsUrl,
  )
}

export function DocsRouteLink({
  href,
  children,
  className,
  onClick,
  previewView = 'article',
  ...props
}: DocsRouteLinkProps) {
  const location = useLocation()
  const { features } = useConsoleProfile()
  const marketingEnabled = features.marketing
  const previewNav = useDocsPreviewNavigation()
  const { openDocsPreview } = useDocsPreview()
  const route = docsHrefToRoute(href)
  const previewSlug = docsHrefToPreviewSlug(href)
  const canUsePreviewPane =
    marketingEnabled && isConsoleDocsPreviewPath(location.pathname)

  if (route && !marketingEnabled) {
    const externalUrl = getDocsPageUrl(href, false)
    const handleExternalClick = (event: MouseEvent<HTMLAnchorElement>) => {
      event.preventDefault()
      event.stopPropagation()
      onClick?.(event)
      openInNewWindow(externalUrl)
    }

    return (
      <a
        {...props}
        href={externalUrl}
        className={className}
        onClick={handleExternalClick}
      >
        {children}
      </a>
    )
  }

  if (previewSlug !== null && (canUsePreviewPane || previewNav)) {
    const handlePreviewClick = (event: MouseEvent<HTMLAnchorElement>) => {
      event.preventDefault()
      event.stopPropagation()
      onClick?.(event)
      // Modals/drawers sit above the right pane; open docs in a new window instead.
      if (isInsideOverlay(event.currentTarget)) {
        openDocsInNewWindow(href, marketingEnabled)
        return
      }
      if (previewNav) {
        previewNav.navigateToSlug(previewSlug, previewView)
        return
      }
      openDocsPreview(previewSlug, { view: previewView })
    }

    return (
      <a {...props} href={href} className={className} onClick={handlePreviewClick}>
        {children}
      </a>
    )
  }

  if (!route) {
    return (
      <a {...props} href={href} className={className} onClick={onClick}>
        {children}
      </a>
    )
  }

  if (!route.params) {
    return (
      <Link
        {...props}
        to={route.to}
        hash={route.hash}
        className={className}
        onClick={onClick}
      >
        {children}
      </Link>
    )
  }

  return (
    <Link
      {...props}
      to={route.to}
      params={route.params}
      hash={route.hash}
      className={cn(className)}
      onClick={onClick}
    >
      {children}
    </Link>
  )
}
