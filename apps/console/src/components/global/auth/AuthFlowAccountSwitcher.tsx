import type { Models } from '@appwrite.io/console'
import { AuthAccountChip } from '@/components/global/auth/AuthAccountChip'
import { useAuthAccountSwitch } from '@/components/global/auth/useAuthAccountSwitch'
import { useAuth } from '@/components/global/auth/RequireAuth'

type AuthFlowAccountSwitcherProps = {
  accountLabel?: string
  /**
   * Console user ID matching `accountLabel` when the label is not the
   * signed-in account (e.g. the operator while impersonating). Defaults to
   * the signed-in account.
   */
  accountId?: string
  disabled?: boolean
  preview?: boolean
  returnUrl?: string
  onSwitchAccount?: () => void | Promise<void>
}

export function AuthFlowAccountSwitcher({
  accountLabel: accountLabelProp,
  accountId,
  disabled = false,
  preview = false,
  returnUrl,
  onSwitchAccount,
}: AuthFlowAccountSwitcherProps) {
  const { account: accountUnknown, isAuthenticated } = useAuth()
  const account = accountUnknown as Models.User | undefined
  const defaultSwitch = useAuthAccountSwitch({ preview, returnUrl })

  const resolvedLabel =
    accountLabelProp ??
    (isAuthenticated ? account?.email || account?.name : undefined)

  if (!resolvedLabel?.trim()) return null

  return (
    <AuthAccountChip
      accountLabel={resolvedLabel}
      userId={accountId ?? (isAuthenticated ? account?.$id : undefined)}
      onSwitchAccount={onSwitchAccount ?? defaultSwitch}
      disabled={disabled}
    />
  )
}
