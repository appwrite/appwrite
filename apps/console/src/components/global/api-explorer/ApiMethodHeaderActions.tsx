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
import { copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type ApiMethodHeaderActionsProps = {
  getPageUrl: () => string
  getMethodMarkdown: (pageUrl: string) => string
  className?: string
}

export function ApiMethodHeaderActions({
  getPageUrl,
  getMethodMarkdown,
  className,
}: ApiMethodHeaderActionsProps) {
  const t = useT()
  const [copiedPage, setCopiedPage] = useState(false)
  const [copiedLink, setCopiedLink] = useState(false)
  const iconButtonClass = 'h-7 w-7 shrink-0 p-0 text-muted-foreground'

  const stopPropagation = (event: MouseEvent) => {
    event.preventDefault()
    event.stopPropagation()
  }

  const handleCopyPage = async (event: MouseEvent<HTMLButtonElement>) => {
    stopPropagation(event)

    const pageUrl = getPageUrl()
    const markdown = getMethodMarkdown(pageUrl)
    const copied = await copyToClipboard('Page', markdown, {
      showToast: false,
    })
    if (!copied) return
    setCopiedPage(true)
    setTimeout(() => setCopiedPage(false), 2000)
  }

  const handleCopyLink = async (event: MouseEvent<HTMLButtonElement>) => {
    stopPropagation(event)
    const copied = await copyToClipboard('Link', getPageUrl(), {
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
              aria-label={t('Copy link')}
            >
              {copiedLink ? (
                <Check className="h-3.5 w-3.5 text-green-600" strokeWidth={3} />
              ) : (
                <Link2 className="h-3.5 w-3.5" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            <p>{copiedLink ? t('Link copied') : t('Copy link')}</p>
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
              aria-label={t('Copy page')}
            >
              {copiedPage ? (
                <Check className="h-3.5 w-3.5 text-green-600" strokeWidth={3} />
              ) : (
                <Copy className="h-3.5 w-3.5" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            <p>{copiedPage ? t('Copied') : t('Copy page')}</p>
          </TooltipContent>
        </Tooltip>
      </div>
    </TooltipProvider>
  )
}
