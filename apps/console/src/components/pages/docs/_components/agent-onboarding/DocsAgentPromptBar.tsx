'use client'

import { useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { toast } from 'sonner'
import { HorizontalScrollFade } from '@/components/global/shared/HorizontalScrollFade'
import { DocsProjectPicker } from '@/components/pages/docs/project-context/DocsProjectPicker'
import { useDocsProject } from '@/components/pages/docs/project-context/DocsProjectContext'
import { Button } from '@/components/ui/button'
import { useAnalytics } from '@/hooks/use-analytics'
import { ANALYTICS_ACTIONS } from '@/lib/analytics-actions'
import {
  generateAIChatDeeplink,
  getIDEById,
  openAIChatDeeplink,
} from '@/lib/config/ide'
import {
  DOCS_ONBOARDING_AGENTS,
  getDocsOnboardingAgent,
  type DocsOnboardingAgentId,
  type DocsOnboardingPlacement,
} from '@/lib/docs/agent-onboarding'
import { cn } from '@/lib/utils'
import { DocsAgentIcon } from './DocsAgentIcon'
import {
  useDocsAgentPrompt,
  useDocsOnboardingAgent,
} from './useDocsAgentOnboarding'

function displayUrl(url: string): string {
  return url.replace(/^https?:\/\//, '')
}

function useCopyPrompt(
  prompt: string,
  onResult: (result: 'copied' | 'failed') => void,
) {
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(prompt)
      onResult('copied')
      setCopied(true)
      toast.success('Prompt copied. Paste it into your coding agent.')
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      onResult('failed')
      toast.error('Failed to copy prompt')
    }
  }

  return { copied, copy }
}

function PromptText({
  intro,
  setupUrl,
  prompt,
  className,
}: {
  intro: string
  setupUrl: string
  prompt: string
  className?: string
}) {
  return (
    <p
      className={cn('min-w-0 break-words font-mono text-foreground', className)}
      title={prompt}
    >
      {intro}{' '}
      <span className="text-[var(--brand-cta)]">{displayUrl(setupUrl)}</span>
    </p>
  )
}

type DocsAgentPromptBarProps = {
  /** Where the card sits, sent with its analytics events. */
  placement: DocsOnboardingPlacement
  /** `compact` is a single row without agent tabs, for narrow spots like the Console panel. */
  size?: 'default' | 'compact'
  /** Controlled agent, for pages that share the choice with other agent UI. */
  agentId?: DocsOnboardingAgentId
  onAgentChange?: (id: DocsOnboardingAgentId) => void
  className?: string
}

/**
 * The setup prompt in a card: pick an agent, then copy the prompt or open it
 * in the agent. The full prompt (with project details) is what gets copied.
 */
