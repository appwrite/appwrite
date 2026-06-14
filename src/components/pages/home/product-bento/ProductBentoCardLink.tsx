'use client'

import { Link } from '@tanstack/react-router'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'

const linkClassName =
  'absolute inset-0 z-[1] rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background'

type ProductBentoCardLinkProps = {
  href: string
  title: string
}

export function ProductBentoCardLink({ href, title }: ProductBentoCardLinkProps) {
  const label = `Learn more about ${title}`

  if (href.startsWith('/docs')) {
    return (
      <DocsRouteLink href={href} className={linkClassName} aria-label={label}>
        <span className="sr-only">{label}</span>
      </DocsRouteLink>
    )
  }

  return (
    <Link to={href} className={linkClassName} aria-label={label}>
      <span className="sr-only">{label}</span>
    </Link>
  )
}
