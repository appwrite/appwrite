import { createIsomorphicFn } from '@tanstack/react-start'
import { getRequest } from '@tanstack/react-start/server'
import {
  getActiveProfileFeatures,
  getProfileFeaturesForCookieHeader,
  type ConsoleProfileFeatures,
} from '@/lib/console-profiles'

/**
 * Profile features for the current render. Server APIs stay inside `.server()`
 * so the client bundle does not import `@tanstack/react-start/server`; the
 * debug profile cookie lets SSR match a localStorage override.
 */
export const getProductPageFeatures = createIsomorphicFn()
  .server((): ConsoleProfileFeatures => {
    try {
      return getProfileFeaturesForCookieHeader(
        getRequest().headers.get('cookie'),
      )
    } catch {
      return getActiveProfileFeatures()
    }
  })
  .client((): ConsoleProfileFeatures => getActiveProfileFeatures())