export function DocsAgentPromptBar({
  placement,
  size = 'default',
  agentId: controlledAgentId,
  onAgentChange,
  className,
}: DocsAgentPromptBarProps) {
  const { prompt, project, intro, setupUrl } = useDocsAgentPrompt()
  const { isAuthenticated } = useDocsProject()
  const { track } = useAnalytics()
  const stored = useDocsOnboardingAgent()

  const agentId = controlledAgentId ?? stored.agentId
  const agent = getDocsOnboardingAgent(agentId)
  const setAgentId = onAgentChange ?? stored.setAgentId

  // The compact card has no agent tabs, so its events carry no agent.
  const eventProps = {
    agent: size === 'compact' ? undefined : agent.id,
    placement,
    has_project: !!project,
  }
  const { copied, copy } = useCopyPrompt(prompt, (result) =>
    track(ANALYTICS_ACTIONS['docs-agent-prompt-copy'], {
      ...eventProps,
      result,
    }),
  )

  const selectAgent = (id: DocsOnboardingAgentId) => {
    if (id !== agentId) {
      track(ANALYTICS_ACTIONS['docs-agent-select'], { agent: id, placement })
    }
    setAgentId(id)
  }

  const projectPicker = isAuthenticated ? (
    <DocsProjectPicker align="end" className="h-7" />
  ) : null

  if (size === 'compact') {
    return (
      <div className={cn('min-w-0 space-y-2.5', className)}>
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-card p-2 ps-4 @[560px]:flex-row @[560px]:items-center">
          <PromptText
            intro={intro}
            setupUrl={setupUrl}
            prompt={prompt}
            className="flex-1 pt-2 text-[13px] leading-6 @[560px]:py-1"
          />
          <Button
            type="button"
            size="sm"
            className="h-8 shrink-0 text-[13px]"
            onClick={() => void copy()}
            data-analytics-track="manual"
          >
            {copied ? (
              <Check className="size-3.5" aria-hidden />
            ) : (
              <Copy className="size-3.5" aria-hidden />
            )}
            Copy prompt
          </Button>
        </div>
        {projectPicker ? (
          <div className="flex items-center gap-1.5 ps-1">
            <span className="text-[12px] text-muted-foreground">Project</span>
            {projectPicker}
          </div>
        ) : null}
      </div>
    )
  }

  const ide = agent.deeplinkIdeId ? getIDEById(agent.deeplinkIdeId) : undefined
  const deeplink = ide ? generateAIChatDeeplink(ide, prompt) : null

  const handleOpen = () => {
    if (!deeplink || !ide) return
    track(ANALYTICS_ACTIONS['docs-agent-prompt-open'], eventProps)
    openAIChatDeeplink(deeplink)
    toast.success(`Opening ${ide.name}...`)
  }

  return (
    <div
      className={cn(
        'flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card',
        className,
      )}
    >
      <div className="flex items-center gap-2 border-b border-border px-2 py-1.5">
        <HorizontalScrollFade
          className="min-w-0 flex-1"
          viewportClassName="flex"
          fadeFromClassName="from-card"
        >
          <div
            role="tablist"
            aria-label="Coding agent"
            className="flex w-max gap-1"
          >
            {DOCS_ONBOARDING_AGENTS.map((item) => {
              const selected = item.id === agentId
              return (
                <button
                  key={item.id}
                  type="button"
                  role="tab"
                  aria-selected={selected}
                  onClick={() => selectAgent(item.id)}
                  className={cn(
                    'inline-flex h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-[12px] font-medium transition-colors',
                    selected
                      ? 'bg-muted text-foreground'
                      : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                  )}
                  data-analytics-track="manual"
                >
                  <DocsAgentIcon agent={item} className="size-3.5" />
                  {item.name}
                </button>
              )
            })}
          </div>
        </HorizontalScrollFade>
        {projectPicker ? <div className="shrink-0">{projectPicker}</div> : null}
      </div>

      <div className="flex-1 px-4 py-5 @[480px]:px-5 @[480px]:py-6">
        <PromptText
          intro={intro}
          setupUrl={setupUrl}
          prompt={prompt}
          className="text-[13px] leading-6 @[480px]:text-[14px]"
        />
      </div>

      <div className="flex flex-col gap-3 border-t border-border bg-muted/30 px-4 py-3 @[560px]:flex-row @[560px]:items-center @[560px]:justify-between @[480px]:px-5">
        <p className="text-[12px] leading-5 text-muted-foreground">
          {agent.pasteHint}
        </p>
        <div className="flex shrink-0 items-center gap-2">
          <Button
            type="button"
            variant={deeplink ? 'outline' : 'default'}
            size="sm"
            className="h-8 text-[13px]"
            onClick={() => void copy()}
            data-analytics-track="manual"
          >
            {copied ? (
              <Check className="size-3.5" aria-hidden />
            ) : (
              <Copy className="size-3.5" aria-hidden />
            )}
            Copy prompt
          </Button>
          {deeplink && ide ? (
            <Button
              type="button"
              size="sm"
              className="h-8 text-[13px]"
              onClick={handleOpen}
              data-analytics-track="manual"
            >
              Open in {ide.name}
            </Button>
          ) : null}
        </div>
      </div>
    </div>
  )
}
