import { useEffect, useMemo, useState } from 'react'
import {
  AlertCircle,
  Check,
  CheckCircle2,
  ChevronDown,
  Copy,
  Loader2,
  Route,
  Wrench,
} from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { ToolSiteFaviconStack } from '@/components/global/providers/agent/ToolSiteFaviconStack'
import { captureExceptionWithContext } from '@/components/global/providers/SentryContext'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { getToolVisualSites } from '@/lib/assistant/tool-sites'
import { isClarifyToolName } from '@/lib/assistant/clarify-protocol'
import { isConsoleToolName } from '@/lib/assistant/console-protocol'
import {
  buildTurnView,
  getAssistantAgentLabel,
  isAssistantMessageInFlight,
  toolsForAgent,
  unscopedTools,
  type AssistantMessageLike,
  type TurnToolView,
  type TurnView,
} from '@/lib/assistant/turn-view'

function visibleTools(tools: TurnToolView[]): TurnToolView[] {
  return tools.filter(
    (tool) =>
      !isConsoleToolName(tool.name) && !isClarifyToolName(tool.name),
  )
}

/** Avoid duplicate Sentry events when the same assistant error remounts. */
const reportedAssistantErrors = new Set<string>()

function formatToolPayload(value: unknown): string | null {
  if (value === undefined || value === null || value === '') return null
  if (typeof value === 'string') return value
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return String(value)
  }
}

function ToolCallCard({
  tool,
  showAgentBadge = true,
}: {
  tool: TurnToolView
  showAgentBadge?: boolean
}) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const status = tool.status?.toLowerCase() ?? 'running'
  const isRunning = status === 'running' || status === 'queued'
  const isError = status === 'error' || status === 'failed' || !!tool.errorMessage
  const inputText = formatToolPayload(tool.input)
  const outputText = formatToolPayload(tool.output ?? tool.errorMessage)
  const visualSites = useMemo(
    () => getToolVisualSites(tool),
    [tool.input, tool.name, tool.output],
  )

  return (
    <Collapsible open={open} onOpenChange={setOpen}>
      <div className="rounded-md border border-border bg-muted/20">
        <div className="flex w-full items-center gap-2 px-3 py-2">
          <CollapsibleTrigger asChild>
            <button
              type="button"
              className="flex min-w-0 flex-1 items-center gap-2 text-start"
            >
              {isRunning ? (
                <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground" />
              ) : isError ? (
                <AlertCircle className="h-3.5 w-3.5 shrink-0 text-destructive" />
              ) : (
                <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-green-600" />
              )}
              <Wrench className="h-3.5 w-3.5 shrink-0 fill-current text-muted-foreground" />
              <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
                {tool.name}
              </span>
            </button>
          </CollapsibleTrigger>
          {visualSites && visualSites.length > 0 ? (
            <ToolSiteFaviconStack sites={visualSites} />
          ) : null}
          {showAgentBadge && tool.agent ? (
            <Badge variant="info" className="text-[10px] shrink-0">
              {t(getAssistantAgentLabel(tool.agent))}
            </Badge>
          ) : null}
          <CollapsibleTrigger asChild>
            <button
              type="button"
              className="flex h-5 w-5 shrink-0 items-center justify-center text-muted-foreground"
              aria-label={tool.name}
            >
              <ChevronDown
                className={cn(
                  'h-3 w-3 transition-transform',
                  open && 'rotate-180',
                )}
              />
            </button>
          </CollapsibleTrigger>
        </div>
        <CollapsibleContent className="overflow-hidden data-[state=open]:overflow-visible">
          <div className="min-w-0 space-y-2.5 border-t border-border px-3 py-2.5">
            {inputText ? (
              <div className="min-w-0">
                <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t('Input')}
                </p>
                <pre className="max-h-[min(70dvh,36rem)] overflow-x-auto overflow-y-auto whitespace-pre-wrap break-words rounded bg-background/60 p-2.5 text-[12px] leading-relaxed text-foreground">
                  {inputText}
                </pre>
              </div>
            ) : null}
            {outputText ? (
              <div className="min-w-0">
                <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {isError ? t('Error') : t('Output')}
                </p>
                <pre className="max-h-[min(70dvh,36rem)] overflow-x-auto overflow-y-auto whitespace-pre-wrap break-words rounded bg-background/60 p-2.5 text-[12px] leading-relaxed text-foreground">
                  {outputText}
                </pre>
              </div>
            ) : isRunning ? (
              <p className="text-[12px] text-muted-foreground">{t('Running...')}</p>
            ) : null}
          </div>
        </CollapsibleContent>
      </div>
    </Collapsible>
  )
}

