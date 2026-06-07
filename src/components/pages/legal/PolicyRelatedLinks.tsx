import { Link, useLocation } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { cn } from '@/lib/utils'
import {
  getRelatedPolicyLinks,
  type PolicySlug,
} from '@/lib/legal/policies'
import {
  PolicySidebarSection,
  policySidebarLinkClassName,
} from './PolicySidebarNav'

type PolicyRelatedLinksProps = {
  current: PolicySlug
  className?: string
  /** When true, wraps links in the sidebar section label (desktop panel). */
  embedded?: boolean
}

export function PolicyRelatedLinks({
  current,
  className,
  embedded = false,
}: PolicyRelatedLinksProps) {
  const location = useLocation()
  const { features } = useConsoleProfile()
  const links = getRelatedPolicyLinks(current, features.marketing)

  if (links.length === 0) return null

  const linkClassName = (isActive: boolean, embeddedLink: boolean) =>
    embeddedLink
      ? policySidebarLinkClassName(isActive)
      : cn(
          'inline-flex rounded-md border border-border bg-card/50 px-3 py-1.5 text-[13px] font-medium leading-5 transition-colors',
          isActive
            ? 'bg-accent text-foreground'
            : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
        )

  const linkList = (
    <ul className={embedded ? 'space-y-0.5' : 'flex flex-wrap gap-2'}>
      {links.map((link) => {
        const isActive =
          !link.external && location.pathname === link.path

        return (
          <li key={link.slug}>
            {link.external ? (
              <a
                href={link.href}
                target="_blank"
                rel="noopener noreferrer"
                className={linkClassName(false, embedded)}
              >
                {link.label}
              </a>
            ) : (
              <Link
                to={link.path}
                className={linkClassName(isActive, embedded)}
              >
                {link.label}
              </Link>
            )}
          </li>
        )
      })}
    </ul>
  )

  if (embedded) {
    return (
      <PolicySidebarSection
        title="Related policies"
        ariaLabel="Related policies"
        className={className}
      >
        {linkList}
      </PolicySidebarSection>
    )
  }

  return (
    <nav aria-label="Related policies" className={className}>
      <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        Related policies
      </p>
      {linkList}
    </nav>
  )
}
