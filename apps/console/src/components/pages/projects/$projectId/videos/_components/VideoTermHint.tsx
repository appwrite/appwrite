import type { ReactNode } from 'react'
import { Info } from 'lucide-react'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { VIDEO_GLOSSARY, type VideoGlossaryTerm } from '@/lib/videos/glossary'

/** Small info icon that explains a Videos term on hover or focus. */
export function VideoTermHint({
  term,
  className,
  side = 'top',
}: {
  term: VideoGlossaryTerm
  className?: string
  side?: 'top' | 'bottom' | 'left' | 'right'
}) {
  const t = useT()
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span
          role="img"
          tabIndex={0}
          aria-label={t(VIDEO_GLOSSARY[term])}
          className={cn(
            'inline-flex shrink-0 cursor-help items-center text-muted-foreground/70 transition-colors hover:text-foreground focus-visible:text-foreground focus-visible:outline-none',
            className,
          )}
          onClick={(event) => event.preventDefault()}
        >
          <Info className="h-3.5 w-3.5" />
        </span>
      </TooltipTrigger>
      <TooltipContent
        side={side}
        className="z-[10060] max-w-[260px] text-[12px] leading-snug"
      >
        {t(VIDEO_GLOSSARY[term])}
      </TooltipContent>
    </Tooltip>
  )
}

/** Inline label followed by a term hint. */
export function VideoTermLabel({
  term,
  children,
  className,
}: {
  term: VideoGlossaryTerm
  children: ReactNode
  className?: string
}) {
  return (
    <span className={cn('inline-flex items-center gap-1', className)}>
      {children}
      <VideoTermHint term={term} />
    </span>
  )
}