function SubagentSection({
  turn,
  agent,
  open,
  summary,
  failed,
  toolCallCount,
}: {
  turn: TurnView
  agent: string
  open: boolean
  summary?: string
  failed?: boolean
  toolCallCount?: number
}) {
  const t = useT()
  const [expanded, setExpanded] = useState(open)
  const tools = visibleTools(toolsForAgent(turn, agent))
  const hasBody = !!summary || tools.length > 0 || open
  const displayToolCallCount =
    typeof toolCallCount === 'number'
      ? Math.min(toolCallCount, tools.length || toolCallCount)
      : tools.length || undefined

  // Follow the live turn: expand while the agent is working, collapse when it ends
  // so we never flash an empty "No tool calls" body.
  useEffect(() => {
    setExpanded(open)
  }, [open])

  return (
    <Collapsible
      open={expanded && hasBody}
      onOpenChange={(next) => {
        if (!hasBody && next) return
        setExpanded(next)
      }}
    >
      <div className="rounded-md border border-border bg-card/40">
        <CollapsibleTrigger asChild>
          <button
            type="button"
            className="flex w-full items-center gap-2 px-3 py-2 text-start"
            disabled={!hasBody && !open}
          >
            {open ? (
              <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground" />
            ) : failed ? (
              <AlertCircle className="h-3.5 w-3.5 shrink-0 text-destructive" />
            ) : (
              <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-green-600" />
            )}
            <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
              {t(getAssistantAgentLabel(agent))}
            </span>
            <Badge variant="info" className="text-[10px] shrink-0">
              {t('Subagent')}
            </Badge>
            {typeof displayToolCallCount === 'number' &&
            displayToolCallCount > 0 ? (
              <span className="text-[11px] text-muted-foreground">
                {displayToolCallCount}{' '}
                {displayToolCallCount === 1 ? t('tool call') : t('tool calls')}
              </span>
            ) : null}
            {hasBody ? (
              <ChevronDown
                className={cn(
                  'h-3 w-3 shrink-0 text-muted-foreground transition-transform',
                  expanded && 'rotate-180',
                )}
              />
            ) : null}
          </button>
        </CollapsibleTrigger>
        {hasBody ? (
          <CollapsibleContent>
            <div className="space-y-2 border-t border-border px-3 py-2.5">
              {summary ? (
                <p className="text-[12px] leading-relaxed text-muted-foreground">
                  {summary}
                </p>
              ) : null}
              {tools.length > 0 ? (
                <div className="space-y-2">
                  {tools.map((tool) => (
                    <ToolCallCard
                      key={
                        tool.toolCallId || tool.id || `${tool.agent}-${tool.name}`
                      }
                      tool={tool}
                      showAgentBadge={false}
                    />
                  ))}
                </div>
              ) : open ? (
                <p className="text-[12px] text-muted-foreground">
                  {t('Working...')}
                </p>
              ) : null}
            </div>
          </CollapsibleContent>
        ) : null}
      </div>
    </Collapsible>
  )
}

