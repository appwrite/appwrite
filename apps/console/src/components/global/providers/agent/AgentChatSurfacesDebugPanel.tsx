import { useMemo, useState } from 'react'
import { ChevronDown, ChevronUp } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import {
  CLARIFY_PROTOCOL_ID,
  type ClarifyEnvelope,
} from '@/lib/assistant/clarify-protocol'
import {
  CONSOLE_PROTOCOL_ID,
  type ConsoleEnvelope,
} from '@/lib/assistant/console-protocol'
import type { AssistantMessageLike } from '@/lib/assistant/turn-view'
import { AgentClarifySurfaces } from './AgentClarifySurfaces'
import { AgentConsoleSurfaces } from './AgentConsoleSurfaces'

type DemoId =
  | 'clarify-choice'
  | 'clarify-confirm'
  | 'clarify-text'
  | 'clarify-mixed'
  | 'console-resource'
  | 'console-list'
  | 'console-chart'
  | 'console-ctas'

const DEMO_BUTTONS: Array<{ id: DemoId; label: string }> = [
  { id: 'clarify-choice', label: 'Clarify choice' },
  { id: 'clarify-confirm', label: 'Clarify confirm' },
  { id: 'clarify-text', label: 'Clarify text' },
  { id: 'clarify-mixed', label: 'Clarify mixed' },
  { id: 'console-resource', label: 'Resource card' },
  { id: 'console-list', label: 'Resource list' },
  { id: 'console-chart', label: 'Chart' },
  { id: 'console-ctas', label: 'CTAs' },
]

function debugButtonClass(isActive: boolean) {
  return cn(
    'h-7 px-2 text-[11px]',
    isActive
      ? 'border-purple-600 bg-purple-600 text-white hover:bg-purple-600/90 dark:border-purple-500 dark:bg-purple-500 dark:hover:bg-purple-500/90'
      : 'border-purple-500/40 text-purple-600 hover:bg-purple-500/10 hover:text-purple-700 dark:border-purple-400/40 dark:text-purple-400 dark:hover:bg-purple-500/15 dark:hover:text-purple-300',
  )
}

function buildDemoMessage(input: {
  id: string
  contentText: string
  toolName: 'clarify' | 'console'
  envelope: ClarifyEnvelope | ConsoleEnvelope
}): AssistantMessageLike {
  const output = JSON.stringify(input.envelope)
  const toolCallId = `${input.id}-call`
  return {
    $id: input.id,
    status: 'completed',
    contentText: input.contentText,
    role: 'assistant',
    routeAgent: '',
    routeNext: '',
    routeReason: '',
    tools: [],
    // Timeline alone is enough for buildTurnView / surface collectors.
    timeline: [
      {
        type: 'tool_start',
        tool: input.toolName,
        toolCallId,
        agent: 'platform',
      },
      {
        type: 'tool_end',
        tool: input.toolName,
        toolCallId,
        agent: 'platform',
        output,
      },
    ],
  }
}

function chartDemoEnvelope(): ConsoleEnvelope {
  const end = new Date()
  const start = new Date(end.getTime() - 24 * 60 * 60 * 1000)
  const points = Array.from({ length: 12 }, (_, index) => {
    const time = new Date(
      start.getTime() + index * 2 * 60 * 60 * 1000,
    ).toISOString()
    return { time, value: 20 + ((index * 17) % 40) + (index % 3) * 5 }
  })
  return {
    protocol: CONSOLE_PROTOCOL_ID,
    actions: [
      {
        type: 'chart',
        title: 'Requests (last 24 hours)',
        unitLabel: 'requests',
        interval: '2h',
        startAt: start.toISOString(),
        endAt: end.toISOString(),
        changePercent: 12.4,
        metrics: [{ metric: 'network.requests', points }],
      },
    ],
  }
}

