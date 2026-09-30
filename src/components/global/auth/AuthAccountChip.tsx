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
  onSwitchAccount: () => void | Promise<void>
  disabled?: boolean
}

export function AuthAccountChip({
  accountLabel,
  onSwitchAccount,
  disabled = false,
}: AuthAccountChipProps) {
  const t = useT()
  const accountInitial = (accountLabel || '?').charAt(0).toUpperCase()

  return (
    <div className="flex max-w-full flex-wrap items-center justify-center gap-x-2 gap-y-1.5">
      <p className="text-muted-foreground shrink-0 text-[12px]">
        {t("You're signed in as")}
      </p>
      <DropdownMenu>
        <DropdownMenuTrigger asChild disabled={disabled}>
          <button
            type="button"
            className="text-foreground border-border hover:bg-muted/50 flex max-w-full cursor-pointer items-center gap-1.5 rounded-lg border bg-muted/30 px-2.5 py-1.5 text-[12px] font-medium transition disabled:opacity-60"
          >
            <span className="bg-muted text-muted-foreground flex size-5 items-center justify-center rounded-md text-[10px] font-semibold">
              {accountInitial}
            </span>
            <span className="truncate">{accountLabel}</span>
            <ChevronDown className="text-muted-foreground size-3.5 shrink-0" />
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
    </div>
  )
}
