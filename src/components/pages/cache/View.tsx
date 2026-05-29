import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { ClearCachePanel } from './ClearCachePanel'

type ImpersonatorAccount = Models.User & {
  impersonator?: boolean
  impersonatorUserId?: string
}

export function CacheConsoleView() {
  const { account: rawAccount } = useAuth()
  const account = rawAccount as ImpersonatorAccount | undefined
  const navigate = useNavigate()
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)

  useKeyboardShortcut('meta+k', () => setCommandCenterOpen(true))
  useKeyboardShortcut('control+k', () => setCommandCenterOpen(true))

  useEffect(() => {
    if (!account) return
    // Same gate as the Blocks page: allow operators (impersonator flag) and
    // anyone already inside an impersonation session.
    const allowed =
      account.impersonator === true || !!account.impersonatorUserId
    if (!allowed) {
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
