'use client'

import { BrainCircuit, Check, ChevronDown, Copy, ExternalLink } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { Button } from '@/components/ui/button'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  generateAIChatDeeplink,
  getAIChatIDEs,
  openAIChatDeeplink,
} from '@/lib/config/ide'
import { cn } from '@/lib/utils'

type DocsPromptBannerProps = {
  prompt: string
  /** When false, hides the in-card prompt preview (e.g. prompt-only pages). */
  showPromptPreview?: boolean
  className?: string
}

export function DocsPromptBanner({
  prompt,
  showPromptPreview = true,
  className,
}: DocsPromptBannerProps) {
  const [copied, setCopied] = useState(false)
  const aiChatIDEs = getAIChatIDEs()
  const trimmedPrompt = prompt.trim()

  const handleCopyPrompt = async () => {
    if (!trimmedPrompt) return
    try {
      await navigator.clipboard.writeText(trimmedPrompt)
      setCopied(true)
      toast.success('Prompt copied to clipboard')
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      toast.error('Failed to copy prompt')
    }
  }

  const handleOpenInIDE = (ide: (typeof aiChatIDEs)[number]) => {
    const deeplink = generateAIChatDeeplink(ide, trimmedPrompt)
    if (deeplink) {
      openAIChatDeeplink(deeplink)
      toast.success(`Opening ${ide.name}...`)
    }
  }

  return (
    <div
      className={cn(
        'not-prose -mt-4 mb-8 overflow-hidden rounded-xl border border-border bg-card/50',
        className,
      )}
      aria-label="Quick start agent prompt"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Quick start agent prompt
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          Use this pre-built prompt with Cursor, Claude, or another coding agent to set up
          Appwrite for this guide. Copy it, preview it below, or open it directly in a
          supported tool.
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="flex flex-nowrap items-center gap-2 px-6 py-4">
        <Button
          type="button"
          size="sm"
          className="h-9 shrink-0 gap-1.5 text-[13px]"
          disabled={!trimmedPrompt}
          onClick={() => void handleCopyPrompt()}
          aria-label="Copy prompt"
        >
          <span className="relative size-3.5 shrink-0" aria-hidden>
            <Copy
              className={cn(
                'size-3.5 transition-opacity',
                copied ? 'opacity-0' : 'opacity-100',
              )}
            />
            <Check
              className={cn(
                'absolute inset-0 size-3.5 text-green-600 transition-opacity',
                copied ? 'opacity-100' : 'opacity-0',
              )}
            />
          </span>
          Copy prompt
        </Button>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 shrink-0 gap-1.5 text-[13px]"
              disabled={!trimmedPrompt}
            >
              <BrainCircuit className="size-3.5" />
              Open in tool
              <ChevronDown className="size-3.5" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="start" className="z-[10050] min-w-[200px]">
            {aiChatIDEs.map((ide) => (
              <DropdownMenuItem key={ide.id} onClick={() => handleOpenInIDE(ide)}>
                <img src={ide.iconPath} alt="" className="size-4" />
                <span className="ms-2">Prompt {ide.name}</span>
                <ExternalLink
                  className="ms-auto size-2.5 shrink-0 text-muted-foreground/30"
                  strokeWidth={1.25}
                />
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {showPromptPreview ? (
        <Collapsible defaultOpen={false}>
          <CollapsibleTrigger className="group flex w-full cursor-pointer items-center justify-between gap-3 border-t border-border px-6 py-3 text-start transition-colors hover:bg-muted/30 data-[state=open]:bg-muted/20">
            <span className="text-[13px] font-medium text-foreground">View prompt</span>
            <ChevronDown className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
          </CollapsibleTrigger>
          <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-accordion-up data-[state=open]:animate-accordion-down">
            <div className="border-t border-border px-6 py-4">
              <ConnectCodeExample
                code={trimmedPrompt}
                language="markdown"
                fixedHeight="min(40dvh, 420px)"
              />
            </div>
          </CollapsibleContent>
        </Collapsible>
      ) : null}
    </div>
  )
}
