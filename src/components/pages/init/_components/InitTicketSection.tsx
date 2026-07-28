import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { analyticsAttrs } from '@/lib/analytics-actions'
import type { InitDisplayEvent } from '@/lib/init/types'
import { buildInitTicketShareMessage } from '@/lib/init/ticket-prefs'
import { buildInitTicketShareUrl } from '@/lib/init/init-ticket-share'
import { getDefaultSiteOrigin } from '@/lib/marketing/site-origin'
import { useInitThemeUsesDarkImage } from '@/lib/init/use-init-theme-image'
import { useInitTicketPrefs } from '@/lib/init/use-init-ticket-prefs'
import { useSyncInitTicketImage } from '@/lib/init/use-sync-init-ticket-image'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { accountIdentitiesQueryOptions } from '@/lib/react-query/hooks/auth'
import { buildInitTicketRenderData } from '@/lib/init/ticket-render-data'
import {
  InitTicketCard,
  type InitTicketCardHandle,
} from '@/components/pages/init/_components/InitTicketCard'
import {
  downloadInitTicketVideo,
  isInitTicketVideoExportSupported,
  recordInitTicketVideo,
  supportsInitTicket60FpsVideoCapture,
  waitForNextPaint,
} from '@/lib/init/record-init-ticket-video'
import { useInitTicketVideoRecording } from '@/lib/init/init-ticket-video-recording-context'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import {
  buildInitCustomizingTicketActivity,
  buildInitRecordingTicketActivity,
  buildInitViewingTicketActivity,
} from '@/lib/init/init-presence-activity'
import { INIT_TICKET_SECTION_ID } from '@/lib/init/init-section-ids'
import { INIT_TICKET_VIDEO_HERO_WARMUP_MS } from '@/lib/init/ticket-video-capture'
import { InitTicketScaledFrame } from '@/components/pages/init/_components/InitTicketScaledFrame'
import { InitTicketVideoCaptureStage } from '@/components/pages/init/_components/InitTicketVideoCaptureStage'
import { InitTicketCustomizeDrawer } from '@/components/pages/init/_components/InitTicketCustomizeDrawer'
import { INIT_TICKET_COLLAPSED_WIDTH_PX } from '@/lib/init/ticket-layout'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import { toast } from 'sonner'
import {
  ChevronDown,
  Copy,
  Linkedin,
  Loader2,
  Share2,
  SlidersHorizontal,
  Ticket,
  Trophy,
  Video,
} from 'lucide-react'

interface InitTicketSectionProps {
  event: InitDisplayEvent
  account?: Models.User | null
}

function buildFallbackShareUrl(): string {
  if (typeof window === 'undefined') {
    return `${getDefaultSiteOrigin()}/init`
  }
  return `${window.location.origin}/init`
}

function buildTicketShareUrl(ticketId?: string): string {
  if (!ticketId) return buildFallbackShareUrl()
  if (typeof window === 'undefined') {
    return buildInitTicketShareUrl(ticketId, getDefaultSiteOrigin())
  }
  return buildInitTicketShareUrl(ticketId)
}

const shareMenuItemClass =
  'flex w-full items-center gap-2 rounded-md px-2 py-2 text-start text-[13px] text-foreground transition-colors hover:bg-accent'

interface ShareActionsProps {
  compact?: boolean
  isAuthenticated: boolean
  isRecapMode?: boolean
  shareButtonLabel?: string
  isSharing: boolean
  shareOpen: boolean
  hasShareLink: boolean
  shareDisabledTooltip?: string
  onShareOpenChange: (open: boolean) => void
  onNativeShare: () => void
  onCopyShareMessage: () => void
  onCustomize: () => void
  canNativeShare: boolean
  twitterShareHref: string
  linkedInShareHref: string
  onShareMenuClose: () => void
}

