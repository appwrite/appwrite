import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  buildAffiliateApiInviteUrl,
  isValidAffiliateLinkId,
} from '@/lib/affiliates/invite-url'

/**
 * Short affiliate invite links on the app domain.
 * Example: https://cloud.appwrite.io/i/Ab12Cd34 → API /v1/affiliates/invite/Ab12Cd34
 */
export const Route = createFileRoute('/i/$linkId')({
  beforeLoad: ({ params }) => {
    const linkId = params.linkId?.trim() ?? ''
    if (!isValidAffiliateLinkId(linkId)) {
      throw redirect({ to: '/sign-up', replace: true })
    }

    throw redirect({
      href: buildAffiliateApiInviteUrl(linkId),
      statusCode: 302,
      replace: true,
    })
  },
})
