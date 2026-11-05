import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  buildAffiliateApiInviteUrl,
  isValidAffiliateLinkId,
} from '@/lib/affiliates/invite-url'

/**
 * Short affiliate invite links on the app domain.
 * Example: https://cloud.appwrite.io/i/Ab12Cd34 → API /v1/affiliates/invite/Ab12Cd34
 *
 * Server 302 so crawlers and first visits never wait on JS. The API then
 * records the click, sets attribution, and continues to signup.
 */
export const Route = createFileRoute('/i/$linkId')({
  ssr: true,
  server: {
    handlers: {
      GET: ({ params }) => affiliateInviteRedirectResponse(params.linkId),
    },
  },
  beforeLoad: ({ params }) => {
    throwAffiliateInviteRedirect(params.linkId)
  },
})

function throwAffiliateInviteRedirect(rawLinkId: string | undefined): never {
  const linkId = rawLinkId?.trim() ?? ''
  if (!isValidAffiliateLinkId(linkId)) {
    throw redirect({ to: '/sign-up', replace: true })
  }

  throw redirect({
    href: buildAffiliateApiInviteUrl(linkId),
    statusCode: 302,
    replace: true,
  })
}

function affiliateInviteRedirectResponse(rawLinkId: string | undefined) {
  const linkId = rawLinkId?.trim() ?? ''
  if (!isValidAffiliateLinkId(linkId)) {
    return new Response(null, {
      status: 302,
      headers: { Location: '/sign-up' },
    })
  }

  return new Response(null, {
    status: 302,
    headers: { Location: buildAffiliateApiInviteUrl(linkId) },
  })
}
