import { createFileRoute, redirect } from '@tanstack/react-router'
import { Layout } from '@/components/pages/agent/Layout'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getPageMetaTags } from '@/lib/seo/page-meta'
import { getRequestSiteOrigin, resolveSiteAssetUrl } from '@/lib/marketing/site-origin'
import {
  ASSISTANT_MESSAGES_PAGE_SIZE,
  ASSISTANT_MODELS_PICKER_PAGE_SIZE,
  assistantAutomationsQueryOptions,
  assistantConversationsQueryOptions,
  assistantMessagesQueryOptions,
  assistantModelsInfiniteQueryOptions,
  ensureConsoleAccountQueryData,
} from '@/lib/react-query/hooks'
import {
  parseAIChatActiveConversationId,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import { pageTitle } from '@/lib/utils/page-title'

const AGENT_PAGE_DESCRIPTION =
  'Chat with the Appwrite Agent to inspect your project, explain issues, and take approved actions.'

function getAgentPageMetaTags(orgId: string, siteOrigin?: string) {
  const origin = siteOrigin ?? getRequestSiteOrigin()
  return getPageMetaTags({
    title: pageTitle('Agent'),
    description: AGENT_PAGE_DESCRIPTION,
    canonical: resolveSiteAssetUrl(`/organizations/${orgId}/agent`, origin),
    siteOrigin: origin,
  })
}

export const Route = createFileRoute('/_public/organizations/$orgId/agent')({
  // Match _public: client-only. Avoid SSR flash of empty chrome before data.
  ssr: false,
  component: Layout,
  head: ({ params }) => ({
    meta: getAgentPageMetaTags(params.orgId) as unknown as Array<
      { title: string } | { name: string; content: string } | { property: string; content: string }
    >,
  }),
  loader: async ({ context, params }) => {
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().agent) {
      throw redirect({
        to: '/organizations/$orgId',
        params: { orgId: params.orgId },
        replace: true,
      })
    }

    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) return

    await context.queryClient.ensureQueryData(assistantConversationsQueryOptions())

    void context.queryClient
      .ensureInfiniteQueryData(
        assistantModelsInfiniteQueryOptions(ASSISTANT_MODELS_PICKER_PAGE_SIZE),
      )
      .catch(() => {})
    void context.queryClient
      .ensureQueryData(assistantAutomationsQueryOptions(''))
      .catch(() => {})

    const activeConversationId = parseAIChatActiveConversationId(
      account.prefs as UserPrefs | undefined,
    )
    if (!activeConversationId) return

    void context.queryClient
      .ensureQueryData(
        assistantMessagesQueryOptions(
          activeConversationId,
          ASSISTANT_MESSAGES_PAGE_SIZE,
        ),
      )
      .catch(() => {})
  },
})
