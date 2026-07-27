import { createFileRoute, redirect } from '@tanstack/react-router'
import { hasWebsiteAccessCookie } from '@/lib/website-access'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * Soft-launch landing route. The root WebsiteAccessGate renders the password
 * form full-screen; this page itself stays empty.
 */
export const Route = createFileRoute('/access')({
  ssr: false,
  beforeLoad: () => {
    if (typeof window === 'undefined') return
    // Already unlocked: don't leave users on a blank /access page.
    if (hasWebsiteAccessCookie()) {
      throw redirect({ to: '/', replace: true })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Password protected') }] }),
  component: () => null,
})
