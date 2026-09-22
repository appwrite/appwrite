import { createIsomorphicFn } from '@tanstack/react-start'
import { getRequest } from '@tanstack/react-start/server'
import {
  readSsrVisitorCountryFromWindow,
  resolveVisitorCountryFromRequest,
} from '@/lib/locale/visitor-country'
import {
  SSR_VISITOR_COUNTRY_WINDOW_KEY,
  injectSsrVisitorCountryIntoHtml,
} from '@/lib/visitor-country-shared'

/**
 * Fast visitor country for first paint. Server APIs stay inside `.server()`
 * so the client bundle does not import `@tanstack/react-start/server`.
 */
export const getSsrVisitorCountry = createIsomorphicFn()
  .server((): string | null => {
    try {
      return resolveVisitorCountryFromRequest(getRequest())
    } catch {
      return null
    }
  })
  .client((): string | null => {
    return readSsrVisitorCountryFromWindow()
  })

export function getSsrVisitorCountryScript(): string {
  return `window.${SSR_VISITOR_COUNTRY_WINDOW_KEY}=${JSON.stringify(getSsrVisitorCountry())};`
}

export { injectSsrVisitorCountryIntoHtml }
