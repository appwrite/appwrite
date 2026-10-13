import { useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { CodeXml, MonitorPlay } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  ContextMenuItem,
} from '@/components/ui/context-menu'
import {
  ContextMenuIcon,
} from '@/components/global/shared/ContextMenuIcon'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { getVideoEmbedPlaybackDisabledReason } from '@/lib/videos/embed-playback-eligibility'
import { getVideoEmbedPlayerUrl } from '@/lib/videos/embed-player-url'
import { openInNewTab } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { VideoEmbed } from './VideoEmbed'

type VideoPreviewEmbedMenuProps = {
  projectId: string
  video: Pick<Models.Video, '$id'> & {
    name?: string | null
    duration?: number
  }
  renditions: Pick<Models.VideoRendition, 'status'>[]
  /** Toolbar buttons on video pages; context menu items on list right-click. */
  variant: 'toolbar' | 'context'
  className?: string
}

export function VideoPreviewEmbedMenu({
  projectId,
  video,
  renditions,
  variant,
  className,
}: VideoPreviewEmbedMenuProps) {
  const t = useT()
  const [embedOpen, setEmbedOpen] = useState(false)
  const disabledReason = getVideoEmbedPlaybackDisabledReason(video, renditions)
  const disabled = Boolean(disabledReason)
  const disabledTooltip = disabledReason ? t(disabledReason) : undefined
  const playerUrl = getVideoEmbedPlayerUrl(projectId, video.$id)
  const title = video.name?.trim() || t('Video player')

  const preview = () => {
    if (disabled) return
    openInNewTab(playerUrl)
  }

  const openEmbed = () => {
    if (disabled) return
    setEmbedOpen(true)
  }

  const previewControl =
    variant === 'context' ? (
      <ContextMenuItem
        disabled={disabled}
        onSelect={(event) => {
          if (disabled) {
            event.preventDefault()
            return
          }
          preview()
        }}
        className="text-[13px]"
      >
        <ContextMenuIcon icon={MonitorPlay} />
        {t('Preview')}
      </ContextMenuItem>
    ) : (
      <ActionButton
        label={t('Preview')}
        icon={MonitorPlay}
        disabled={disabled}
        disabledTooltip={disabledTooltip}
        onClick={preview}
        className={className?.includes('w-full') ? 'flex-1' : undefined}
      />
    )

  const embedControl =
    variant === 'context' ? (
      <ContextMenuItem
        disabled={disabled}
        onSelect={(event) => {
          if (disabled) {
            event.preventDefault()
            return
          }
          openEmbed()
        }}
        className="text-[13px]"
      >
        <ContextMenuIcon icon={CodeXml} />
        {t('Embed')}
      </ContextMenuItem>
    ) : (
      <ActionButton
        label={t('Embed')}
        icon={CodeXml}
        disabled={disabled}
        disabledTooltip={disabledTooltip}
        onClick={openEmbed}
        className={className?.includes('w-full') ? 'flex-1' : undefined}
      />
    )

  return (
    <>
      {variant === 'context' ? (
        <>
          {previewControl}
          {embedControl}
        </>
      ) : (
        <div className={cn('flex items-center gap-2', className)}>
          {previewControl}
          {embedControl}
        </div>
      )}
      <VideoEmbed
        open={embedOpen}
        onOpenChange={setEmbedOpen}
        projectId={projectId}
        videoId={video.$id}
        videoTitle={title}
        durationMs={video.duration}
      />
    </>
  )
}

function ActionButton({
  label,
  icon: Icon,
  disabled,
  disabledTooltip,
  onClick,
  className,
}: {
  label: string
  icon: typeof MonitorPlay
  disabled: boolean
  disabledTooltip?: string
  onClick: () => void
  className?: string
}) {
  const button = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className={cn(
        'h-9 gap-1.5 text-[13px]',
        className?.includes('flex-1') && 'w-full',
        className,
      )}
      disabled={disabled}
      onClick={onClick}
    >
      <Icon className="h-3.5 w-3.5" />
      {label}
    </Button>
  )

  if (!disabled || !disabledTooltip) return button

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className={cn('inline-flex min-w-0', className)}>{button}</span>
      </TooltipTrigger>
      <TooltipContent side="bottom" className="max-w-xs text-[12px]">
        {disabledTooltip}
      </TooltipContent>
    </Tooltip>
  )
}
