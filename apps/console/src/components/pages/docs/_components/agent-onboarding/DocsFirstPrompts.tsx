'use client'

import { useState } from 'react'
import {
  ArrowUpRight,
  Check,
  Copy,
  Database,
  FileText,
  Folder,
  Globe,
  Sparkles,
  Terminal,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { toast } from 'sonner'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  DOCS_AGENT_CAPABILITIES,
  DOCS_FIRST_PROMPTS,
  type DocsAgentCapability,
  type DocsFirstPrompt,
} from '@/lib/docs/agent-onboarding'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '../../DocsRouteLink'

const CARD_CLASS =
  'group flex h-full w-full flex-col rounded-xl border border-border bg-card/45 p-5 text-start transition-colors hover:bg-accent/15'

const GRID_CLASS =
  'grid grid-cols-1 gap-4 @[560px]:grid-cols-2 @[1080px]:grid-cols-4'

/** Same icons as the matching products in the docs sidebar. */
const PROMPT_ICONS: Record<DocsFirstPrompt['icon'], LucideIcon> = {
  auth: Users,
  table: Database,
  upload: Folder,
  deploy: Globe,
}

function FirstPromptCard({ item }: { item: DocsFirstPrompt }) {
  const [copied, setCopied] = useState(false)
  const Icon = PROMPT_ICONS[item.icon]

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(item.prompt)
      setCopied(true)
      toast.success('Prompt copied. Paste it into your agent.')
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error('Failed to copy prompt')
    }
  }

  return (
    <li>
      <button
        type="button"
        onClick={() => void handleCopy()}
        aria-label={`Copy prompt: ${item.prompt}`}
        className={cn(CARD_CLASS, 'cursor-pointer')}
        {...analyticsAttrs('docs-first-prompt-copy')}
      >
        <span className="flex items-center justify-between">
          <span className="flex size-8 items-center justify-center rounded-lg border border-border bg-muted/40">
            <Icon className="size-3.5 text-muted-foreground" aria-hidden />
          </span>
          <span
            className="flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors group-hover:bg-muted/60 group-hover:text-foreground"
            aria-hidden
          >
            {copied ? (
              <Check className="size-3.5" />
            ) : (
              <Copy className="size-3.5" />
            )}
          </span>
        </span>
        <span className="mt-4 text-[13px] font-medium text-foreground">
          {item.title}
        </span>
        <span className="mt-2 text-[13px] leading-5 text-muted-foreground">
          {item.prompt}
        </span>
      </button>
    </li>
  )
}

/** Follow-up prompts as cards. Clicking a card copies its prompt. */
export function DocsFirstPrompts({ className }: { className?: string }) {
  return (
    <ul className={cn(GRID_CLASS, className)}>
      {DOCS_FIRST_PROMPTS.map((item) => (
        <FirstPromptCard key={item.title} item={item} />
      ))}
    </ul>
  )
}

function CapabilityIcon({ icon }: { icon: DocsAgentCapability['icon'] }) {
  if (icon === 'mcp') return <McpIcon className="size-3.5" />
  const Icon: LucideIcon =
    icon === 'skills' ? Sparkles : icon === 'cli' ? Terminal : FileText
  return <Icon className="size-3.5" aria-hidden />
}

/** What the agent gets from setup, as links to each piece's docs. */
export function DocsAgentCapabilities({ className }: { className?: string }) {
  return (
    <ul className={cn(GRID_CLASS, className)}>
      {DOCS_AGENT_CAPABILITIES.map((item) => (
        <li key={item.title}>
          <DocsRouteLink href={item.href} className={CARD_CLASS}>
            <span className="flex items-center gap-2 text-foreground">
              <span className="text-muted-foreground">
                <CapabilityIcon icon={item.icon} />
              </span>
              <span className="text-[13px] font-medium">{item.title}</span>
              <ArrowUpRight
                className="ms-auto size-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                aria-hidden
              />
            </span>
            <span className="mt-2 text-[13px] leading-5 text-muted-foreground">
              {item.description}
            </span>
          </DocsRouteLink>
        </li>
      ))}
    </ul>
  )
}
