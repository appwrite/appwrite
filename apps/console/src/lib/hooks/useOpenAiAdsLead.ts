import type { Models } from '@appwrite.io/console'
import { useQueryClient } from '@tanstack/react-query'
import { useLocation } from '@tanstack/react-router'
import { useEffect } from 'react'
import {
  isOpenAiAdsLead,
  measureOpenAiAdsLeadCreated,
  shouldCheckOpenAiAdsLead,
} from '@/lib/openai-ads'
import {
  organizationProjectsQueryOptions,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks/organizations'
import { resolveOrganizationCanonicalPlan } from '@/lib/utils/plan-filter'

type OrganizationBilling = Partial<
  Pick<Models.Organization, 'billingPlan' | 'billingPlanId'>
>

/**
 * OpenAI Ads `lead_created`: while a new account is in its first week, look at
 * what its own organizations built each time the console navigates (at most
 * every few minutes), and convert once it has used enough product areas.
 * Education accounts never convert: none of them went Pro in the study.
 */
export function useOpenAiAdsLead(account: Models.User | null | undefined) {
  const queryClient = useQueryClient()
  const pathname = useLocation({ select: (location) => location.pathname })

  useEffect(() => {
    if (!account || !shouldCheckOpenAiAdsLead(account)) return

    void (async () => {
      const { teams } = await queryClient.fetchQuery(
        organizationsQueryOptions(),
      )
      const isEducation = teams.some(
        (team) =>
          resolveOrganizationCanonicalPlan(team as OrganizationBilling) ===
          'education',
      )
      if (isEducation) return

      // Organizations from before the account are ones it was invited to.
      const registered = Date.parse(account.registration)
      const own = teams.filter(
        (team) => Date.parse(team.$createdAt) >= registered,
      )
      const lists = await Promise.all(
        own.map((team) =>
          queryClient.fetchQuery(organizationProjectsQueryOptions(team.$id)),
        ),
      )
      const projects = lists.flatMap((list) => list.projects)
      if (isOpenAiAdsLead(projects, account.registration)) {
        measureOpenAiAdsLeadCreated(account.$id)
      }
    })().catch(() => {
      // The next navigation checks again.
    })
  }, [account, pathname, queryClient])
}
