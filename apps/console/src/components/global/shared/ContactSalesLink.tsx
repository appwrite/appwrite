import type { ComponentPropsWithoutRef, ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getContactSalesFormUrl,
  getSalesFormSignInSearch,
  SALES_FORM_ROUTE,
  type SalesSource,
} from '@/lib/sales/contact-sales'
import {
  analyticsAttrs,
  getContactSalesAnalyticsAction,
} from '@/lib/analytics-actions'

type ContactSalesLinkProps = Omit<
  ComponentPropsWithoutRef<'a'>,
  'href' | 'children'
> & {
  children: ReactNode
  /** When true, always routes through sign-in even if the user is authenticated. */
  forceSignIn?: boolean
  /** In-console place the link sits in. Sent to the form and names the click event. */
  source?: SalesSource
  /** Organization the user is working in. The form prefills its company name. */
  orgId?: string | null
}

/**
 * Auth-aware link to the gated sales inquiry form.
 * Authenticated users go to `/sales`; guests go to sign-in with a redirect back.
 */
export function ContactSalesLink({
  children,
  forceSignIn = false,
  source,
  orgId,
  ...props
}: ContactSalesLinkProps) {
  const { isAuthenticated } = useAuth()
  const salesUrl = getContactSalesFormUrl()
  const linkProps = source
    ? { ...analyticsAttrs(getContactSalesAnalyticsAction(source)), ...props }
    : props

  if (!forceSignIn && isAuthenticated && salesUrl === SALES_FORM_ROUTE) {
    return (
      <Link
        to={SALES_FORM_ROUTE}
        search={{ source, orgId: orgId ?? undefined }}
        {...linkProps}
      >
        {children}
      </Link>
    )
  }

  if (salesUrl !== SALES_FORM_ROUTE) {
    return (
      <a
        href={salesUrl}
        target="_blank"
        rel="noopener noreferrer"
        {...linkProps}
      >
        {children}
      </a>
    )
  }

  return (
    <Link to="/sign-in" search={getSalesFormSignInSearch()} {...linkProps}>
      {children}
    </Link>
  )
}
