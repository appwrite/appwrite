import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

/**
 * Soft-launch landing route. The root WebsiteAccessGate renders the password
 * form full-screen; this page itself stays empty.
 */
export const Route = createFileRoute('/access')({
  ssr: false,
  head: () => ({ meta: [{ title: pageTitle('Password protected') }] }),
  component: () => null,
})
