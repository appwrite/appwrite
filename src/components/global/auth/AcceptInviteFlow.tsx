import { AuthFlowAccountSwitcher } from '@/components/global/auth/AuthFlowAccountSwitcher'
import { AuthFlowAccountSwitcherStatic } from '@/components/global/auth/AuthFlowAccountSwitcherStatic'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import {
  Building2,
  Check,
  Loader2,
  TriangleAlert,
  UserRound,
} from 'lucide-react'

export type AcceptInviteScreen =
  | 'loading'
  | 'accept'
  | 'wrong-account'
  | 'invalid'
  | 'error'
  | 'success'

export type AcceptInviteFlowProps = {
  screen: AcceptInviteScreen
  teamName?: string | null
  accountLabel?: string
  errorMessage?: string | null
  errorIsAccountMismatch?: boolean
  isBusy?: boolean
  preview?: boolean
  onAccept?: () => void
  onSwitchAccount?: () => void | Promise<void>
  onGoToDashboard?: () => void
}

function InviteAccountSwitcher({
  accountLabel,
  preview,
  disabled,
  onSwitchAccount,
}: {
  accountLabel?: string
  preview: boolean
  disabled?: boolean
  onSwitchAccount?: () => void | Promise<void>
}) {
  if (preview && accountLabel) {
    return (
      <AuthFlowAccountSwitcherStatic
        accountLabel={accountLabel}
        preview
        disabled={disabled}
      />
    )
  }
  if (accountLabel) {
    return (
      <AuthFlowAccountSwitcherStatic
        accountLabel={accountLabel}
        preview={preview}
        disabled={disabled}
        onSwitchAccount={onSwitchAccount}
      />
    )
  }
  return (
    <AuthFlowAccountSwitcher
      disabled={disabled}
      onSwitchAccount={onSwitchAccount}
    />
  )
}

export function AcceptInviteFlow({
  screen,
  teamName,
  accountLabel,
  errorMessage,
  errorIsAccountMismatch = false,
  isBusy = false,
  preview = false,
  onAccept,
  onSwitchAccount,
  onGoToDashboard,
}: AcceptInviteFlowProps) {
  const t = useT()

  const accountSwitcher =
    screen === 'loading' || (preview && !accountLabel) ? null : (
      <InviteAccountSwitcher
        accountLabel={accountLabel}
        preview={preview}
        disabled={isBusy}
        onSwitchAccount={onSwitchAccount}
      />
    )

  if (screen === 'loading') {
    return (
      <AuthFlowShell width="narrow" showLegal={false}>
        <AuthFlowNarrowCard>
          <div className="flex flex-col items-center py-8 text-center">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground motion-reduce:animate-none" />
          </div>
        </AuthFlowNarrowCard>
      </AuthFlowShell>
    )
  }

  const inviteDescription = teamName ? (
    <>
      {t("You've been invited to join")}{' '}
      <span className="text-foreground font-medium break-words">{teamName}</span>
      {'. '}
      {t('Accept the invitation to get started.')}
    </>
  ) : (
    t(
      "You've been invited to join an organization. Accept the invitation to get started.",
    )
  )

  return (
    <AuthFlowShell width="narrow" accountSwitcher={accountSwitcher}>
      <AuthFlowNarrowCard>
        <div className="space-y-6">
          {screen === 'success' ? (
            <div className="flex flex-col items-center gap-4 text-center">
              <AuthFlowHeaderIcon icon={Check} />
              <div className="space-y-1">
                <AuthFlowTitle>{t('Welcome to the organization!')}</AuthFlowTitle>
                <AuthFlowDescription>
                  {t("You've successfully joined. Redirecting you now...")}
                </AuthFlowDescription>
              </div>
            </div>
          ) : screen === 'wrong-account' ? (
            <>
              <div className="flex flex-col items-center gap-4 text-center">
                <AuthFlowHeaderIcon icon={UserRound} />
                <div className="space-y-1">
                  <AuthFlowTitle>
                    {t("You're signed in with a different account")}
                  </AuthFlowTitle>
                  <AuthFlowDescription>
                    {t('This invitation was sent to a different account.')}{' '}
                    {t(
                      'Switch to the account the invitation was sent to in order to accept it.',
                    )}
                  </AuthFlowDescription>
                </div>
              </div>
              <div className="flex flex-col gap-2">
                <Button
                  variant="brandCta"
                  className="w-full"
                  onClick={() => void onSwitchAccount?.()}
                  disabled={isBusy}
                >
                  {t('Use a different account')}
                </Button>
                <Button
                  variant="outline"
                  className="w-full"
                  onClick={onGoToDashboard}
                  disabled={isBusy}
                >
                  {t('Go to dashboard')}
                </Button>
              </div>
            </>
          ) : screen === 'error' ? (
            <>
              <div className="flex flex-col items-center gap-4 text-center">
                <AuthFlowHeaderIcon icon={TriangleAlert} variant="destructive" />
                <div className="space-y-1">
                  <AuthFlowTitle>{t('Unable to accept invitation')}</AuthFlowTitle>
                  <AuthFlowDescription>
                    {errorMessage ?? t('Failed to accept invitation')}
                  </AuthFlowDescription>
                </div>
              </div>
              <div className="flex flex-col gap-2">
                {errorIsAccountMismatch ? (
                  <Button
                    variant="brandCta"
                    className="w-full"
                    onClick={() => void onSwitchAccount?.()}
                    disabled={isBusy}
                  >
                    {t('Use a different account')}
                  </Button>
                ) : null}
                <Button
                  variant="outline"
                  className="w-full"
                  onClick={onGoToDashboard}
                  disabled={isBusy}
                >
                  {t('Go to dashboard')}
                </Button>
              </div>
            </>
          ) : screen === 'invalid' ? (
            <>
              <div className="flex flex-col items-center gap-4 text-center">
                <AuthFlowHeaderIcon icon={TriangleAlert} variant="destructive" />
                <div className="space-y-1">
                  <AuthFlowTitle>{t('Invalid invitation link')}</AuthFlowTitle>
                  <AuthFlowDescription>
                    {t(
                      'This invitation link is missing required parameters. Please use the link from your invitation email.',
                    )}
                  </AuthFlowDescription>
                </div>
              </div>
              <Button
                variant="outline"
                className="w-full"
                onClick={onGoToDashboard}
              >
                {t('Go to dashboard')}
              </Button>
            </>
          ) : (
            <>
              <div className="flex flex-col items-center gap-4 text-center">
                <AuthFlowHeaderIcon icon={Building2} />
                <div className="space-y-1">
                  <AuthFlowTitle>{t('Accept invitation')}</AuthFlowTitle>
                  <AuthFlowDescription>{inviteDescription}</AuthFlowDescription>
                </div>
              </div>
              <div className="flex flex-col gap-2">
                <Button
                  variant="brandCta"
                  className="w-full"
                  onClick={onAccept}
                  disabled={isBusy}
                >
                  {t('Accept invitation')}
                </Button>
              </div>
            </>
          )}
        </div>
      </AuthFlowNarrowCard>
    </AuthFlowShell>
  )
}
