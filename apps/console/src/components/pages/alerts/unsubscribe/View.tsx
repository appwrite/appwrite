import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { Button } from '@/components/ui/button'
import { sdk } from '@/lib/appwrite/sdk'
import {
  setAlertEmails,
  unsubscribeScope,
} from '@/lib/appwrite/monitors/unsubscribe'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

/**
 * Where an alert email's unsubscribe links land: it confirms before stopping
 * the emails, since mail scanners open links, and offers to resume them.
 */
export function View({ token }: { token: string }) {
  const t = useT()
  const scope = unsubscribeScope(token)
  const [stopped, setStopped] = useState(false)

  const mutation = useMutation({
    mutationFn: (method: 'post' | 'delete') =>
      setAlertEmails(sdk.forConsole.client, method, token),
    onSuccess: (_, method) => setStopped(method === 'post'),
  })

  const description =
    scope === null
      ? t('Open the latest link from an alert email.')
      : scope.kind === 'monitor'
        ? stopped
          ? t(
              'You will no longer get emails for this monitor. Console notifications continue.',
            )
          : t(
              'Stop getting emails for this monitor? Console notifications continue.',
            )
        : stopped
          ? t(
              'You will no longer get alert emails of this kind. Console notifications continue.',
            )
          : t(
              'Stop getting every alert email of this kind? Console notifications continue.',
            )

  return (
    <AuthFlowShell width="narrow" showLegal={false}>
      <AuthFlowNarrowCard>
        <div className="space-y-6">
          <div className="space-y-2">
            <AuthFlowTitle>
              {scope === null
                ? t('This link is not valid')
                : stopped
                  ? t('Alert emails stopped')
                  : t('Stop alert emails?')}
            </AuthFlowTitle>
            <AuthFlowDescription>{description}</AuthFlowDescription>
            {mutation.isError && (
              <p className="text-[13px] text-destructive">
                {getErrorMessage(
                  mutation.error,
                  t('This link is not valid or has expired.'),
                )}
              </p>
            )}
          </div>
          {scope !== null && (
            <Button
              type="button"
              variant={stopped ? 'outline' : 'brandCta'}
              className="w-full"
              disabled={mutation.isPending}
              onClick={() => mutation.mutate(stopped ? 'delete' : 'post')}
            >
              {stopped ? t('Resume emails') : t('Stop emails')}
            </Button>
          )}
        </div>
      </AuthFlowNarrowCard>
    </AuthFlowShell>
  )
}
