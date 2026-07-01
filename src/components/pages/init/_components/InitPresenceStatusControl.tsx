import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Skeleton } from '@/components/ui/skeleton'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  useInitPresence,
  type InitParticipantStatus,
} from '@/lib/init/init-presence-context'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { cn } from '@/lib/utils'
import { motion, useReducedMotion } from 'motion/react'

const PRESENCE_STATUS_PULSE_TRANSITION = {
  duration: 0.42,
  ease: [0.22, 1, 0.36, 1] as [number, number, number, number],
}

const ONLINE_USER_TOOLTIP_CLASS =
  'max-w-[min(280px,calc(100dvw-5rem))] border border-border bg-popover px-3 py-2.5 text-popover-foreground shadow-md [&_svg]:!hidden'

function StatusDot({ online, className }: { online: boolean; className?: string }) {
  const reduceMotion = useReducedMotion()

  return (
    <motion.span
      key={online ? 'online' : 'offline'}
      className={cn(
        'size-2 shrink-0 rounded-full',
        online ? 'bg-emerald-500 shadow-[0_0_0_2px_color-mix(in_srgb,var(--background)_70%,transparent)]' : 'bg-muted-foreground/45',
        className,
      )}
      animate={reduceMotion ? undefined : { scale: [1, 1.25, 1] }}
      transition={PRESENCE_STATUS_PULSE_TRANSITION}
      aria-hidden
    />
  )
}

function PresenceStatusToggle({
  participantStatus,
  disabled,
  onStatusChange,
  className,
}: {
  participantStatus: InitParticipantStatus
  disabled?: boolean
  onStatusChange: (status: InitParticipantStatus) => void
  className?: string
}) {
  return (
    <ToggleGroup
      type="single"
      variant="outline"
      size="sm"
      value={participantStatus}
      onValueChange={(value) => {
        if (value === 'online' || value === 'offline') {
          onStatusChange(value)
        }
      }}
      disabled={disabled}
      className={cn('grid w-full grid-cols-2', className)}
      aria-label="Your Init presence status"
    >
      <ToggleGroupItem
        value="online"
        className="h-8 cursor-pointer text-[12px] font-medium disabled:cursor-not-allowed data-[state=on]:bg-emerald-500/10 data-[state=on]:text-emerald-700 dark:data-[state=on]:text-emerald-400"
      >
        Online
      </ToggleGroupItem>
      <ToggleGroupItem
        value="offline"
        className="h-8 cursor-pointer text-[12px] font-medium disabled:cursor-not-allowed data-[state=on]:bg-muted data-[state=on]:text-foreground"
      >
        Offline
      </ToggleGroupItem>
    </ToggleGroup>
  )
}

function PresenceStatusPanel({
  name,
  participantStatus,
  isUpdating,
  onStatusChange,
}: {
  name: string
  participantStatus: InitParticipantStatus
  isUpdating: boolean
  onStatusChange: (status: InitParticipantStatus) => void
}) {
  const isOnline = participantStatus === 'online'

  return (
    <div className="space-y-3">
      <div className="flex items-start gap-2.5">
        <InitialsAvatar name={name} size="sm" className="mt-0.5 shrink-0 rounded-full" />
        <div className="min-w-0 flex-1">
          <p className="truncate text-[13px] font-medium text-foreground">{name}</p>
          <div className="mt-0.5 flex items-start gap-1.5">
            <StatusDot online={isOnline} className="mt-1" />
            <p className="min-h-[2lh] text-[11px] leading-normal text-muted-foreground">
              {isOnline ? 'Visible to others on Init' : 'Hidden from the online list'}
            </p>
          </div>
        </div>
        <div className="flex size-3.5 shrink-0 items-center justify-center" aria-hidden={!isUpdating}>
          {isUpdating ? (
            <Loader2 className="size-3.5 animate-spin text-muted-foreground" />
          ) : null}
        </div>
      </div>
      <PresenceStatusToggle
        participantStatus={participantStatus}
        disabled={isUpdating}
        onStatusChange={onStatusChange}
      />
    </div>
  )
}

