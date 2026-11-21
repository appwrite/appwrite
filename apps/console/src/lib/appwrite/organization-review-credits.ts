/**
 * Whether code reviews may buy extra credits past the plan's allowance.
 * Cloud returns `reviewCreditsOverage` on the organization and takes it on
 * `PATCH /organizations/:organizationId/review-credits`; the generated SDK
 * has neither yet, so the update goes through `client.call`.
 */

import type { Client, Models } from '@appwrite.io/console'

/**
 * Whether reviews keep running past the included credits. Editions without
 * the switch, and organizations that never chose, read as true.
 */
export function allowsReviewCreditsOverage(
  organization: object | null | undefined,
): boolean {
  const value = (
    organization as { reviewCreditsOverage?: unknown } | null | undefined
  )?.reviewCreditsOverage
  return value !== false
}

export function updateReviewCredits(
  client: Client,
  params: { organizationId: string; overage: boolean },
): Promise<Models.Organization<Models.Preferences>> {
  const uri = new URL(
    `${client.config.endpoint}/organizations/${encodeURIComponent(params.organizationId)}/review-credits`,
  )
  return client.call(
    'patch',
    uri,
    {
      'X-Appwrite-Project': client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    },
    { overage: params.overage },
  ) as Promise<Models.Organization<Models.Preferences>>
}
