/** Authenticated console route for the enterprise sales inquiry form. */
export const SALES_FORM_ROUTE = '/sales'

/** In-console places that link to the sales form. */
export const SALES_SOURCES = [
  'billing',
  'settings',
  'members',
  'backups',
  'activity',
  'specs',
  'replicas',
  'compliance',
  'support',
  'support-ticket',
  'plan-picker',
] as const

export type SalesSource = (typeof SALES_SOURCES)[number]

export function getSalesFormSignInSearch(): { redirect: string } {
  return { redirect: SALES_FORM_ROUTE }
}

/**
 * Resolves the sales form URL. Falls back to the authenticated console route;
 * `VITE_CONTACT_SALES_URL` can override for deployments that host the form elsewhere.
 */
export function getContactSalesFormUrl(): string {
  const override = import.meta.env.VITE_CONTACT_SALES_URL?.trim()
  return override || SALES_FORM_ROUTE
}
