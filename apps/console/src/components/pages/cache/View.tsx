import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'
import { ClearCachePanel } from './ClearCachePanel'

export function CacheConsoleView() {
  const { account: rawAccount } = useAuth()
  const account = rawAccount as OperatorAccount | undefined
  const navigate = useNavigate()
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)

  useKeyboardShortcut('meta+k', () => setCommandCenterOpen(true), OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS)
  useKeyboardShortcut('control+k', () => setCommandCenterOpen(true), OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS)

  useEffect(() => {
    if (!account) return
    // Same gate as the Blocks page: allow operators (impersonator flag) and
    // anyone already inside an impersonation session.
    if (!isOperatorAccount(account)) {
      navigate({ to: '/account', replace: true })
    }
  }, [account, navigate])

  return (
    <>
      <ConsoleLayout
        header={{ onCommandCenterOpen: () => setCommandCenterOpen(true) }}
        showFooter
      >
        <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
          <div className="flex flex-col gap-1">
            <h1 className="text-[20px] font-semibold text-foreground">Cache</h1>
            <p className="text-[13px] text-muted-foreground">
              Clear internal caches by region and target.
            </p>
          </div>
        </div>

        <div className="border-b border-border" />

        <div className="mx-auto w-full max-w-2xl px-4 py-6 sm:px-6">
          <ClearCachePanel />
        </div>
      </ConsoleLayout>

      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={setCommandCenterOpen}
        context="account"
      />
    </>
  )
}