function ShareActions({
  compact = false,
  isAuthenticated,
  isRecapMode = false,
  shareButtonLabel,
  isSharing,
  shareOpen,
  hasShareLink,
  shareDisabledTooltip,
  onShareOpenChange,
  onNativeShare,
  onCopyShareMessage,
  onCustomize,
  canNativeShare,
  twitterShareHref,
  linkedInShareHref,
  onShareMenuClose,
}: ShareActionsProps) {
  const buttonSizeClass = compact ? 'h-8 text-[12px]' : 'h-10 text-[13px]'
  const primaryButtonClass = cn(
    compact
      ? 'h-8 px-2.5 text-[12px] has-[>svg]:px-2'
      : 'h-10 px-3 text-[13px] has-[>svg]:px-2.5',
  )
  const iconSizeClass = compact ? 'me-1 size-3.5' : 'me-1.5 size-4'
  const actionsAlignClass = compact ? 'justify-start' : 'justify-center'
  const primaryShareLabel =
    shareButtonLabel ?? (isRecapMode ? 'Share ticket' : 'Share and win')

  if (!isAuthenticated && !hasShareLink) {
    return (
      <div
        className={cn('flex flex-wrap items-center gap-2', actionsAlignClass)}
      >
        <Button className={primaryButtonClass} asChild>
          <Link
            to="/sign-up"
            search={{ redirect: '/init' }}
            {...analyticsAttrs('init-claim-ticket')}
          >
            <Ticket className={iconSizeClass} />
            Claim your ticket
          </Link>
        </Button>
        <Button variant="outline" className={buttonSizeClass} asChild>
          <Link to="/sign-in" search={{ redirect: '/init' }}>
            Sign in
          </Link>
        </Button>
      </div>
    )
  }

  return (
    <div className={cn('flex flex-wrap items-center gap-2', actionsAlignClass)}>
      {!isAuthenticated ? (
        <Button className={primaryButtonClass} asChild>
          <Link
            to="/sign-up"
            search={{ redirect: '/init' }}
            {...analyticsAttrs('init-claim-ticket')}
          >
            <Ticket className={iconSizeClass} />
            Claim your ticket
          </Link>
        </Button>
      ) : null}
      <Popover open={shareOpen} onOpenChange={onShareOpenChange}>
        <PopoverTrigger asChild>
          <Button
            className={primaryButtonClass}
            disabled={isSharing || !hasShareLink}
            title={!hasShareLink ? shareDisabledTooltip : undefined}
          >
            <Trophy className={iconSizeClass} />
            {primaryShareLabel}
          </Button>
        </PopoverTrigger>
        <PopoverContent
          align={compact ? 'start' : 'center'}
          className="w-52 p-1"
        >
          {canNativeShare ? (
            <button
              type="button"
              className={shareMenuItemClass}
              disabled={isSharing}
              onClick={() => void onNativeShare()}
            >
              <Share2 className="size-4 shrink-0 text-muted-foreground" />
              Share…
            </button>
          ) : null}
          <a
            href={twitterShareHref}
            target="_blank"
            rel="noopener noreferrer"
            className={shareMenuItemClass}
            onClick={onShareMenuClose}
          >
            <span className="flex size-4 shrink-0 items-center justify-center text-[11px] font-bold text-muted-foreground">
              X
            </span>
            Share on X
          </a>
          <a
            href={linkedInShareHref}
            target="_blank"
            rel="noopener noreferrer"
            className={shareMenuItemClass}
            onClick={onShareMenuClose}
          >
            <Linkedin className="size-4 shrink-0 text-muted-foreground" />
            LinkedIn
          </a>
          <button
            type="button"
            className={cn(
              shareMenuItemClass,
              'mt-1 border-t border-border pt-2',
            )}
            onClick={() => void onCopyShareMessage()}
          >
            <Copy className="size-4 shrink-0 text-muted-foreground" />
            Copy message
          </button>
        </PopoverContent>
      </Popover>

      {isAuthenticated ? (
        <Button
          type="button"
          variant="outline"
          className={buttonSizeClass}
          onClick={onCustomize}
        >
          <SlidersHorizontal className={iconSizeClass} />
          Customize
        </Button>
      ) : null}
    </div>
  )
}

