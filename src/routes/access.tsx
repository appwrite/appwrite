import { createFileRoute, redirect } from '@tanstack/react-router'
import { getRuntimeConfig } from '@/lib/runtime-config'
import { pageTitle } from '@/lib/utils/page-title'
import {
  hasWebsiteAccessCookie,
  isWebsiteAccessEnabled,
} from '@/lib/website-access'

/**
 * Soft-launch landing route. The root WebsiteAccessGate renders the password
 * form full-screen; this page itself stays empty.
 */
export const Route = createFileRoute('/access')({
  ssr: false,
  beforeLoad: () => {
    if (typeof window === 'undefined') return
    // Gate off or already unlocked: don't leave users on a blank /access page.
    if (
      !isWebsiteAccessEnabled(getRuntimeConfig().websiteAccess) ||
      hasWebsiteAccessCookie()
    ) {
      throw redirect({ to: '/', replace: true })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Password protected') }] }),
  component: () => null,
})