function InitPresenceStatusSkeleton({
  collapsed = false,
  isMobile = false,
}: {
  collapsed?: boolean
  isMobile?: boolean
}) {
  if (collapsed && !isMobile) {
    return (
      <div
        className="flex justify-center py-2"
        aria-busy="true"
        aria-label="Loading your status"
      >
        <Skeleton className="size-8 rounded-full bg-muted/80 dark:bg-muted/40" />
      </div>
    )
  }

  return (
    <div
      className={cn('space-y-3 px-2.5 py-3', isMobile && 'px-0 py-0')}
      aria-busy="true"
      aria-label="Loading your status"
    >
      <Skeleton className="h-2.5 w-14 bg-muted/80 dark:bg-muted/40" />
      <div className="flex items-start gap-2.5">
        <Skeleton className="mt-0.5 size-8 shrink-0 rounded-full bg-muted/80 dark:bg-muted/40" />
        <div className="min-w-0 flex-1 space-y-2">
          <Skeleton className="h-3.5 w-[58%] bg-muted/80 dark:bg-muted/40" />
          <Skeleton className="min-h-[2lh] w-full bg-muted/80 dark:bg-muted/40" />
        </div>
        <span className="size-3.5 shrink-0" aria-hidden />
      </div>
      <div className="grid grid-cols-2 gap-1.5">
        <Skeleton className="h-8 rounded-md bg-muted/80 dark:bg-muted/40" />
        <Skeleton className="h-8 rounded-md bg-muted/80 dark:bg-muted/40" />
      </div>
    </div>
  )
}

export function InitPresenceStatusControl({
  collapsed = false,
  isMobile = false,
}: {
  collapsed?: boolean
  isMobile?: boolean
}) {
  const { data: account } = useQuery(consoleAccountQueryOptions())
  const {
    participantStatus,
    isParticipantStatusUpdating,
    isReady,
    setParticipantStatus,
  } = useInitPresence()
  const reduceMotion = useReducedMotion()

  const name =
    account?.name?.trim() || account?.email?.split('@')[0]?.trim() || 'You'

  const handleStatusChange = (status: InitParticipantStatus) => {
    void setParticipantStatus(status)
  }

  if (!account || !isReady) {
    return <InitPresenceStatusSkeleton collapsed={collapsed} isMobile={isMobile} />
  }

  if (collapsed && !isMobile) {
    const isOnline = participantStatus === 'online'

    return (
      <Popover>
        <Tooltip delayDuration={200}>
          <TooltipTrigger asChild>
            <PopoverTrigger asChild>
              <button
                type="button"
                className={cn(
                  'relative flex w-full items-center justify-center rounded-md py-2',
                  'transition-colors hover:bg-accent/50',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                )}
                aria-label={`Your status: ${isOnline ? 'Online' : 'Offline'}. Open status settings`}
              >
                <span className="relative">
                  <InitialsAvatar name={name} size="sm" className="rounded-full" />
                  <motion.span
                    key={isOnline ? 'online' : 'offline'}
                    className={cn(
                      'absolute -bottom-0.5 -end-0.5 size-2.5 rounded-full ring-2 ring-background',
                      isOnline ? 'bg-emerald-500' : 'bg-muted-foreground/50',
                    )}
                    animate={reduceMotion ? undefined : { scale: [1, 1.25, 1] }}
                    transition={PRESENCE_STATUS_PULSE_TRANSITION}
                    aria-hidden
                  />
                </span>
                {isParticipantStatusUpdating ? (
                  <Loader2 className="absolute end-1 top-1 size-3 animate-spin text-muted-foreground" />
                ) : null}
              </button>
            </PopoverTrigger>
          </TooltipTrigger>
          <TooltipContent side="right" sideOffset={12} className={ONLINE_USER_TOOLTIP_CLASS}>
            <p className="text-[13px] font-semibold text-foreground">
              {isOnline ? 'You are online' : 'You are offline'}
            </p>
            <p className="text-[12px] leading-relaxed text-muted-foreground/70">
              Click to change your Init visibility
            </p>
          </TooltipContent>
        </Tooltip>
        <PopoverContent
          side="right"
          align="end"
          sideOffset={12}
          className="w-[240px] border border-border bg-popover p-3 shadow-md"
        >
          <PresenceStatusPanel
            name={name}
            participantStatus={participantStatus}
            isUpdating={isParticipantStatusUpdating}
            onStatusChange={handleStatusChange}
          />
        </PopoverContent>
      </Popover>
    )
  }

  return (
    <div className={cn('px-2.5 py-3', isMobile && 'px-0 py-0')}>
      <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/70">
        Your status
      </p>
      <PresenceStatusPanel
        name={name}
        participantStatus={participantStatus}
        isUpdating={isParticipantStatusUpdating}
        onStatusChange={handleStatusChange}
      />
    </div>
  )
}
