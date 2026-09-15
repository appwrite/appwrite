'use client'

import { ArrowLeftRight, Check, ChevronDown } from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { useT } from '@/lib/i18n/translate'

type AuthAccountChipProps = {
  accountLabel: string
  /** When provided, the chip opens a menu with "Use a different account". */
  onSwitchAccount?: () => void | Promise<void>
  disabled?: boolean
}

export function AuthAccountChip({
  accountLabel,
  onSwitchAccount,
  disabled = false,
}: AuthAccountChipProps) {
  const t = useT()
  const accountInitial = (accountLabel || '?').charAt(0).toUpperCase()

  if (!onSwitchAccount) {
    return (
      <p className="text-muted-foreground text-[12px]">
        {t('Signed in as')}{' '}
        <span className="text-foreground font-medium">{accountLabel}</span>
      </p>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild disabled={disabled}>
        <button
          type="button"
          className="cursor-pointer text-muted-foreground hover:text-foreground border-border hover:bg-muted/50 flex max-w-full items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-[12px] transition disabled:opacity-60"
        >
          <span className="bg-muted text-muted-foreground flex size-5 items-center justify-center rounded-md text-[10px] font-semibold">
            {accountInitial}
          </span>
          <span className="truncate">{accountLabel}</span>
          <ChevronDown className="size-3.5 shrink-0" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="center" className="w-72">
        <div className="flex items-center gap-2 px-2 py-1.5">
          <span className="bg-muted text-muted-foreground flex size-7 shrink-0 items-center justify-center rounded-md text-xs font-semibold">
            {accountInitial}
          </span>
          <span className="min-w-0 flex-1 truncate text-start text-[13px]">
            {accountLabel}
          </span>
          <Check className="text-muted-foreground size-4 shrink-0" />
        </div>
        <DropdownMenuItem
          disabled={disabled}
          onSelect={() => void onSwitchAccount()}
        >
          <MenuItemContent icon={ArrowLeftRight}>
            {t('Use a different account')}
          </MenuItemContent>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
