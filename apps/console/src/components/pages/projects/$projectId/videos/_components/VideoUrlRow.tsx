import type { ReactNode } from 'react'
import { Copy, ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { cn } from '@/lib/utils'
import { copyToClipboard, openInNewTab } from '@/lib/utils/context-menu'
import type { VideoGlossaryTerm } from '@/lib/videos/glossary'
import { useT } from '@/lib/i18n/translate'
import { VideoFormatLabel, VideoOutputBadge } from './VideoOutputBadge'
import { VideoTermHint } from './VideoTermHint'

/**
 * One copyable endpoint. `url` is what client apps request; opening from the
 * console adds admin mode because the console user is not in the file's read roles.
 */
export function VideoUrlRow({
  label,
  term,
  url,
  meta,
  unavailableReason,
  formatKey,
}: {
  label: ReactNode
  term?: VideoGlossaryTerm
  url: string
  meta?: ReactNode
  unavailableReason?: string | null
  /** HLS, DASH, CMAF, or manifest kind for color-coded label. */
  formatKey?: string
}) {
  const t = useT()
  const unavailable = Boolean(unavailableReason)
  return (
    <div className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:gap-4">
      <div className="flex w-full shrink-0 items-center gap-1.5 sm:w-44">
        {formatKey ? (
          <VideoOutputBadge
            output={formatKey}
            label={label}
            className={cn(
              'text-[11px] normal-case',
              unavailable && 'opacity-60',
            )}
          />
        ) : (
          <span
            className={cn(
              'text-[13px] font-medium text-foreground',
              unavailable && 'text-muted-foreground',
            )}
          >
            {label}
          </span>
        )}
        {term ? <VideoTermHint term={term} /> : null}
        {meta ? (
          <span className="text-[11px] text-muted-foreground">{meta}</span>
        ) : null}
      </div>
      <code
        className={cn(
          'min-w-0 flex-1 truncate rounded-md border border-border bg-muted/40 px-2.5 py-1.5 font-mono text-[12px] text-foreground',
          unavailable && 'text-muted-foreground',
        )}
        title={url}
      >
        {unavailable ? unavailableReason : url}
      </code>
      <div className="flex shrink-0 items-center gap-1">
        <IconAction
          label={t('Copy URL')}
          disabled={unavailable}
          onClick={() => copyToClipboard(t('URL'), url)}
        >
          <Copy className="h-3.5 w-3.5" />
        </IconAction>
        <IconAction
          label={t('Open in new tab')}
          disabled={unavailable}
          onClick={() => openInNewTab(withAdminMode(url))}
        >
          <ExternalLink className="h-3.5 w-3.5" />
        </IconAction>
      </div>
    </div>
  )
}

function IconAction({
  label,
  disabled,
  onClick,
  children,
}: {
  label: string
  disabled?: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">
          <Button
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-muted-foreground hover:text-foreground"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
          >
            {children}
          </Button>
        </span>
      </TooltipTrigger>
      <TooltipContent className="text-[12px]">{label}</TooltipContent>
    </Tooltip>
  )
}
