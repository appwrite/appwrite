import { useLayoutEffect } from 'react'
import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  buildAffiliateApiInviteUrl,
  isValidAffiliateLinkId,
} from '@/lib/affiliates/invite-url'

/**
 * Short affiliate invite links on the app domain.
 * Example: https://cloud.appwrite.io/i/Ab12Cd34 → API /v1/affiliates/invite/Ab12Cd34
 *
 * Client-only so `getApiEndpoint()` can use the active endpoint (env or debug
 * override in localStorage), not the SSR-only env value.
 */
export const Route = createFileRoute('/i/$linkId')({
  ssr: false,
  beforeLoad: ({ params }) => {
    if (typeof window === 'undefined') return

    const linkId = params.linkId?.trim() ?? ''
    if (!isValidAffiliateLinkId(linkId)) {
      throw redirect({ to: '/sign-up', replace: true })
    }

    // Prefer a hard navigation so the browser follows the API invite redirect
    // chain (set cookie → signup) against the active endpoint.
    window.location.replace(buildAffiliateApiInviteUrl(linkId))
  },
  component: AffiliateInviteRedirect,
})

function AffiliateInviteRedirect() {
  const { linkId: rawLinkId } = Route.useParams()

  useLayoutEffect(() => {
    const linkId = rawLinkId?.trim() ?? ''
    if (!isValidAffiliateLinkId(linkId)) {
      window.location.replace('/sign-up')
      return
    }
    window.location.replace(buildAffiliateApiInviteUrl(linkId))
  }, [rawLinkId])

  return null
}
