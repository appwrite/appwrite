import { AuthAccountChip } from '@/components/global/auth/AuthAccountChip'
import { useAuthAccountSwitch } from '@/components/global/auth/useAuthAccountSwitch'

/** Account switcher when the label is mock/demo and `useAuth` may be unavailable. */
export function AuthFlowAccountSwitcherStatic({
  accountLabel,
  disabled = false,
  preview = false,
  returnUrl,
  onSwitchAccount,
}: {
  accountLabel: string
  disabled?: boolean
  preview?: boolean
  returnUrl?: string
  onSwitchAccount?: () => void | Promise<void>
}) {
  const defaultSwitch = useAuthAccountSwitch({ preview, returnUrl })

  if (!accountLabel.trim()) return null

  return (
    <AuthAccountChip
      accountLabel={accountLabel}
      onSwitchAccount={onSwitchAccount ?? defaultSwitch}
      disabled={disabled}
    />
  )
}