function demoMessageFor(id: DemoId): AssistantMessageLike {
  switch (id) {
    case 'clarify-choice':
      return buildDemoMessage({
        id: 'debug-clarify-choice',
        contentText: 'Need one detail before I continue.',
        toolName: 'clarify',
        envelope: {
          protocol: CLARIFY_PROTOCOL_ID,
          title: 'Which bucket?',
          prompts: [
            {
              id: 'bucket',
              kind: 'choice',
              question: 'Which bucket should I delete?',
              options: [
                {
                  id: 'avatars',
                  label: 'avatars',
                  description: '64 files',
                },
                {
                  id: 'uploads',
                  label: 'uploads',
                  description: '12 files',
                },
                {
                  id: 'backups',
                  label: 'backups',
                  description: '3 files',
                },
              ],
            },
          ],
        },
      })
    case 'clarify-confirm':
      return buildDemoMessage({
        id: 'debug-clarify-confirm',
        contentText: 'Confirm before I continue.',
        toolName: 'clarify',
        envelope: {
          protocol: CLARIFY_PROTOCOL_ID,
          prompts: [
            {
              id: 'confirm_delete',
              kind: 'confirm',
              question: 'Delete bucket avatars and all of its files?',
              confirmLabel: 'Delete',
              cancelLabel: 'Keep it',
              danger: true,
              hint: 'This cannot be undone.',
            },
          ],
        },
      })
    case 'clarify-text':
      return buildDemoMessage({
        id: 'debug-clarify-text',
        contentText: 'What should I name it?',
        toolName: 'clarify',
        envelope: {
          protocol: CLARIFY_PROTOCOL_ID,
          title: 'Name the database',
          prompts: [
            {
              id: 'name',
              kind: 'text',
              question: 'Database name',
              placeholder: 'main',
              defaultValue: 'main',
            },
            {
              id: 'notes',
              kind: 'text',
              question: 'Optional notes',
              placeholder: 'Anything I should know?',
              required: false,
              multiline: true,
            },
          ],
        },
      })
    case 'clarify-mixed':
      return buildDemoMessage({
        id: 'debug-clarify-mixed',
        contentText: 'A few details before I create this.',
        toolName: 'clarify',
        envelope: {
          protocol: CLARIFY_PROTOCOL_ID,
          title: 'Create database',
          prompts: [
            {
              id: 'name',
              kind: 'text',
              question: 'Database name',
              placeholder: 'main',
            },
            {
              id: 'id_mode',
              kind: 'choice',
              question: 'Database ID',
              options: [
                { id: 'unique', label: 'Auto-generate (unique())' },
                { id: 'custom', label: "I'll provide a custom ID" },
              ],
            },
            {
              id: 'confirm_create',
              kind: 'confirm',
              question: 'Create the database with these settings?',
              confirmLabel: 'Create',
              cancelLabel: 'Cancel',
            },
          ],
        },
      })
    case 'console-resource':
      return buildDemoMessage({
        id: 'debug-console-resource',
        contentText: 'Created a storage bucket.',
        toolName: 'console',
        envelope: {
          protocol: CONSOLE_PROTOCOL_ID,
          actions: [
            {
              type: 'resource',
              mutation: 'create',
              resourceType: 'bucket',
              resourceId: 'avatars',
              title: 'avatars',
              subtitle: 'Storage bucket',
              status: 'enabled',
              href: '/projects/demo/storage/avatars',
              metadata: [
                { label: 'Files', value: '64' },
                { label: 'Region', value: 'fra' },
              ],
            },
          ],
        },
      })
    case 'console-list':
      return buildDemoMessage({
        id: 'debug-console-list',
        contentText: 'Here are matching users.',
        toolName: 'console',
        envelope: {
          protocol: CONSOLE_PROTOCOL_ID,
          actions: [
            {
              type: 'resource_list',
              resourceType: 'user',
              title: 'Users',
              description: 'Recently active accounts',
              total: 3,
              listHref: '/projects/demo/auth',
              items: [
                {
                  resourceId: 'user_demo_1',
                  title: 'Ada Lovelace',
                  subtitle: 'ada@example.com',
                  status: 'verified',
                  fields: {
                    email: 'ada@example.com',
                    status: 'verified',
                  },
                },
                {
                  resourceId: 'user_demo_2',
                  title: 'Grace Hopper',
                  subtitle: 'grace@example.com',
                  status: 'unverified',
                  fields: {
                    email: 'grace@example.com',
                    status: 'unverified',
                  },
                },
                {
                  resourceId: 'user_demo_3',
                  title: 'Alan Turing',
                  subtitle: 'alan@example.com',
                  status: 'blocked',
                  fields: {
                    email: 'alan@example.com',
                    status: 'blocked',
                  },
                },
              ],
            },
          ],
        },
      })
    case 'console-chart':
      return buildDemoMessage({
        id: 'debug-console-chart',
        contentText: 'Request volume over the last day.',
        toolName: 'console',
        envelope: chartDemoEnvelope(),
      })
    case 'console-ctas':
      return buildDemoMessage({
        id: 'debug-console-ctas',
        contentText: 'A few shortcuts you can try.',
        toolName: 'console',
        envelope: {
          protocol: CONSOLE_PROTOCOL_ID,
          actions: [
            {
              type: 'navigate',
              path: '/projects/demo/storage',
            },
            {
              type: 'open_create',
              resource: 'bucket',
              projectId: 'demo',
            },
            {
              type: 'open_dialog',
              dialog: 'shortcuts',
            },
            {
              type: 'show_pane',
              content: 'docs',
            },
            {
              type: 'toggle_terminal',
            },
          ],
        },
      })
  }
}