export function InitTicketSection({
  event,
  account,
}: InitTicketSectionProps) {
  const isAuthenticated = Boolean(account)
  const themeUsesDarkImage = useInitThemeUsesDarkImage()
  const { mockInitTicketType } = useDebugOverrides()
  const { data: identitiesData } = useQuery({
    ...accountIdentitiesQueryOptions(),
    enabled: isAuthenticated,
  })
  const { prefs, updatePrefs } = useInitTicketPrefs(event.id, account)
  const collapsed = prefs.sectionCollapsed ?? false
  const [shareOpen, setShareOpen] = useState(false)
  const [customizeOpen, setCustomizeOpen] = useState(false)
  const [isSharing, setIsSharing] = useState(false)
  const [isCapturingVideo, setIsCapturingVideo] = useState(false)
  const [isExportingVideo, setIsExportingVideo] = useState(false)
  const [videoExportProgress, setVideoExportProgress] = useState<number | null>(
    null,
  )
  const isVideoBusy = isCapturingVideo || isExportingVideo
  const { setIsCapturing: setPageVideoCapturing } =
    useInitTicketVideoRecording()
  const { setPriorityActivity, setTransientActivity } =
    useInitPresenceActivity()
  const ticketCardRef = useRef<InitTicketCardHandle>(null)

  useEffect(() => {
    setPageVideoCapturing(isCapturingVideo)
    return () => setPageVideoCapturing(false)
  }, [isCapturingVideo, setPageVideoCapturing])

  useEffect(() => {
    if (!isAuthenticated) return
    if (isVideoBusy) {
      setPriorityActivity(buildInitRecordingTicketActivity())
      return
    }
    if (customizeOpen) {
      setPriorityActivity(buildInitCustomizingTicketActivity())
      return
    }
    setPriorityActivity(null)
  }, [customizeOpen, isAuthenticated, isVideoBusy, setPriorityActivity])
  const videoCaptureStageRef = useRef<HTMLDivElement>(null)
  const canExportTicketVideo = isInitTicketVideoExportSupported()
  const canExport60FpsVideo = supportsInitTicket60FpsVideoCapture()

  const ticketRenderData = useMemo(
    () =>
      buildInitTicketRenderData({
        event,
        account,
        identities: isAuthenticated ? identitiesData?.identities : undefined,
        prefs,
        themeUsesDarkImage,
        mockTypeId: mockInitTicketType,
        fallbackHolderName: isAuthenticated ? 'Console user' : 'Your name',
      }),
    [
      account,
      event,
      identitiesData?.identities,
      isAuthenticated,
      mockInitTicketType,
      prefs,
      themeUsesDarkImage,
    ],
  )
  const { holderName, ticketAppearance } = ticketRenderData

  useSyncInitTicketImage({
    eventSlug: event.slug,
    account,
    prefs,
    renderData: ticketRenderData,
    themeUsesDarkImage,
    updatePrefs,
  })

  const shareUrl = buildTicketShareUrl(prefs.imageFileId)
  const hasShareLink = Boolean(prefs.imageFileId)
  const shareDisabledTooltip = hasShareLink
    ? undefined
    : 'Your ticket image is still generating'

  const shareMessage = useMemo(
    () =>
      buildInitTicketShareMessage({
        eventName: event.name,
        dateRangeLabel: event.dateRangeLabel,
        holderName,
        shareUrl,
      }),
    [event.dateRangeLabel, event.name, holderName, shareUrl],
  )

  const twitterShareHref = `https://twitter.com/intent/tweet?text=${encodeURIComponent(shareMessage)}`
  const linkedInShareHref = `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(shareUrl)}`
  const canNativeShare =
    typeof navigator !== 'undefined' && typeof navigator.share === 'function'

  const handleNativeShare = async () => {
    if (!hasShareLink) return
    setIsSharing(true)
    try {
      await navigator.share({
        title: `${event.name} Init ticket`,
        text: shareMessage,
        url: shareUrl,
      })
      setShareOpen(false)
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return
      toast.error('Could not share your ticket')
    } finally {
      setIsSharing(false)
    }
  }

  const handleCopyShareMessage = async () => {
    try {
      await navigator.clipboard.writeText(shareMessage)
      toast.success('Share message copied')
      setShareOpen(false)
    } catch {
      toast.error('Could not copy to clipboard')
    }
  }

  const handleDownloadTicketVideo = async () => {
    if (!isAuthenticated) return

    const handle = ticketCardRef.current
    const captureElement = videoCaptureStageRef.current
    if (!handle || !captureElement) return

    setIsCapturingVideo(true)
    setIsExportingVideo(true)
    setVideoExportProgress(0)
    try {
      await waitForNextPaint()
      await waitForNextPaint()
      await new Promise<void>((resolve) => {
        window.setTimeout(resolve, INIT_TICKET_VIDEO_HERO_WARMUP_MS)
      })
      handle.prepareForVideoCapture()
      await waitForNextPaint()

      const { blob, fileExtension } = await recordInitTicketVideo({
        captureElement,
        setTilt: handle.setCaptureTilt,
        resetTilt: handle.resetCaptureTilt,
        onProgress: setVideoExportProgress,
        onVisibleCaptureComplete: () => {
          setIsCapturingVideo(false)
          handle.resetCaptureTilt()
        },
      })
      const slug = event.name
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
      downloadInitTicketVideo(blob, `${slug || 'init'}-ticket.${fileExtension}`)
      toast.success('Ticket video downloaded')
    } catch (error) {
      if (
        error instanceof Error &&
        error.message === 'Tab capture was cancelled'
      ) {
        toast.error('Video capture was cancelled')
      } else {
        toast.error('Could not generate ticket video')
      }
    } finally {
      setIsCapturingVideo(false)
      setIsExportingVideo(false)
      setVideoExportProgress(null)
    }
  }

  const ticketCopy = event.isRecapMode ? event.recap?.ticket : undefined

  const shareActionsProps: ShareActionsProps = {
    compact: collapsed,
    isAuthenticated,
    isRecapMode: event.isRecapMode,
    shareButtonLabel: ticketCopy?.shareButtonLabel,
    isSharing,
    shareOpen,
    hasShareLink,
    shareDisabledTooltip,
    onShareOpenChange: setShareOpen,
    onNativeShare: () => void handleNativeShare(),
    onCopyShareMessage: () => void handleCopyShareMessage(),
    onCustomize: () => setCustomizeOpen(true),
    canNativeShare,
    twitterShareHref,
    linkedInShareHref,
    onShareMenuClose: () => setShareOpen(false),
  }

  const ticketCardProps = ticketRenderData

  const sectionTitle = isAuthenticated
    ? (ticketCopy?.titleAuthenticated ?? 'Share to enter the giveaway')
    : (ticketCopy?.titleGuest ?? 'Claim your Init ticket')
  const sectionDescriptionExpanded = isAuthenticated
    ? (ticketCopy?.descriptionAuthenticated ??
      'Post your ticket on socials during Init week. One ticket holder wins the exclusive giveaway on day 5. Sharing is how you enter.')
    : (ticketCopy?.descriptionGuest ??
      'Create a free account to unlock your personalized pass, customize it with your stack, and share for a chance to win exclusive Init swag.')
  const sectionDescriptionCollapsed = isAuthenticated
    ? (ticketCopy?.descriptionCollapsedAuthenticated ??
      'Share your ticket on socials for a chance to win exclusive Init swag.')
    : (ticketCopy?.descriptionCollapsedGuest ??
      'Sign up to claim your pass and enter the day 5 giveaway.')

  let sectionBody: ReactNode

  if (collapsed) {
    sectionBody = (
      <div className="flex items-center gap-3 py-1 pe-10 sm:gap-5 sm:py-2">
        <InitTicketScaledFrame
          widthPx={INIT_TICKET_COLLAPSED_WIDTH_PX}
          pointerEventsNone
        >
          <InitTicketCard {...ticketCardProps} previewOnly />
        </InitTicketScaledFrame>
        <div className="min-w-0 flex-1 space-y-2 text-start">
          <h2 className="text-[14px] font-semibold leading-tight tracking-tight text-foreground sm:text-[15px]">
            {sectionTitle}
          </h2>
          <p className="hidden text-[12px] leading-relaxed text-muted-foreground sm:block">
            {sectionDescriptionCollapsed}
          </p>
          <ShareActions {...shareActionsProps} />
        </div>
      </div>
    )
  } else {
    sectionBody = (
      <div className="mx-auto flex w-full max-w-[820px] flex-col items-center pt-8">
        <InitTicketVideoCaptureStage
          ref={videoCaptureStageRef}
          showRecordingChrome={isCapturingVideo}
          showHeroAnimation={isCapturingVideo}
          ticketAccentColor={ticketAppearance.accentColor}
          ticketUsesDarkChrome={ticketAppearance.usesDarkChrome}
        >
          <InitTicketScaledFrame
            measureContainer
            className="w-full"
            overflowVisible
          >
            <InitTicketCard
              ref={ticketCardRef}
              captureMode={isCapturingVideo}
              {...ticketCardProps}
            />
          </InitTicketScaledFrame>
        </InitTicketVideoCaptureStage>

        <div className="mt-0 flex w-full flex-col items-center gap-3 pb-5 text-center sm:pb-6">
          <div className="max-w-md space-y-2">
            <h2 className="text-[18px] font-semibold leading-tight tracking-tight text-foreground">
              {sectionTitle}
            </h2>
            <p className="text-[13px] leading-relaxed text-muted-foreground">
              {sectionDescriptionExpanded}
            </p>
          </div>
          <ShareActions {...shareActionsProps} />
        </div>
      </div>
    )
  }

  return (
    <section
      id={INIT_TICKET_SECTION_ID}
      className="relative w-full scroll-mt-28"
      aria-label="Init ticket"
      onMouseEnter={() => {
        if (!isAuthenticated || customizeOpen || isVideoBusy) return
        setTransientActivity(buildInitViewingTicketActivity())
      }}
      onMouseLeave={() => setTransientActivity(null)}
    >
      {!collapsed && isAuthenticated ? (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="absolute start-0 top-0 z-20 shrink-0 text-[13px]"
          disabled={!canExportTicketVideo || isVideoBusy}
          title={
            !canExportTicketVideo
              ? 'Video export is not supported in this browser'
              : isVideoBusy
                ? undefined
                : canExport60FpsVideo
                  ? 'Records a ~10s 60fps clip via tab share (Chrome or Edge)'
                  : 'Records a ~10s clip (use Chrome or Edge for 60fps)'
          }
          aria-busy={isVideoBusy}
          onClick={() => void handleDownloadTicketVideo()}
        >
          {isVideoBusy ? (
            <>
              <Loader2 className="me-1.5 size-4 animate-spin" />
              {isCapturingVideo ? 'Recording' : 'Processing'}{' '}
              {Math.round((videoExportProgress ?? 0) * 100)}%
            </>
          ) : (
            <>
              <Video className="me-1.5 size-4" />
              {canExport60FpsVideo
                ? 'Download 60fps video'
                : 'Download ticket video'}
            </>
          )}
        </Button>
      ) : null}
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="absolute end-0 top-0 z-20 size-8 shrink-0"
        onClick={() => updatePrefs({ sectionCollapsed: !collapsed })}
        aria-expanded={!collapsed}
        aria-label={
          collapsed ? 'Expand ticket section' : 'Collapse ticket section'
        }
      >
        <ChevronDown
          className={cn(
            'size-4 transition-transform duration-200',
            collapsed && 'rotate-180',
          )}
        />
      </Button>

      {sectionBody}

      <InitTicketCustomizeDrawer
        open={customizeOpen}
        onOpenChange={setCustomizeOpen}
        prefs={prefs}
        updatePrefs={updatePrefs}
        account={account}
        defaultHolderTitle={ticketAppearance.holderTitle}
      />
    </section>
  )
}
