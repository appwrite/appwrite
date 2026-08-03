import { useMemo, useState, type ReactNode } from 'react'
import { Bug, Check, Copy, PanelRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { cn } from '@/lib/utils'
import {
  buildTurnView,
  type AssistantMessageLike,
} from '@/lib/assistant/turn-view'
import type { AssistantMessage } from '@/lib/react-query/hooks'

function shortId(value?: string | null, length = 8): string | null {
  if (!value) return null
  return value.length > length ? value.slice(0, length) : value
}

function formatDebugValue(value: unknown): string {
  if (value === undefined) return '—'
  if (value === null) return 'null'
  if (typeof value === 'string') return value.trim() ? value : '""'
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value)
  }
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

function DebugField({ label, value }: { label: string; value: unknown }) {
  const text = formatDebugValue(value)
  return (
    <div className="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-x-2 gap-y-0.5 text-[11px]">
      <span className="shrink-0 font-medium text-muted-foreground">{label}</span>
      <span
        className="min-w-0 break-all font-mono text-[10px] text-foreground"
        title={text}
      >
        {text}
      </span>
    </div>
  )
}

function DebugSection({
  title,
  children,
}: {
  title: string
  children: ReactNode
}) {
  return (
    <div className="space-y-1.5">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
        {title}
      </p>
      <div className="space-y-1">{children}</div>
    </div>
  )
}

function MetaChip({ children }: { children: ReactNode }) {
  return (
    <span className="inline-flex max-w-[10rem] shrink-0 truncate rounded bg-purple-500/10 px-1 py-px font-mono text-[10px] text-purple-700 dark:text-purple-300">
      {children}
    </span>
  )
}

