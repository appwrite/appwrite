import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Loader2, RotateCcw } from 'lucide-react'
import { toast } from 'sonner'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { DebugMenuSwitch } from '@/components/global/providers/DebugMenuSwitch'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { getActiveLaunchEvent } from '@/lib/init/events'
import { resetInitTicketImagePrefs } from '@/lib/init/reset-init-ticket-image-prefs'
import {
  readInitTicketPrefsFromAccountPrefs,
  readInitTicketPrefsFromStorage,
} from '@/lib/init/ticket-prefs'
import {
  formatInitMockTicketType,
  type InitTicketTypeId,
} from '@/lib/init/ticket-types'
import {
  loadDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'

const TICKET_TYPE_OPTIONS: {
  id: InitTicketTypeId
  label: string
  description: string
}[] = [
  {
    id: 'standard',
    label: 'Standard',
    description: 'Theme-based light or dark pass',
  },
  {
    id: 'silver',
    label: 'Silver VIP',
    description: '3+ year members · Appwrite VIP',
  },
  {
    id: 'gold',
    label: 'Gold contributor',
    description: 'Verified @appwrite.io · Contributor pass',
  },
]

export function DebugMenuInitTicketPanel() {
  const [overrides, setOverrides] = useState(loadDebugOverrides)
  const [isResettingImage, setIsResettingImage] = useState(false)
  const { account, isAuthenticated } = useAuth()
  const queryClient = useQueryClient()
  const activeEvent = getActiveLaunchEvent()
  const mockEnabled = overrides.mockInitTicketType !== null
  const selectedType = overrides.mockInitTicketType ?? 'standard'

  const storedTicketPrefs =
    account && activeEvent
      ? (readInitTicketPrefsFromAccountPrefs(
          account.prefs as Record<string, unknown> | undefined,
          activeEvent.id,
        ) ??
        readInitTicketPrefsFromStorage(activeEvent.id, account.$id))
      : null
  const hasStoredTicketImage = Boolean(storedTicketPrefs?.imageFileId)

  useEffect(() => subscribeToDebugOverrides(setOverrides), [])

  const handleResetTicketImage = async () => {
    if (!account || !activeEvent || isResettingImage) return

    setIsResettingImage(true)
    try {
      await resetInitTicketImagePrefs({
        eventId: activeEvent.id,
        userId: account.$id,
        accountPrefs: account.prefs as Record<string, unknown> | undefined,
        queryClient,
      })
      toast.success(
        typeof window !== 'undefined' &&
          window.location.pathname.startsWith('/init')
          ? 'Ticket image reset. Regenerating now on /init.'
          : 'Ticket image reset. Open /init to regenerate your share image.',
      )
    } catch {
      toast.error('Could not reset ticket image')
    } finally {
      setIsResettingImage(false)
    }
  }

  return (
    <div className="space-y-4 px-1 py-1" aria-label="Init ticket mock">
      <div className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5">
        <div className="min-w-0 flex-1">
          <p className="text-[13px] font-medium text-foreground">Mock ticket type</p>
          <p className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
            {mockEnabled
              ? formatInitMockTicketType(selectedType)
              : 'Use account rules on /init'}
          </p>
        </div>
        <DebugMenuSwitch
          checked={mockEnabled}
          onCheckedChange={(checked) => {
            setDebugOverride(
              'mockInitTicketType',
              checked ? selectedType : null,
            )
          }}
          className="shrink-0"
        />
      </div>

      {mockEnabled ? (
        <div className="space-y-2 rounded-lg px-3 pb-3">
          {TICKET_TYPE_OPTIONS.map((option) => {
            const active = selectedType === option.id
            return (
              <button
                key={option.id}
                type="button"
                onClick={() => setDebugOverride('mockInitTicketType', option.id)}
                className={cn(
                  'flex w-full flex-col rounded-lg border px-3 py-2.5 text-start transition-colors',
                  active
                    ? 'border-[color-mix(in_srgb,var(--network-globe-edge)_60%,var(--border))] bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)]'
                    : 'border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)]',
                )}
              >
                <span className="text-[13px] font-medium text-foreground">
                  {option.label}
                </span>
                <span className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
                  {option.description}
                </span>
              </button>
            )
          })}
          <p className="pt-1 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
            Overrides gold, silver, and standard rules while enabled. Sign in on
            /init to preview the ticket.
          </p>
        </div>
      ) : null}

      <div className="rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] px-3 py-3">
        <div className="space-y-1">
          <p className="text-[13px] font-medium text-foreground">
            Reset ticket image
          </p>
          <p className="text-[11px] leading-relaxed text-[var(--network-globe-edge)]/80">
            {isAuthenticated
              ? hasStoredTicketImage
                ? 'Clears the saved share image from your prefs. The /init page regenerates and saves a new one automatically.'
                : 'No saved ticket image in your prefs. Open /init to generate one.'
              : 'Sign in to clear your saved ticket image and regenerate it on /init.'}
          </p>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="mt-3 h-8 w-full text-[12px]"
          disabled={!isAuthenticated || !activeEvent || isResettingImage}
          onClick={() => void handleResetTicketImage()}
        >
          {isResettingImage ? (
            <>
              <Loader2 className="me-1.5 size-3.5 animate-spin" />
              Resetting
            </>
          ) : (
            <>
              <RotateCcw className="me-1.5 size-3.5" />
              Reset ticket image
            </>
          )}
        </Button>
      </div>
    </div>
  )
}
