import { useState, useEffect, useRef, type ReactNode } from 'react'
import { toast } from 'sonner'
import type { UseMutationResult } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  useUpdateDenyFreeEmailPolicy,
  useUpdateDenyAliasedEmailPolicy,
  useUpdateDenyDisposableEmailPolicy,
  useUpdateDenyCorporateEmailPolicy,
} from '@/lib/react-query/hooks/auth'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

const EMAIL_POLICIES_DOCS_URL =
  '/docs/products/auth/email-policies'

type EmailPolicyCardProps = {
  currentEnabled: boolean
  title: string
  description: ReactNode
  switchId: string
  switchLabel: string
  mutation: UseMutationResult<unknown, Error, boolean, unknown>
  successMessage: string
  errorMessage: string
}

function EmailPolicyCard({
  currentEnabled,
  title,
  description,
  switchId,
  switchLabel,
  mutation,
  successMessage,
  errorMessage,
}: EmailPolicyCardProps) {
  const t = useT()
  const [enabled, setEnabled] = useState(currentEnabled)
  const lastSubmittedValue = useRef<boolean | null>(null)

  useEffect(() => {
    if (!mutation.isPending) {
      if (
        lastSubmittedValue.current === null ||
        currentEnabled === lastSubmittedValue.current
      ) {
        setEnabled(currentEnabled)
        if (
          lastSubmittedValue.current !== null &&
          currentEnabled === lastSubmittedValue.current
        ) {
          lastSubmittedValue.current = null
        }
      }
    }
  }, [currentEnabled, mutation.isPending])

  const hasChanges = enabled !== currentEnabled

  const handleSubmit = () => {
    lastSubmittedValue.current = enabled
    mutation.mutate(enabled, {
      onSuccess: () => {
        toast.success(successMessage)
      },
      onError: (error: Error) => {
        toast.error(error.message || errorMessage)
        lastSubmittedValue.current = null
      },
    })
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
        <p className="text-[13px] text-muted-foreground mt-1">{description}</p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center gap-3">
          <Switch
            id={switchId}
            checked={enabled}
            onCheckedChange={setEnabled}
            disabled={mutation.isPending}
          />
          <Label
            htmlFor={switchId}
            className="text-[13px] text-foreground cursor-pointer"
          >
            {switchLabel}
          </Label>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || mutation.isPending}
          onClick={handleSubmit}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}

export function DenyFreeEmailCard({
  projectId,
  currentEnabled,
}: {
  projectId: string
  currentEnabled: boolean
}) {
  const t = useT()
  const mutation = useUpdateDenyFreeEmailPolicy(projectId)

  return (
    <EmailPolicyCard
      currentEnabled={currentEnabled}
      title={t('Free emails')}
      description={
        <>
          {t(
            'Reject sign-ups and email updates from free providers such as Gmail, Yahoo, and Outlook. Useful when seats should tie to a work organization. Applies at sign-up and email change only - not to existing sessions.',
          )}{' '}
          <DocsRouteLink className="link-neutral" href={EMAIL_POLICIES_DOCS_URL}>
            {t('Learn more')}
          </DocsRouteLink>
          .
        </>
      }
      switchId="deny-free-email-enabled"
      switchLabel={t('Deny free emails')}
      mutation={mutation}
      successMessage={t('Updated deny free emails policy')}
      errorMessage={t('Failed to update deny free emails policy')}
    />
  )
}

export function DenyAliasedEmailCard({
  projectId,
  currentEnabled,
}: {
  projectId: string
  currentEnabled: boolean
}) {
  const t = useT()
  const mutation = useUpdateDenyAliasedEmailPolicy(projectId)

  return (
    <EmailPolicyCard
      currentEnabled={currentEnabled}
      title={t('Aliased emails')}
      description={t(
        'Reject sign-ups and email updates that use subaddresses, tags, or other provider-specific variations of the same inbox (e.g. user+folder@outlook.com, user+tag@yahoo.com).',
      )}
      switchId="deny-aliased-email-enabled"
      switchLabel={t('Deny aliased emails')}
      mutation={mutation}
      successMessage={t('Updated deny aliased emails policy')}
      errorMessage={t('Failed to update deny aliased emails policy')}
    />
  )
}

export function DenyDisposableEmailCard({
  projectId,
  currentEnabled,
}: {
  projectId: string
  currentEnabled: boolean
}) {
  const t = useT()
  const mutation = useUpdateDenyDisposableEmailPolicy(projectId)

  return (
    <EmailPolicyCard
      currentEnabled={currentEnabled}
      title={t('Disposable emails')}
      description={t(
        'Reject sign-ups and email updates that use disposable or temporary inbox providers (e.g. Mailinator). Appwrite validates each address against a provider database maintained by the Appwrite team, built from public blocklists and other trusted online sources, and refreshed on a regular cadence.', // pragma: allowlist secret
      )}
      switchId="deny-disposable-email-enabled"
      switchLabel={t('Deny disposable emails')}
      mutation={mutation}
      successMessage={t('Updated deny disposable emails policy')}
      errorMessage={t('Failed to update deny disposable emails policy')}
    />
  )
}

export function DenyCorporateEmailCard({
  projectId,
  currentEnabled,
}: {
  projectId: string
  currentEnabled: boolean
}) {
  const t = useT()
  const mutation = useUpdateDenyCorporateEmailPolicy(projectId)

  return (
    <EmailPolicyCard
      currentEnabled={currentEnabled}
      title={t('Corporate emails')}
      description={t(
        'Reject sign-ups and email updates that use corporate or organization-managed domains. Useful when your project should only accept personal email addresses.',
      )}
      switchId="deny-corporate-email-enabled"
      switchLabel={t('Deny corporate emails')}
      mutation={mutation}
      successMessage={t('Updated deny corporate emails policy')}
      errorMessage={t('Failed to update deny corporate emails policy')}
    />
  )
}
