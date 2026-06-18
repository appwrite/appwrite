'use client'

import { useState, type MouseEvent } from 'react'
import { Check, Copy, Link2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { buildApiReferenceMethodMarkdown } from '@/lib/docs/references/method-markdown'
import type {
  ReferencePlatform,
  ReferenceVersion,
} from '@/lib/docs/references/constants'
import type { ApiReferenceMethod } from '@/lib/docs/references/types'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'

function buildMethodLink(methodId: string): string {
  return `${window.location.origin}${window.location.pathname}${window.location.search}#${methodId}`
}

type ApiReferenceMethodActionsProps = {
  method: ApiReferenceMethod
  version: ReferenceVersion
  platform: ReferencePlatform
  className?: string
}

export function ApiReferenceMethodActions({
  method,
  version,
  platform,
  className,
}: ApiReferenceMethodActionsProps) {
  const [copiedPage, setCopiedPage] = useState(false)
  const [copiedLink, setCopiedLink] = useState(false)
  const iconButtonClass = 'h-7 w-7 shrink-0 p-0 text-muted-foreground'

  const stopPropagation = (event: MouseEvent) => {
    event.preventDefault()
    event.stopPropagation()
  }

  const handleCopyPage = async (event: MouseEvent<HTMLButtonElement>) => {
    stopPropagation(event)

    const markdown = buildApiReferenceMethodMarkdown(method, version, platform, {
      pageUrl: buildMethodLink(method.id),
    })
    const copied = await copyToClipboard('Page', markdown, {
      showToast: false,
    })
    if (!copied) return
    setCopiedPage(true)
    setTimeout(() => setCopiedPage(false), 2000)
  }

  const handleCopyLink = async (event: MouseEvent<HTMLButtonElement>) => {
    stopPropagation(event)
    const copied = await copyToClipboard('Link', buildMethodLink(method.id), {
      showToast: false,
    })
    if (!copied) return
    setCopiedLink(true)
    setTimeout(() => setCopiedLink(false), 2000)
  }

  return (
    <TooltipProvider delayDuration={300}>
      <div
        className={cn('flex shrink-0 items-center gap-0.5', className)}
        onClick={stopPropagation}
      >
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className={iconButtonClass}
              onClick={handleCopyLink}
              aria-label="Copy link"
            >
              {copiedLink ? (
                <Check className="h-3.5 w-3.5 text-green-600" strokeWidth={3} />
              ) : (
                <Link2 className="h-3.5 w-3.5" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            <p>{copiedLink ? 'Link copied' : 'Copy link'}</p>
          </TooltipContent>
        </Tooltip>

        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className={iconButtonClass}
              onClick={handleCopyPage}
              aria-label="Copy page"
            >
              {copiedPage ? (
                <Check className="h-3.5 w-3.5 text-green-600" strokeWidth={3} />
              ) : (
                <Copy className="h-3.5 w-3.5" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            <p>{copiedPage ? 'Copied' : 'Copy page'}</p>
          </TooltipContent>
        </Tooltip>
      </div>
    </TooltipProvider>
  )
}
