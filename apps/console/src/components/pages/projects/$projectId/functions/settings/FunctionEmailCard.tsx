import { useEffect, useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Query } from '@appwrite.io/console'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DomainInput } from '@/components/global/shared/DomainInput'
import {
  buildFunctionUpdateParams,
  createSmtpDomainRule,
  useFunctionDomains,
} from '@/lib/react-query/hooks'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Mail } from 'lucide-react'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import {
  functionEmailAddress,
  isFunctionEmailEnabled,
  putFunction,
  suggestFunctionEmailLabel,
  type FunctionWithEmail,
} from '@/lib/function-email'
import { isSmtpRule } from '@/lib/function-smtp-domain'

/** One page is plenty: a function rarely has more than a few addresses. */
const ADDRESSES_LIMIT = 25
const SMTP_QUERIES = [Query.equal('protocol', ['smtp'])]

/** Lets sibling settings cards wait for an in-flight email update. */
export function functionEmailMutationKey(
  functionId: string | null | undefined,
) {
  return ['function', 'email', functionId]
}

interface FunctionEmailCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
  region: string | undefined
  func: FunctionWithEmail
  disabled?: boolean
}

/**
 * Opt-in inbound email (SMTP) trigger, next to Schedule and Events.
 *
 * A function receives mail at the email domains stored for it as SMTP rules.
 * While it has none, the card offers a `{label}.{zone}` domain, suggested from
 * the function name with a random part like Function and Site domains, and
 * saves it together with the trigger. Custom domains are added on the
 * Domains tab.
 */
export function FunctionEmailCard({
  projectId,
  functionId,
  region,
  func,
  disabled = false,
}: FunctionEmailCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { emailDomain } = useConsoleVariables(region)
  const savedEnabled = isFunctionEmailEnabled(func)
  const [emailEnabled, setEmailEnabled] = useSyncStateFromServer(savedEnabled)

  const { data: domainsData } = useFunctionDomains(
    projectId,
    functionId,
    0,
    ADDRESSES_LIMIT,
    undefined,
    SMTP_QUERIES,
  )
  const addresses = useMemo(
    () => (domainsData?.rules ?? []).filter(isSmtpRule),
    [domainsData],
  )
  // Only offer a new domain once the list is known to be empty.
  const needsAddress =
    !!emailDomain && domainsData !== undefined && addresses.length === 0

  const suggestion = useMemo(
    () =>
      emailDomain
        ? `${suggestFunctionEmailLabel(func.name)}.${emailDomain}`
        : '',
    [emailDomain, func.name],
  )
  const [domain, setDomain] = useState('')
  const [domainValid, setDomainValid] = useState(false)
  useEffect(() => {
    if (domain === '' && suggestion !== '') setDomain(suggestion)
  }, [domain, suggestion])

  const createsAddress = emailEnabled && needsAddress

  const updateMutation = useMutation({
    mutationKey: functionEmailMutationKey(functionId),
    mutationFn: async (nextEnabled: boolean) => {
      if (!projectId || !functionId)
        throw new Error('Project ID and Function ID are required')
      let updated: FunctionWithEmail = func
      if (nextEnabled !== savedEnabled) {
        const projectSdk = sdk.forProject(projectId)
        const params = buildFunctionUpdateParams(func, {}) as {
          functionId: string
        } & Record<string, unknown>
        updated = await putFunction(projectSdk.client, {
          ...params,
          emailEnabled: nextEnabled,
        })
        queryClient.setQueryData(
          ['function', 'project', projectId, functionId],
          updated,
        )
      }
      // Cloud only stores an email domain for a function that takes mail,
      // so the trigger is saved first.
      if (nextEnabled && needsAddress) {
        await createSmtpDomainRule(projectId, { domain, functionId })
      }
      return updated
    },
    onSuccess: (_updated, nextEnabled) => {
      toast.success(
        nextEnabled !== savedEnabled
          ? nextEnabled
            ? t('Email trigger enabled')
            : t('Email trigger disabled')
          : t('Email address added'),
      )
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update email trigger')))
      // The trigger may have saved before the domain failed: show what is
      // stored now.
      const current = queryClient.getQueryData<FunctionWithEmail>([
        'function',
        'project',
        projectId,
        functionId,
      ])
      setEmailEnabled(isFunctionEmailEnabled(current ?? func))
    },
    onSettled: () => {
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: ['proxy-rules', 'function', projectId, functionId],
      })
    },
  })

  const pending = disabled || updateMutation.isPending
  const changed = emailEnabled !== savedEnabled || createsAddress
  const canUpdate = changed && !pending && (!createsAddress || domainValid)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Email')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Allow inbound email to trigger this function. It is off by default, so no mail is accepted until you enable it.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex items-center justify-between gap-4">
          <div className="min-w-0">
            <Label htmlFor="function-email-enabled" className="text-[13px]">
              {t('Email trigger')}
            </Label>
            <p className="text-[12px] text-muted-foreground mt-1">
              {emailEnabled
                ? t('Inbound mail for this function is accepted.')
                : t('Inbound mail for this function is rejected.')}
            </p>
          </div>
          <Switch
            id="function-email-enabled"
            checked={emailEnabled}
            onCheckedChange={setEmailEnabled}
            disabled={pending}
            className="shrink-0"
          />
        </div>
        {addresses.length > 0 ? (
          <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/30 px-3 py-3">
            <Mail className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1 space-y-2">
              <p className="text-[12px] font-medium text-foreground">
                {addresses.length === 1 ? t('Address') : t('Addresses')}
              </p>
              {addresses.map((rule) => (
                <div key={rule.$id} className="flex items-center gap-2">
                  <CopyableId
                    id={functionEmailAddress(rule.domain)}
                    size="md"
                    maxWidth={420}
                    showCopyOnHover={false}
                    copyToastLabel="Email"
                    className="max-w-full"
                  />
                  {String(rule.status) !== 'verified' ? (
                    <Badge variant="warning" className="text-[10px] shrink-0">
                      {t('Unverified')}
                    </Badge>
                  ) : null}
                </div>
              ))}
              <p className="text-[12px] text-muted-foreground">
                {t('Any local part works, so mail to')}{' '}
                <code className="font-mono">orders@{addresses[0].domain}</code>{' '}
                {t('reaches this function too.')}
              </p>
              {!emailEnabled ? (
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    'Enable the email trigger to receive mail at these addresses.',
                  )}
                </p>
              ) : !func.deploymentId ? (
                <p className="text-[12px] text-amber-600 dark:text-amber-400">
                  {t(
                    'Mail is delivered when this function has an active deployment.',
                  )}
                </p>
              ) : null}
            </div>
          </div>
        ) : createsAddress ? (
          <div className="space-y-1">
            <DomainInput
              value={domain}
              onChange={setDomain}
              onValidChange={setDomainValid}
              baseDomain={emailDomain ?? ''}
              placeholder="my-function"
              disabled={pending}
            />
            <p className="text-[12px] text-muted-foreground">
              {t(
                'This address is saved with the email trigger. Any local part works.',
              )}
            </p>
          </div>
        ) : null}
        {projectId && functionId ? (
          <p className="text-[12px] text-muted-foreground">
            <Link
              to="/projects/$projectId/functions/$functionId/domains"
              params={{ projectId, functionId }}
              className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
            >
              {t('Manage email domains')}
            </Link>{' '}
            {t('to add another address or a custom domain.')}
          </p>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!canUpdate}
          onClick={() => updateMutation.mutate(emailEnabled)}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
