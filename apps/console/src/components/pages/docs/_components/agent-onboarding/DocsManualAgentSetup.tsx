'use client'

import { ArrowUpRight } from 'lucide-react'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { HorizontalScrollFade } from '@/components/global/shared/HorizontalScrollFade'
import { Button } from '@/components/ui/button'
import { useAnalytics } from '@/hooks/use-analytics'
import { ANALYTICS_ACTIONS } from '@/lib/analytics-actions'
import {
  DOCS_ONBOARDING_AGENTS,
  type DocsOnboardingAgent,
  type DocsOnboardingAgentId,
} from '@/lib/docs/agent-onboarding'
import { cn } from '@/lib/utils'
import { DocsAgentIcon } from './DocsAgentIcon'

/** Step-by-step setup without the prompt, one tab per agent. */
export function DocsManualAgentSetup({
  agentId,
  agent,
  onAgentChange,
}: {
  agentId: DocsOnboardingAgentId
  agent: DocsOnboardingAgent
  onAgentChange: (id: DocsOnboardingAgentId) => void
}) {
  const { track } = useAnalytics()

  const selectAgent = (id: DocsOnboardingAgentId) => {
    if (id !== agentId) {
      track(ANALYTICS_ACTIONS['docs-agent-select'], {
        agent: id,
        placement: 'quick-start',
        section: 'manual-setup',
      })
    }
    onAgentChange(id)
  }

  return (
    <div className="overflow-hidden rounded-xl border border-border">
      <div className="border-b border-border px-2 py-1.5">
        <HorizontalScrollFade
          className="min-w-0"
          viewportClassName="flex"
          fadeFromClassName="from-background"
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
      </div>

      <ol className="divide-y divide-border">
        {agent.manualSteps.map((step, index) => (
          <li
            key={`${agent.id}-${step.title}`}
            className="flex gap-3 px-4 py-4 @[480px]:px-5"
          >
            <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border border-border font-mono text-[11px] text-muted-foreground">
              {index + 1}
            </span>
            <div className="min-w-0 flex-1 space-y-2.5">
              <div>
                <p className="text-[13px] font-medium text-foreground">
                  {step.title}
                </p>
                {step.description ? (
                  <p className="mt-1 text-[13px] leading-5 text-muted-foreground">
                    {step.description}
                  </p>
                ) : null}
              </div>
              {step.link ? (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 gap-1.5 text-[12px]"
                  asChild
                >
                  <a
                    href={step.link.href}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    {step.link.label}
                    <ArrowUpRight className="size-3.5" aria-hidden />
                  </a>
                </Button>
              ) : null}
              {step.code ? (
                <div data-analytics-track="manual">
                  <ConnectCodeExample
                    code={step.code}
                    language={step.language ?? 'bash'}
                    onCopied={() =>
                      track(ANALYTICS_ACTIONS['docs-manual-setup-copy'], {
                        agent: agent.id,
                        step: index + 1,
                      })
                    }
                  />
                </div>
              ) : null}
            </div>
          </li>
        ))}
      </ol>
    </div>
  )
}