export function AgentTurnActivity({
  message,
}: {
  message: AssistantMessageLike
}) {
  const t = useT()
  const turn = useMemo(() => buildTurnView(message), [message])
  const [copiedError, setCopiedError] = useState(false)
  const inFlight = isAssistantMessageInFlight(message.status)
  const orphanTools = visibleTools(unscopedTools(turn))
  const routeAgent = turn.route?.agent
  const showRoute =
    !!routeAgent && routeAgent !== 'FINISH' && routeAgent !== 'supervisor'
  const hasAnswer =
    !!turn.contentText.trim() || !!turn.answeringAgent
  // Same spinner row as "Routing request…" - use Thinking… until the server
  // status label / tools / answer take over.
  const showStatusLabel =
    inFlight &&
    !hasAnswer &&
    (!!turn.statusLabel ||
      (!showRoute && turn.agents.length === 0 && orphanTools.length === 0))
  const statusText = turn.statusLabel || t('Thinking...')
  const hasActivity =
    showStatusLabel ||
    showRoute ||
    turn.agents.length > 0 ||
    orphanTools.length > 0 ||
    !!turn.error

  useEffect(() => {
    if (!turn.error) return
    const fingerprint = `${turn.messageId}:${turn.error}`
    if (reportedAssistantErrors.has(fingerprint)) return
    reportedAssistantErrors.add(fingerprint)

    captureExceptionWithContext(new Error(turn.error), {
      source: 'assistant-turn-error',
      messageId: turn.messageId,
      messageStatus: message.status,
    })
  }, [message.status, turn.error, turn.messageId])

  if (!hasActivity) return null

  const handleCopyError = async () => {
    if (!turn.error) return
    try {
      await navigator.clipboard.writeText(turn.error)
      setCopiedError(true)
      toast.success(t('Error details copied to clipboard'))
      window.setTimeout(() => setCopiedError(false), 2000)
    } catch {
      toast.error(t('Failed to copy'))
    }
  }

  return (
    <div className="mb-3 space-y-2">
      {showStatusLabel ? (
        <div className="flex items-center gap-2 text-[12px] text-muted-foreground">
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
          <span>{statusText}</span>
        </div>
      ) : null}

      {showRoute || turn.route?.reason ? (
        <div className="flex flex-wrap items-center gap-2">
          {showRoute ? (
            <Badge variant="info" className="text-[10px] shrink-0 gap-1">
              <Route className="h-2.5 w-2.5" />
              {t('Routed to')} {t(getAssistantAgentLabel(routeAgent))}
            </Badge>
          ) : null}
          {turn.route?.reason ? (
            <span className="text-[12px] leading-relaxed text-muted-foreground">
              {turn.route.reason}
            </span>
          ) : null}
        </div>
      ) : null}

      {turn.agents.map((section) => (
        <SubagentSection
          key={`${turn.messageId}-${section.agent}-${section.summary ?? ''}`}
          turn={turn}
          agent={section.agent}
          open={section.open}
          summary={section.summary}
          failed={section.failed}
          toolCallCount={section.toolCallCount}
        />
      ))}

      {orphanTools.length > 0 ? (
        <div className="space-y-2">
          {orphanTools.map((tool) => (
            <ToolCallCard
              key={tool.toolCallId || tool.id || `${tool.agent}-${tool.name}`}
              tool={tool}
            />
          ))}
        </div>
      ) : null}

      {turn.error ? (
        <div className="relative flex items-start gap-2 rounded-md border border-border bg-muted/20 px-3 py-2 pe-9 text-[13px] leading-relaxed text-foreground">
          <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-destructive" />
          <span className="min-w-0 flex-1 break-words">{turn.error}</span>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="absolute end-1.5 top-1.5 h-6 w-6 p-0 text-muted-foreground hover:text-foreground"
            onClick={() => void handleCopyError()}
            aria-label={t('Copy error details')}
          >
            {copiedError ? (
              <Check className="h-3 w-3 text-emerald-500" />
            ) : (
              <Copy className="h-3 w-3" />
            )}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
