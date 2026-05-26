import type { Models } from '@appwrite.io/console'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { InitTicketPrefs } from '@/lib/init/ticket-prefs'
import {
  INIT_TICKET_MAX_STACK,
  INIT_TICKET_STACK_OPTIONS,
  type InitTicketStackId,
} from '@/lib/init/ticket-stack'
import { cn } from '@/lib/utils'
import { toast } from 'sonner'

interface InitTicketCustomizeDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  prefs: InitTicketPrefs
  updatePrefs: (patch: Partial<InitTicketPrefs>) => void
  account?: Models.User | null
  defaultHolderTitle: string
}

export function InitTicketCustomizeDrawer({
  open,
  onOpenChange,
  prefs,
  updatePrefs,
  account,
  defaultHolderTitle,
}: InitTicketCustomizeDrawerProps) {
  const accountName =
    account?.name?.trim() || account?.email?.split('@')[0] || undefined

  const toggleStackItem = (id: InitTicketStackId) => {
    const current = prefs.stack ?? []
    if (current.includes(id)) {
      updatePrefs({ stack: current.filter((item) => item !== id) })
      return
    }
    if (current.length >= INIT_TICKET_MAX_STACK) {
      toast.error(`Choose up to ${INIT_TICKET_MAX_STACK} technologies`)
      return
    }
    updatePrefs({ stack: [...current, id] })
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title="Customize ticket"
      description="Personalize your Init pass before you share it."
      maxWidth="sm:max-w-md"
    >
      <div className="border-t border-border" />
      <div className="flex-1 overflow-y-auto px-6 py-6 space-y-6">
        <div className="space-y-2">
          <Label htmlFor="init-ticket-display-name" className="text-[13px]">
            Name on ticket
          </Label>
          <Input
            id="init-ticket-display-name"
            value={prefs.displayName ?? accountName ?? ''}
            placeholder={accountName ?? 'Your name'}
            onChange={(e) => updatePrefs({ displayName: e.target.value })}
            className="h-9 text-[13px]"
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="init-ticket-holder-title" className="text-[13px]">
            Title on ticket
          </Label>
          <Input
            id="init-ticket-holder-title"
            value={prefs.holderTitle ?? ''}
            placeholder={defaultHolderTitle}
            onChange={(e) => {
              const value = e.target.value
              updatePrefs({ holderTitle: value.trim() ? value : undefined })
            }}
            className="h-9 text-[13px]"
          />
        </div>

        <div className="space-y-3">
          <div className="space-y-1">
            <p className="text-[13px] font-medium text-foreground">Your stack</p>
            <p className="text-[12px] text-muted-foreground">
              Pick up to {INIT_TICKET_MAX_STACK} technologies you build with. Icons
              appear on your ticket.
            </p>
          </div>
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
            {INIT_TICKET_STACK_OPTIONS.map((option) => {
              const selected = prefs.stack.includes(option.id)
              return (
                <button
                  key={option.id}
                  type="button"
                  onClick={() => toggleStackItem(option.id)}
                  className={cn(
                    'flex flex-col items-center gap-1.5 rounded-lg border px-2 py-2.5 text-[11px] font-medium transition-colors',
                    selected
                      ? 'border-foreground/30 bg-accent text-foreground'
                      : 'border-border bg-background text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                  )}
                  aria-pressed={selected}
                >
                  <FrameworkIcon
                    framework={option.iconKey}
                    size="sm"
                    className="shrink-0"
                  />
                  <span className="line-clamp-2 text-center leading-tight">
                    {option.label}
                  </span>
                </button>
              )
            })}
          </div>
        </div>
      </div>
    </BaseDrawer>
  )
}
