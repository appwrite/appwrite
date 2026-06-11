import { Link } from '@tanstack/react-router'
import type { ReactNode } from 'react'
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
  const route = docsHrefToRoute(href)

  if (!route) {
    return (
      <a href={href} className={className}>
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
