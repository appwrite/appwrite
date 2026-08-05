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

function getAgentPageMetaTags(siteOrigin?: string) {
  const origin = siteOrigin ?? getRequestSiteOrigin()
  return getPageMetaTags({
    title: pageTitle('Agent'),
    description: AGENT_PAGE_DESCRIPTION,
    canonical: resolveSiteAssetUrl('/agent', origin),
    siteOrigin: origin,
  })
}

export const Route = createFileRoute('/_public/agent')({
  // Match _public: client-only. Avoid SSR flash of empty chrome before data.
  ssr: false,
  component: Layout,
  head: () => ({
    meta: getAgentPageMetaTags() as Array<
      { title: string } | { name: string; content: string } | { property: string; content: string }
    >,
  }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().agent) {
      throw redirect({ to: '/', replace: true })
    }

    // Soft-resolve auth so guests can paint immediately.
    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (!account) return

    // Critical for first paint: conversations list only.
    await context.queryClient.ensureQueryData(assistantConversationsQueryOptions())

    // Non-blocking: models/automations can fill in after the shell paints.
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

    // Warm the active thread without blocking first paint.
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