function DemoPreview({
  demoId,
  projectId,
  organizationId,
}: {
  demoId: DemoId
  projectId?: string | null
  organizationId?: string | null
}) {
  const message = useMemo(() => demoMessageFor(demoId), [demoId])
  const isClarify = demoId.startsWith('clarify-')
  const label =
    DEMO_BUTTONS.find((button) => button.id === demoId)?.label ?? demoId

  return (
    <div className="space-y-2 rounded-md border border-purple-500/20 bg-background/80 p-2.5">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
        {label}
      </p>
      {message.contentText ? (
        <p className="text-[13px] leading-relaxed text-foreground">
          {message.contentText}
        </p>
      ) : null}
      {isClarify ? (
        <AgentClarifySurfaces
          message={message}
          interactive
          onSubmitAnswers={(answersJson) => {
            toast.message('Clarify answers (debug)', {
              description: answersJson,
            })
          }}
        />
      ) : (
        <AgentConsoleSurfaces
          message={message}
          projectId={projectId}
          organizationId={organizationId}
        />
      )}
    </div>
  )
}

export function AgentChatSurfacesDebugPanel({
  projectId,
  organizationId,
}: {
  projectId?: string | null
  organizationId?: string | null
}) {
  const [expanded, setExpanded] = useState(false)
  const [activeDemos, setActiveDemos] = useState<DemoId[]>([])

  const toggleDemo = (id: DemoId) => {
    setActiveDemos((current) =>
      current.includes(id)
        ? current.filter((item) => item !== id)
        : [...current, id],
    )
  }

  const allActive = activeDemos.length === DEMO_BUTTONS.length

  return (
    <div className="rounded-lg border border-purple-500/25 bg-purple-500/5">
      <button
        type="button"
        onClick={() => setExpanded((value) => !value)}
        aria-expanded={expanded}
        className="flex w-full items-center gap-2 px-2.5 py-2 text-start transition-colors hover:bg-purple-500/10"
      >
        <span className="min-w-0 flex-1 text-[10px] font-semibold uppercase tracking-wider text-purple-600/80 dark:text-purple-400/80">
          Chat surfaces demo
        </span>
        {!expanded ? (
          <span className="truncate text-[10px] tabular-nums text-purple-600/70 dark:text-purple-400/70">
            {activeDemos.length > 0
              ? `${activeDemos.length} active`
              : 'clarify · console'}
          </span>
        ) : null}
        {expanded ? (
          <ChevronUp className="h-3.5 w-3.5 shrink-0 text-purple-600/80 dark:text-purple-400/80" />
        ) : (
          <ChevronDown className="h-3.5 w-3.5 shrink-0 text-purple-600/80 dark:text-purple-400/80" />
        )}
      </button>
      {expanded ? (
        <div className="space-y-2 border-t border-purple-500/20 p-2.5 pt-2">
          <div className="flex flex-wrap gap-1.5">
            {DEMO_BUTTONS.map(({ id, label }) => (
              <Button
                key={id}
                type="button"
                size="sm"
                variant="outline"
                className={debugButtonClass(activeDemos.includes(id))}
                onClick={() => toggleDemo(id)}
              >
                {label}
              </Button>
            ))}
            <Button
              type="button"
              size="sm"
              variant="outline"
              className={debugButtonClass(allActive)}
              onClick={() =>
                setActiveDemos(
                  allActive ? [] : DEMO_BUTTONS.map((button) => button.id),
                )
              }
            >
              All
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              className={debugButtonClass(false)}
              onClick={() => setActiveDemos([])}
              disabled={activeDemos.length === 0}
            >
              Clear
            </Button>
          </div>
          {activeDemos.length > 0 ? (
            <div className="max-h-[min(50dvh,420px)] space-y-2 overflow-y-auto">
              {activeDemos.map((demoId) => (
                <DemoPreview
                  key={demoId}
                  demoId={demoId}
                  projectId={projectId}
                  organizationId={organizationId}
                />
              ))}
            </div>
          ) : (
            <p className="text-[11px] text-purple-600/70 dark:text-purple-400/70">
              Toggle a surface to preview clarify prompts and console cards
              without sending a message.
            </p>
          )}
        </div>
      ) : null}
    </div>
  )
}
