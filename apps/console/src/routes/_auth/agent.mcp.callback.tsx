import { useEffect, useMemo, useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { CheckCircle2, Loader2, XCircle } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import {
  agentSettingsPath,
  preferredOrganizationId,
} from '@/lib/assistant/agent-paths'
import {
  ASSISTANT_MCP_OAUTH_MESSAGE_TYPE,
  parseMcpOAuthCallbackSearch,
  type McpOAuthCallbackMessage,
} from '@/lib/assistant/mcp-oauth'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import { fetchConsoleAccount } from '@/lib/react-query/hooks/auth'

export const Route = createFileRoute('/_auth/agent/mcp/callback')({
  ssr: false,
  validateSearch: (search: Record<string, unknown>) => search,
  component: AgentMcpOAuthCallbackPage,
  head: () => ({
    meta: [{ title: pageTitle('Connecting MCP') }],
  }),
})

function AgentMcpOAuthCallbackPage() {
  const t = useT()
  const search = Route.useSearch()
  const message = useMemo(
    () => parseMcpOAuthCallbackSearch(search),
    [search],
  )
  const [posted, setPosted] = useState(false)

  useEffect(() => {
    const payload: McpOAuthCallbackMessage = message
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage(payload, window.location.origin)
      setPosted(true)
      window.setTimeout(() => {
        window.close()
      }, 400)
      return
    }

    // Top-level redirect fallback: keep the result available via session and
    // bounce back to org agent MCP settings so the opener path is not required.
    if (payload.type === ASSISTANT_MCP_OAUTH_MESSAGE_TYPE) {
      sessionStorage.setItem(
        'assistant.mcp.oauth.callback',
        JSON.stringify(payload),
      )
    }
    setPosted(true)
    window.setTimeout(() => {
      void (async () => {
        try {
          const account = await fetchConsoleAccount()
          const fromPrefs = preferredOrganizationId(
            account.prefs as Record<string, unknown> | undefined,
          )
          const orgId =
            fromPrefs ?? (await resolvePostAuthOrganizationId(account))
          window.location.replace(agentSettingsPath(orgId, 'mcp'))
        } catch {
          window.location.replace('/agent/settings/mcp')
        }
      })()
    }, 800)
  }, [message])

  const isError = message.status === 'error'

  return (
    <div className="flex min-h-svh items-center justify-center bg-background px-4">
      <div className="w-full max-w-sm rounded-xl border border-border bg-card p-6 text-center">
        {isError ? (
          <XCircle className="mx-auto h-8 w-8 text-destructive" />
        ) : posted ? (
          <CheckCircle2 className="mx-auto h-8 w-8 text-green-600" />
        ) : (
          <Loader2 className="mx-auto h-8 w-8 animate-spin text-muted-foreground" />
        )}
        <h1 className="mt-3 text-[15px] font-semibold text-foreground">
          {isError
            ? t('MCP connection failed')
            : posted
              ? t('MCP connected')
              : t('Connecting MCP...')}
        </h1>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {isError
            ? message.errorDescription || message.error
            : t('You can close this window and return to the console.')}
        </p>
      </div>
    </div>
  )
}
