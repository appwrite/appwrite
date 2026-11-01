import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { sdk } from '@/lib/appwrite/sdk'
import {
  getAlertsUnsubscribe,
  setAlertEmails,
  type AlertsUnsubscribe,
  type UnsubscribeScope,
} from '@/lib/appwrite/monitors/unsubscribe'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

/**
 * Where an alert email's Unsubscribe link lands. It works without a session,
 * names the monitor the email came from, and stops (or undoes stopping) that
 * monitor's emails or every alert email of its type, as the type allows. It
 * acts only on a click, since mail scanners open links.
 */
export function View({ token }: { token: string }) {
  const t = useT()
  const queryClient = useQueryClient()
  const queryKey = ['alerts', 'unsubscribe', token]

  const page = useQuery({
    queryKey,
    queryFn: () => getAlertsUnsubscribe(sdk.forConsole.client, token),
    enabled: token !== '',
    retry: false,
  })

  const mutation = useMutation({
    mutationFn: ({ scope, stop }: { scope: UnsubscribeScope; stop: boolean }) =>
      setAlertEmails(
        sdk.forConsole.client,
        stop ? 'post' : 'delete',
        token,
        scope,
      ),
    onSuccess: (_, { scope, stop }) =>
      queryClient.setQueryData<AlertsUnsubscribe>(queryKey, (current) =>
        current ? { ...current, [scope]: stop } : current,
      ),
  })

  const invalid = token === '' || page.isError
  const data = page.data

  const choices: {
    scope: UnsubscribeScope
    label: string
    stopped: string
  }[] = data
    ? [
        {
          scope: 'monitor',
          label: t('Stop emails for this monitor'),
          stopped: t('Emails for this monitor are stopped.'),
        },
        ...(data.optOut
          ? [
              {
                scope: 'all' as const,
                label:
                  data.type === 'usage'
                    ? t('Stop all usage alert emails')
                    : t('Stop all alert emails of this kind'),
                stopped:
                  data.type === 'usage'
                    ? t('All usage alert emails are stopped.')
                    : t('All alert emails of this kind are stopped.'),
              },
            ]
          : []),
      ]
    : []

  return (
    <AuthFlowShell width="narrow" showLegal={false}>
      <AuthFlowNarrowCard>
        {invalid ? (
          <div className="space-y-2">
            <AuthFlowTitle>
              {t('This link is invalid or has expired')}
            </AuthFlowTitle>
            <AuthFlowDescription>
              {t('Open the Unsubscribe link in a more recent alert email.')}
            </AuthFlowDescription>
          </div>
        ) : (
          <div className="space-y-6">
            <div className="space-y-2">
              <AuthFlowTitle>
                {t('Unsubscribe from alert emails')}
              </AuthFlowTitle>
              <div className="min-h-5 text-[14px] text-muted-foreground">
                {data ? (
                  data.monitorName === '' ? (
                    t('This monitor was deleted.')
                  ) : (
                    <span className="break-words">
                      {[data.monitorName, data.projectName]
                        .filter(Boolean)
                        .join(' · ')}
                    </span>
                  )
                ) : (
                  <Skeleton className="h-5 w-48" />
                )}
              </div>
            </div>

            <ul className="divide-y divide-border rounded-lg border border-border">
              {data
                ? choices.map((choice) => {
                    const stopped = data[choice.scope]
                    const pending =
                      mutation.isPending &&
                      mutation.variables?.scope === choice.scope
                    return (
                      <li
                        key={choice.scope}
                        className="flex min-h-16 items-center justify-between gap-3 px-4 py-3"
                      >
                        <span className="min-w-0 text-[13px]">
                          {stopped ? (
                            <span className="flex items-start gap-2 text-foreground">
                              <Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                              {choice.stopped}
                            </span>
                          ) : (
                            <span className="font-medium text-foreground">
                              {choice.label}
                            </span>
                          )}
                        </span>
                        <Button
                          type="button"
                          size="sm"
                          variant={stopped ? 'ghost' : 'outline'}
                          className="h-8 shrink-0 text-[13px]"
                          disabled={mutation.isPending}
                          aria-label={
                            stopped
                              ? `${t('Undo')}: ${choice.label}`
                              : choice.label
                          }
                          onClick={() =>
                            mutation.mutate({
                              scope: choice.scope,
                              stop: !stopped,
                            })
                          }
                        >
                          {pending
                            ? t('Saving...')
                            : stopped
                              ? t('Undo')
                              : t('Stop')}
                        </Button>
                      </li>
                    )
                  })
                : [0, 1].map((row) => (
                    <li
                      key={row}
                      className="flex min-h-16 items-center justify-between gap-3 px-4 py-3"
                    >
                      <Skeleton className="h-4 w-44" />
                      <Skeleton className="h-8 w-14" />
                    </li>
                  ))}
            </ul>

            {mutation.isError && (
              <p className="text-[13px] text-destructive">
                {getErrorMessage(
                  mutation.error,
                  t('This link is invalid or has expired'),
                )}
              </p>
            )}

            <p className="text-[13px] text-muted-foreground">
              {t("You'll still see these alerts in console notifications.")}
            </p>
          </div>
        )}
      </AuthFlowNarrowCard>
    </AuthFlowShell>
  )
}
