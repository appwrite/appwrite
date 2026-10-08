/** Authenticated console route for the enterprise sales inquiry form. */
export const SALES_FORM_ROUTE = '/sales'

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