export function AgentMessageDebugCard({
  message,
  align = 'start',
}: {
  message: AssistantMessage
  align?: 'start' | 'end'
}) {
  const [open, setOpen] = useState(false)
  const [copied, setCopied] = useState(false)

  const turn = useMemo(
    () => buildTurnView(message as AssistantMessageLike),
    [message],
  )

  const tools = Array.isArray(message.tools) ? message.tools : []
  const timeline = turn.timeline ?? []
  const contentLength = message.contentText?.length ?? 0
  const idShort = shortId(message.$id)
  const runShort = shortId(message.runId)

  const chips = [
    message.status || null,
    tools.length > 0 ? `${tools.length} tools` : null,
    turn.route?.agent || null,
    turn.agents.length > 0 ? `${turn.agents.length} subagents` : null,
    message.contextProjectId
      ? `proj:${shortId(message.contextProjectId)}`
      : null,
    runShort ? `run:${runShort}` : null,
    idShort ? `id:${idShort}` : null,
  ].filter(Boolean) as string[]

  const exportPayload = useMemo(
    () => ({
      message,
      turn,
    }),
    [message, turn],
  )

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(
        JSON.stringify(exportPayload, null, 2),
      )
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      // Ignore clipboard failures in debug UI.
    }
  }

  return (
    <>
      <button
        type="button"
        dir="ltr"
        onClick={() => setOpen(true)}
        aria-label="Open message debug"
        title="Open message debug"
        className={cn(
          'flex max-w-full items-center gap-1.5 rounded-md px-1.5 py-0.5 text-start transition-colors',
          'text-purple-600 hover:bg-purple-500/10 dark:text-purple-400 dark:hover:bg-purple-500/15',
          align === 'end' ? 'ms-auto' : 'me-auto',
        )}
      >
        <Bug className="h-3 w-3 shrink-0" />
        <span className="flex min-w-0 flex-wrap items-center gap-1">
          {chips.map((chip) => (
            <MetaChip key={chip}>{chip}</MetaChip>
          ))}
        </span>
        <PanelRight className="h-3 w-3 shrink-0 opacity-70" />
      </button>

      <BaseDrawer
        open={open}
        onOpenChange={setOpen}
        title="Message debug"
        description="Extended assistant message metadata for debugging."
        maxWidth="sm:max-w-xl"
        headerActions={
          <Button
            type="button"
            size="sm"
            variant="outline"
            className={cn(
              'h-8 gap-1.5 px-2.5 text-[12px]',
              'border-purple-500/40 text-purple-600 hover:bg-purple-500/10 hover:text-purple-700',
              'dark:border-purple-400/40 dark:text-purple-400 dark:hover:bg-purple-500/15 dark:hover:text-purple-300',
            )}
            onClick={() => {
              void handleCopy()
            }}
          >
            {copied ? (
              <Check className="h-3.5 w-3.5" />
            ) : (
              <Copy className="h-3.5 w-3.5" />
            )}
            {copied ? 'Copied' : 'Copy JSON'}
          </Button>
        }
      >
        <div
          dir="ltr"
          lang="en"
          className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 pb-6"
        >
          <DebugSection title="Identity">
            <DebugField label="$id" value={message.$id} />
            <DebugField label="conversationId" value={message.conversationId} />
            <DebugField
              label="parentMessageId"
              value={message.parentMessageId}
            />
            <DebugField label="runId" value={message.runId} />
            <DebugField
              label="editedFrom"
              value={message.editedFromMessageId}
            />
            <DebugField label="retryFrom" value={message.retryFromMessageId} />
          </DebugSection>

          <DebugSection title="Status">
            <DebugField label="role" value={message.role} />
            <DebugField label="status" value={message.status} />
            <DebugField label="contentType" value={message.contentType} />
            <DebugField label="contentLength" value={contentLength} />
            <DebugField label="$createdAt" value={message.$createdAt} />
            <DebugField label="$updatedAt" value={message.$updatedAt} />
          </DebugSection>

          <DebugSection title="Context">
            <DebugField label="projectId" value={message.contextProjectId} />
            <DebugField
              label="organizationId"
              value={message.contextOrganizationId}
            />
            <DebugField label="teamId" value={message.contextTeamId} />
            <DebugField label="pagePath" value={message.contextPagePath} />
            <DebugField label="pageTitle" value={message.contextPageTitle} />
            <DebugField label="pageUrl" value={message.contextPageUrl} />
            <DebugField label="attachments" value={message.attachments} />
          </DebugSection>

          <DebugSection title="Routing">
            <DebugField label="routeAgent" value={message.routeAgent} />
            <DebugField label="routeNext" value={message.routeNext} />
            <DebugField label="routeReason" value={message.routeReason} />
            <DebugField label="answeringAgent" value={turn.answeringAgent} />
            <DebugField label="statusLabel" value={turn.statusLabel} />
            <DebugField label="error" value={turn.error} />
          </DebugSection>

          <DebugSection title="Turn">
            <DebugField
              label="subagents"
              value={turn.agents.map((agent) => ({
                agent: agent.agent,
                open: agent.open,
                failed: agent.failed,
                toolCallCount: agent.toolCallCount,
                summary: agent.summary,
              }))}
            />
            <DebugField
              label="tools"
              value={turn.toolOrder.map((key) => {
                const tool = turn.tools[key]
                return {
                  key,
                  name: tool?.name,
                  agent: tool?.agent,
                  status: tool?.status,
                  toolCallId: tool?.toolCallId,
                  errorMessage: tool?.errorMessage,
                }
              })}
            />
            <DebugField label="timelineEvents" value={timeline.length} />
            <DebugField label="rawTools" value={tools.length} />
          </DebugSection>

          <DebugSection title="Raw message">
            <pre className="max-h-[min(40dvh,20rem)] overflow-auto whitespace-pre-wrap break-words rounded-md border border-border bg-muted/30 p-2.5 font-mono text-[10px] text-foreground">
              {JSON.stringify(message, null, 2)}
            </pre>
          </DebugSection>

          <DebugSection title="Derived turn">
            <pre className="max-h-[min(40dvh,20rem)] overflow-auto whitespace-pre-wrap break-words rounded-md border border-border bg-muted/30 p-2.5 font-mono text-[10px] text-foreground">
              {JSON.stringify(turn, null, 2)}
            </pre>
          </DebugSection>
        </div>
      </BaseDrawer>
    </>
  )
}
