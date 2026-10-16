import type { ComponentPropsWithoutRef, ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getContactSalesFormUrl,
  getSalesFormSignInSearch,
  SALES_FORM_ROUTE,
} from '@/lib/sales/contact-sales'

type ContactSalesLinkProps = Omit<
  ComponentPropsWithoutRef<'a'>,
  'href' | 'children'
> & {
  children: ReactNode
  /** When true, always routes through sign-in even if the user is authenticated. */
  forceSignIn?: boolean
}

/**
 * Auth-aware link to the gated sales inquiry form.
 * Authenticated users go to `/sales`; guests go to sign-in with a redirect back.
 */
export function ContactSalesLink({
  children,
  forceSignIn = false,
  ...props
}: ContactSalesLinkProps) {
  const { isAuthenticated } = useAuth()
  const salesUrl = getContactSalesFormUrl()

  if (!forceSignIn && isAuthenticated && salesUrl === SALES_FORM_ROUTE) {
    return (
      <Link to={SALES_FORM_ROUTE} {...props}>
        {children}
      </Link>
    )
  }

  if (salesUrl !== SALES_FORM_ROUTE) {
    return (
      <a href={salesUrl} target="_blank" rel="noopener noreferrer" {...props}>
        {children}
      </a>
    )
  }

  return (
    <Link to="/sign-in" search={getSalesFormSignInSearch()} {...props}>
      {children}
    </Link>
  )
}
