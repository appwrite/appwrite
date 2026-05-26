import { useMemo, useState, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import type { InitDisplayEvent } from '@/lib/init/types'
import {
  findGitHubIdentity,
  getGitHubUsername,
} from '@/lib/init/github-identity'
import { buildInitTicketShareMessage } from '@/lib/init/ticket-prefs'
import { resolveInitTicketAppearance } from '@/lib/init/ticket-types'
import { useInitThemeUsesDarkImage } from '@/lib/init/use-init-theme-image'
import { useInitTicketPrefs } from '@/lib/init/use-init-ticket-prefs'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { accountIdentitiesQueryOptions } from '@/lib/react-query/hooks/auth'
import {
  getInitTicketHolderName,
  getInitTicketNumberForUser,
  InitTicketCard,
} from '@/components/pages/init/_components/InitTicketCard'
import { InitTicketCustomizeDrawer } from '@/components/pages/init/_components/InitTicketCustomizeDrawer'
import {
  INIT_TICKET_COLLAPSED_WIDTH_PX,
  INIT_TICKET_MAX_WIDTH_PX,
  initTicketDisplayAspectRatio,
} from '@/lib/init/ticket-layout'
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
  Share2,
  SlidersHorizontal,
  Ticket,
  Trophy,
} from 'lucide-react'

interface InitTicketSectionProps {
  event: InitDisplayEvent
  account?: Models.User | null
}

function buildShareUrl(): string {
  if (typeof window === 'undefined') return 'https://cloud.appwrite.io/init'
  return `${window.location.origin}/init`
}

const shareMenuItemClass =
  'flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-[13px] text-foreground transition-colors hover:bg-accent'

const COLLAPSED_TICKET_SCALE =
  INIT_TICKET_COLLAPSED_WIDTH_PX / INIT_TICKET_MAX_WIDTH_PX
const COLLAPSED_TICKET_HEIGHT_PX =
  INIT_TICKET_COLLAPSED_WIDTH_PX / initTicketDisplayAspectRatio()

interface ShareActionsProps {
  compact?: boolean
  isAuthenticated: boolean
  isSharing: boolean
  shareOpen: boolean
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
  isSharing,
  shareOpen,
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
    compact ? 'h-8 min-w-[132px] text-[12px]' : 'h-10 min-w-[180px] text-[13px]',
  )
  const iconSizeClass = compact ? 'mr-1 size-3.5' : 'mr-1.5 size-4'
  const actionsAlignClass = compact ? 'justify-start' : 'justify-center'

  if (!isAuthenticated) {
    return (
      <div className={cn('flex flex-wrap items-center gap-2', actionsAlignClass)}>
        <Button className={primaryButtonClass} asChild>
          <Link to="/sign-up" search={{ redirect: '/init' }}>
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
    <div
      className={cn(
        'flex flex-wrap items-center gap-2',
        actionsAlignClass,
      )}
    >
      <Popover open={shareOpen} onOpenChange={onShareOpenChange}>
        <PopoverTrigger asChild>
          <Button
            className={primaryButtonClass}
            disabled={isSharing}
          >
            <Trophy className={iconSizeClass} />
            Share and win
          </Button>
        </PopoverTrigger>
        <PopoverContent align={compact ? 'start' : 'center'} className="w-52 p-1">
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
            className={cn(shareMenuItemClass, 'mt-1 border-t border-border pt-2')}
            onClick={() => void onCopyShareMessage()}
          >
            <Copy className="size-4 shrink-0 text-muted-foreground" />
            Copy message
          </button>
        </PopoverContent>
      </Popover>

      <Button
        type="button"
        variant="outline"
        className={buttonSizeClass}
        onClick={onCustomize}
      >
        <SlidersHorizontal className={iconSizeClass} />
        Customize
      </Button>
    </div>
  )
}

