import { useEffect, useMemo, useRef } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { useTheme } from 'next-themes'
import { useConsoleRightPane } from '@/components/global/providers/ConsoleRightPaneContext'
import { useCliShellOptional } from '@/components/global/cli-shell/CliShellProvider'
import { useProjectConnectDialog } from '@/components/pages/projects/$projectId/shared/ProjectConnectDialogContext'
import {
  applyConsoleEnvelope,
  createConsoleRefreshHandler,
  createOpenCreateHandler,
  type ConsoleProtocolHandlers,
} from '@/lib/assistant/console-dispatch'
import {
  collectConsoleToolResults,
  consoleToolApplyKey,
  isConsoleToolName,
  normalizeConsolePath,
  type ConsoleDialog,
} from '@/lib/assistant/console-protocol'
import {
  openCommandCenterViaBridge,
  type CommandCenterPage,
} from '@/lib/command-center/opener-bridge'
import {
  buildTurnView,
  type AssistantMessageLike,
} from '@/lib/assistant/turn-view'

const INCOMPLETE_TOOL_STATUSES = new Set([
  'running',
  'queued',
  'pending',
  'processing',
  'in_progress',
  'in-progress',
])

function isIncompleteToolStatus(status: string | null | undefined): boolean {
  return INCOMPLETE_TOOL_STATUSES.has((status ?? '').trim().toLowerCase())
}

function isSuccessfulToolStatus(status: string | null | undefined): boolean {
  const normalized = (status ?? '').trim().toLowerCase()
  return normalized === 'success' || normalized === 'completed'
}

/** Shared handlers for live side-effects and in-message CTAs. */
export function useConsoleProtocolHandlers(options?: {
  projectId?: string | null
  organizationId?: string | null
}): ConsoleProtocolHandlers {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { setTheme } = useTheme()
  const { showAgent, showDocs, hideRightPane } = useConsoleRightPane()
  const cliShell = useCliShellOptional()
  const projectConnect = useProjectConnectDialog()
  const projectId = options?.projectId
  const organizationId = options?.organizationId

  return useMemo<ConsoleProtocolHandlers>(() => {
    const openCreate = createOpenCreateHandler((opts) => {
      return navigate(opts as never)
    }, projectId)
    const refreshScopes = createConsoleRefreshHandler(queryClient, projectId)

    return {
      setTheme: (theme) => {
        setTheme(theme)
      },
      navigate: ({ path, hash, replace }) => {
        const normalized = normalizeConsolePath(path)
        let hashValue = hash?.trim() || undefined
        if (hashValue?.startsWith('#')) {
          hashValue = hashValue.slice(1)
        }
        const href = hashValue ? `${normalized}#${hashValue}` : normalized
        return navigate({
          to: href as never,
          replace: !!replace,
        }).catch(() => {
          if (typeof window !== 'undefined') {
            if (replace) window.location.replace(href)
            else window.location.assign(href)
          }
        })
      },
      openCreate,
      openDialog: (dialog: ConsoleDialog, dialogProjectId?: string) => {
        const pid = dialogProjectId?.trim() || projectId?.trim() || undefined
        if (dialog === 'connect_mcp') {
          projectConnect?.openConnect('mcp')
          return
        }
        if (
          dialog === 'shortcuts' ||
          dialog === 'feedback' ||
          dialog === 'support' ||
          dialog === 'docs_search'
        ) {
          const page: CommandCenterPage =
            dialog === 'docs_search'
              ? 'docs'
              : dialog === 'shortcuts'
                ? 'shortcuts'
                : dialog === 'feedback'
                  ? 'feedback'
                  : 'support'
          openCommandCenterViaBridge(page)
          return
        }
        if (dialog === 'create_project') {
          const orgId = organizationId?.trim()
          if (orgId) {
            void navigate({
              to: '/organizations/$orgId',
              params: { orgId },
              search: { create: 'project' },
            })
          } else {
            void navigate({
              to: '/',
              search: { create: 'project' },
            })
          }
          return
        }
        if (dialog === 'invite_member') {
          const orgId = organizationId?.trim()
          if (orgId) {
            void navigate({
              to: '/organizations/$orgId',
              params: { orgId },
              search: { invite: 'member' },
            })
          }
          return
        }
        void pid
      },
      showPane: (content) => {
        if (content === 'agent') showAgent()
        else if (content === 'docs') showDocs()
        else hideRightPane()
      },
      toggleTerminal: () => {
        cliShell?.toggle()
      },
      refreshScopes,
    }
  }, [
    cliShell,
    hideRightPane,
    navigate,
    organizationId,
    projectConnect,
    projectId,
    queryClient,
    setTheme,
    showAgent,
    showDocs,
  ])
}

/**
 * Apply console side-effects only when a `console` tool transitions from
 * in-progress → success during this session.
 *
 * First observation of an already-completed tool is treated as history and
 * never auto-runs (avoids Command Center / navigate flashes when the agent
 * pane loads a conversation). Replay via in-message CTAs instead.
 */
export function useConsoleProtocolEffects(
  messages: AssistantMessageLike[] | null | undefined,
  options?: {
    conversationId?: string | null
    projectId?: string | null
    organizationId?: string | null
  },
) {
  const handlers = useConsoleProtocolHandlers(options)
  const conversationId = options?.conversationId

  /** Last observed status per apply key (scoped to the active conversation). */
  const toolStatusByKeyRef = useRef<Map<string, string>>(new Map())
  const appliedToolKeysRef = useRef<Set<string>>(new Set())
  const trackedConversationRef = useRef<string | null>(null)

  useEffect(() => {
    if (!conversationId) return

    if (trackedConversationRef.current !== conversationId) {
      trackedConversationRef.current = conversationId
      toolStatusByKeyRef.current = new Map()
      appliedToolKeysRef.current = new Set()
    }

    for (const message of messages ?? []) {
      const turn = buildTurnView(message)
      const messageId = turn.messageId?.trim()
      if (!messageId) continue

      for (const toolKey of turn.toolOrder) {
        const tool = turn.tools[toolKey]
        if (!tool || !isConsoleToolName(tool.name)) continue

        const applyKey = `${conversationId}:${consoleToolApplyKey(tool, {
          messageId,
        })}`
        const status = (tool.status ?? '').trim().toLowerCase() || 'unknown'
        const previousStatus = toolStatusByKeyRef.current.get(applyKey)
        toolStatusByKeyRef.current.set(applyKey, status)

        // First time we see this tool: record status only. Already-complete
        // tools are historical and must not auto-run.
        if (previousStatus === undefined) continue

        if (appliedToolKeysRef.current.has(applyKey)) continue
        if (!isIncompleteToolStatus(previousStatus)) continue
        if (!isSuccessfulToolStatus(status)) continue

        const envelope = collectConsoleToolResults([
          { ...tool, messageId },
        ])[0]?.envelope
        if (!envelope) continue

        appliedToolKeysRef.current.add(applyKey)
        applyConsoleEnvelope(envelope, handlers)
      }
    }
  }, [conversationId, handlers, messages])
}
