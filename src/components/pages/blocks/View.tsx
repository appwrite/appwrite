import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { BlocksList } from './BlocksList'
import { ComposeBlock } from './ComposeBlock'
import { TargetBar } from './TargetBar'
import { UserStatusPanel } from './UserStatusPanel'

type ImpersonatorAccount = Models.User & { impersonator?: boolean }

export function BlocksConsoleView() {
  const { account: rawAccount } = useAuth()
  const account = rawAccount as ImpersonatorAccount | undefined
  const navigate = useNavigate()
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)

  const [draft, setDraft] = useState('')
  const [focusedProjectId, setFocusedProjectId] = useState<string | null>(null)

  useKeyboardShortcut('meta+k', () => setCommandCenterOpen(true))
  useKeyboardShortcut('control+k', () => setCommandCenterOpen(true))

  useEffect(() => {
    if (!account) return
    if (account.impersonator !== true) {
      navigate({ to: '/account', replace: true })
    }
  }, [account, navigate])

  const handleSubmit = () => {
    const v = draft.trim()
    if (!v) return
    setFocusedProjectId(v)
  }

  const handleClear = () => {
    setDraft('')
    setFocusedProjectId(null)
  }

  return (
    <>
      <ConsoleLayout
        header={{ onCommandCenterOpen: () => setCommandCenterOpen(true) }}
        showFooter
      >
        <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
          <div className="flex flex-col gap-1">
            <h1 className="text-[20px] font-semibold text-foreground">
              Blocks
            </h1>
            <p className="text-[13px] text-muted-foreground">
              Manage resource blocks and user access.
            </p>
          </div>
        </div>

        <div className="border-b border-border" />

        <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
          <div className="space-y-6">
            <TargetBar
              draft={draft}
              onDraftChange={setDraft}
              onSubmit={handleSubmit}
              focusedProjectId={focusedProjectId}
              onClear={handleClear}
            />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
              <BlocksList projectId={focusedProjectId} />
              <ComposeBlock projectId={focusedProjectId} />
            </div>

            <UserStatusPanel />
          </div>
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