export function InitTicketSection({ event, account }: InitTicketSectionProps) {
  const isAuthenticated = Boolean(account)
  const themeUsesDarkImage = useInitThemeUsesDarkImage()
  const { mockInitTicketType } = useDebugOverrides()
  const { data: identitiesData } = useQuery({
    ...accountIdentitiesQueryOptions(),
    enabled: isAuthenticated,
  })
  const { prefs, updatePrefs } = useInitTicketPrefs(event.id, account)
  const [collapsed, setCollapsed] = useState(false)
  const [shareOpen, setShareOpen] = useState(false)
  const [customizeOpen, setCustomizeOpen] = useState(false)
  const [isSharing, setIsSharing] = useState(false)

  const accountName =
    account?.name?.trim() || account?.email?.split('@')[0] || undefined
  const githubUsername = useMemo(() => {
    if (!isAuthenticated) return undefined
    const identity = findGitHubIdentity(identitiesData?.identities)
    return getGitHubUsername(identity, accountName)
  }, [accountName, identitiesData?.identities, isAuthenticated])
  const holderName = getInitTicketHolderName(
    accountName,
    prefs,
    isAuthenticated ? 'Console user' : 'Your name',
  )
  const ticketNumber = getInitTicketNumberForUser(account?.$id)
  const ticketAppearance = useMemo(
    () =>
      resolveInitTicketAppearance(
        event.tickets,
        { account, identities: identitiesData?.identities },
        themeUsesDarkImage,
        mockInitTicketType,
      ),
    [
      account,
      event.tickets,
      identitiesData?.identities,
      mockInitTicketType,
      themeUsesDarkImage,
    ],
  )
  const shareUrl = buildShareUrl()

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
    if (!isAuthenticated) return
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

  const shareActionsProps: ShareActionsProps = {
    compact: collapsed,
    isAuthenticated,
    isSharing,
    shareOpen,
    onShareOpenChange: setShareOpen,
    onNativeShare: () => void handleNativeShare(),
    onCopyShareMessage: () => void handleCopyShareMessage(),
    onCustomize: () => setCustomizeOpen(true),
    canNativeShare,
    twitterShareHref,
    linkedInShareHref,
    onShareMenuClose: () => setShareOpen(false),
  }

  const ticketCardProps = {
    eventName: event.name,
    dateRangeLabel: event.dateRangeLabel,
    holderName,
    githubUsername,
    ticketNumber,
    prefs,
    ticketAppearance,
  } as const

  const sectionTitle = isAuthenticated
    ? 'Share to enter the giveaway'
    : 'Claim your Init ticket'
  const sectionDescriptionExpanded = isAuthenticated
    ? 'Post your ticket on socials during Init week. One ticket holder wins the exclusive giveaway on day 5 — sharing is how you enter.'
    : 'Create a free account to unlock your personalized pass, customize it with your stack, and share for a chance to win exclusive Init swag.'
  const sectionDescriptionCollapsed = isAuthenticated
    ? 'Share your ticket on socials for a chance to win exclusive Init swag.'
    : 'Sign up to claim your pass and enter the day 5 giveaway.'

  let sectionBody: ReactNode

  if (collapsed) {
    sectionBody = (
      <div className="flex items-center gap-3 py-1 pr-10 sm:gap-5 sm:py-2">
        <div
          className="relative shrink-0"
          style={{
            width: INIT_TICKET_COLLAPSED_WIDTH_PX,
            height: COLLAPSED_TICKET_HEIGHT_PX,
          }}
        >
          <div
            className="pointer-events-none absolute left-0 top-0 origin-top-left"
            style={{
              width: INIT_TICKET_MAX_WIDTH_PX,
              transform: `scale(${COLLAPSED_TICKET_SCALE})`,
            }}
          >
            <InitTicketCard {...ticketCardProps} previewOnly />
          </div>
        </div>
        <div className="min-w-0 flex-1 space-y-2 text-left">
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
      <div className="mx-auto flex w-full max-w-[820px] flex-col items-center">
        <div className="relative mx-auto w-full">
          <InitTicketCard {...ticketCardProps} />
        </div>

        <div className="mt-1 flex w-full flex-col items-center gap-4 pb-8 text-center sm:mt-2 sm:pb-10">
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
    <section className="relative w-full" aria-label="Init ticket">
      <Button
        type="button"
        variant="outline"
        size="icon"
        className="absolute right-0 top-0 z-20 size-8 shrink-0"
        onClick={() => setCollapsed((value) => !value)}
        aria-expanded={!collapsed}
        aria-label={collapsed ? 'Expand ticket section' : 'Collapse ticket section'}
      >
        <ChevronDown
          className={cn('size-4 transition-transform duration-200', collapsed && 'rotate-180')}
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
