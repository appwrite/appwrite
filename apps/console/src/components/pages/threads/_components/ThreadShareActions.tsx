'use client'

import { Check } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

type ThreadShareActionsProps = {
  path: string
  title: string
  buttonClassName?: string
  className?: string
}

function SolidLinkIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      className={cn('size-4 shrink-0', className)}
      fill="currentColor"
      aria-hidden
    >
      <path d="M10.59 13.41c.41.39.41 1.03 0 1.42-.39.39-1.03.39-1.42 0a5.003 5.003 0 0 1 0-7.07l3.54-3.54a5.003 5.003 0 0 1 7.07 0 5.003 5.003 0 0 1 0 7.07l-1.49 1.49c-.39.39-1.03.39-1.42 0-.39-.39-.39-1.03 0-1.42l1.49-1.49a3 3 0 1 0-4.24-4.24l-3.54 3.54a3 3 0 0 0 0 4.24z" />
      <path d="M13.41 10.59c-.41-.39-.41-1.03 0-1.42.39-.39 1.03-.39 1.42 0a5.003 5.003 0 0 1 0 7.07l-3.54 3.54a5.003 5.003 0 0 1-7.07 0 5.003 5.003 0 0 1 0-7.07l1.49-1.49c.39-.39 1.03-.39 1.42 0 .39.39.39 1.03 0 1.42l-1.49 1.49a3 3 0 0 0 0 4.24 3 3 0 0 0 4.24 0l3.54-3.54a3 3 0 0 0 0-4.24z" />
    </svg>
  )
}

function normalizePath(path: string): string {
  return path.startsWith('/') ? path : `/${path}`
}

export function ThreadShareActions({
  path,
  title,
  buttonClassName = 'size-9 p-0',
  className,
}: ThreadShareActionsProps) {
  const [linkCopied, setLinkCopied] = useState(false)
  const [shareUrl, setShareUrl] = useState(normalizePath(path))

  useEffect(() => {
    setShareUrl(`${window.location.origin}${normalizePath(path)}`)
  }, [path])

  const twitterShareHref = `https://twitter.com/intent/tweet?text=${encodeURIComponent(title)}&url=${encodeURIComponent(shareUrl)}`
  const linkedInShareHref = `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(shareUrl)}`
  const iconButtonClass = cn('shrink-0 text-muted-foreground', buttonClassName)

  const handleCopyLink = async () => {
    try {
      await navigator.clipboard.writeText(shareUrl)
      setLinkCopied(true)
      toast.success('Link copied')
      setTimeout(() => setLinkCopied(false), 2000)
    } catch {
      toast.error('Could not copy link')
    }
  }

  return (
    <TooltipProvider>
      <div className={cn('flex items-center gap-1', className)}>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className={iconButtonClass}
              onClick={() => void handleCopyLink()}
              aria-label="Copy link"
            >
              {linkCopied ? (
                <Check className="size-4 fill-current text-green-600" strokeWidth={3} />
              ) : (
                <SolidLinkIcon />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            <p>{linkCopied ? 'Link copied' : 'Copy link'}</p>
          </TooltipContent>
        </Tooltip>

        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className={iconButtonClass}
              asChild
              aria-label="Share on X"
            >
              <a href={twitterShareHref} target="_blank" rel="noopener noreferrer">
                <ProductFeaturePublicIcon src="/icons/x.svg" tone="muted-foreground" />
              </a>
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            <p>Share on X</p>
          </TooltipContent>
        </Tooltip>

        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className={iconButtonClass}
              asChild
              aria-label="Share on LinkedIn"
            >
              <a href={linkedInShareHref} target="_blank" rel="noopener noreferrer">
                <ProductFeaturePublicIcon src="/icons/linkedin.svg" tone="muted-foreground" />
              </a>
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            <p>Share on LinkedIn</p>
          </TooltipContent>
        </Tooltip>
      </div>
    </TooltipProvider>
  )
}
