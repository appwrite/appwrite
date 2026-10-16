/**
 * Add SMTP (inbound email) domain wizard.
 *
 * Mirrors AddDomainWizard but creates an SMTP rule verified by MX + TXT
 * records instead of HTTP CNAME / redirect behaviour.
 */

import { useState, useEffect } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  VerifySmtpDomainContent,
  dnsPendingVerificationError,
} from './VerifySmtpDomainContent'
import type { DomainVerificationError } from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import {
  useCreateSmtpDomainRule,
  useProjectFunction,
  useProject,
  useVerifyDomain,
  useDeleteDomain,
} from '@/lib/react-query/hooks'
import {
  ensureApexDomainInOrganization,
  isValidDomain,
} from '@/lib/utils/proxy-domains'
import {
  isFunctionEmailEnabled,
  type FunctionWithEmail,
} from '@/lib/function-email'
import type { SmtpProxyRule } from '@/lib/function-smtp-domain'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useConsoleProfile } from '@/hooks/use-console-profile'

export function AddSmtpDomainWizard() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const { data: func } = useProjectFunction(projectId, functionId)
  const { features } = useConsoleProfile()
  const enabled = features.functionEmailDomains

  const createMutation = useCreateSmtpDomainRule(projectId)
  const verifyMutation = useVerifyDomain(projectId, project?.region)
  const deleteMutation = useDeleteDomain(projectId, project?.region)

  const [domain, setDomain] = useState('')
  const [error, setError] = useState('')
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)
  const [rule, setRule] = useState<SmtpProxyRule | null>(null)

  const fallbackPath = `/projects/${projectId}/functions/${functionId}/domains`
  const isPending =
    createMutation.isPending ||
    verifyMutation.isPending ||
    deleteMutation.isPending

  const emailEnabled = isFunctionEmailEnabled(
    func as FunctionWithEmail | undefined,
  )

  const leave = () => {
    navigate({
      to: '/projects/$projectId/functions/$functionId/domains',
      params: { projectId: projectId!, functionId: functionId! },
    })
  }

  useEffect(() => {
    if (!enabled) {
      navigate({
        to: '/projects/$projectId/functions/$functionId/domains',
        params: { projectId: projectId!, functionId: functionId! },
      })
    }
  }, [enabled, navigate, projectId, functionId])

  if (!enabled) {
    return null
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    const d = domain.trim().toLowerCase()
    setError('')
    if (!d) {
      setError(t('Required'))
      return
    }
    if (!isValidDomain(d)) {
      setError(t('Invalid'))
      return
    }
    if (!emailEnabled) {
      toast.error(
        t(
          'Enable the email trigger under Settings → Executions before adding an email domain.',
        ),
      )
      return
    }

    try {
      await ensureApexDomainInOrganization(project?.teamId ?? '', d)
    } catch (err: unknown) {
      const e = err as { type?: string }
      if (e?.type !== 'domain_already_exists') {
        toast.error(t('Failed to register domain'))
        return
      }
    }

    try {
      const created = await createMutation.mutateAsync({
        domain: d,
        functionId: functionId!,
      })
      if (created.status === 'verified') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'smtp', 'function', projectId, functionId],
        })
        toast.success(t('Email domain verified'))
        leave()
      } else {
        setRule(created)
      }
    } catch (err) {
      toast.error(getErrorMessage(err, t('Failed to add email domain')))
    }
  }

  const handleVerify = async () => {
    if (!rule) return
    setVerificationError(null)
    try {
      const updated = (await verifyMutation.mutateAsync(
        rule.$id,
      )) as SmtpProxyRule
      if (updated.status === 'verified') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'smtp', 'function', projectId, functionId],
        })
        toast.success(t('Email domain verified'))
        leave()
      } else {
        setRule(updated)
        setVerificationError(dnsPendingVerificationError(t))
      }
    } catch {
      setVerificationError(dnsPendingVerificationError(t))
    }
  }

  const handleChange = async () => {
    if (!rule) return
    setVerificationError(null)
    try {
      await deleteMutation.mutateAsync(rule.$id)
      setRule(null)
    } catch {
      toast.error(t('Failed to remove domain'))
    }
  }

  if (rule) {
    return (
      <WizardLayout
        title={t('Verify email domain')}
        fallbackPath={fallbackPath}
        fullscreen
        useSidebar={false}
        maxWidth="max-w-4xl"
        footer={
          <div className="flex gap-2 justify-end w-full">
            <Button
              variant="outline"
              onClick={handleChange}
              disabled={isPending}
            >
              {t('Change')}
            </Button>
            <Button onClick={handleVerify} disabled={isPending}>
              {t('Verify')}
            </Button>
          </div>
        }
      >
        <VerifySmtpDomainContent
          rule={rule}
          region={project?.region}
          verificationError={verificationError}
        />
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title={t('Add email domain')}
      fallbackPath={fallbackPath}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-4xl"
      footer={
        <div className="flex gap-2 justify-end w-full">
          <Button variant="outline" onClick={leave} disabled={isPending}>
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isPending || !domain.trim() || !emailEnabled}
          >
            {t('Add')}
          </Button>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-6">
        {!emailEnabled ? (
          <div className="rounded-xl border border-amber-500/40 bg-amber-500/5 px-6 py-4">
            <p className="text-[13px] text-foreground">
              {t(
                'Enable the email trigger under Settings → Executions before adding an email domain.',
              )}
            </p>
          </div>
        ) : null}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Email domain')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Mail sent to any address on this domain runs this function. This is separate from HTTP domains and from outbound SMTP for the project.',
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <Label className="text-[12px]">{t('Domain name')}</Label>
            <Input
              placeholder="mail.example.com"
              value={domain}
              onChange={(e) => {
                setDomain(e.target.value)
                setError('')
              }}
              className={`font-mono mt-1.5 ${error ? 'border-destructive' : ''}`}
              disabled={createMutation.isPending || !emailEnabled}
            />
            {error ? (
              <p className="text-[11px] text-destructive mt-1">{error}</p>
            ) : (
              <p className="text-[12px] text-muted-foreground mt-2">
                {t(
                  'After adding the domain, publish an MX record and a TXT ownership record, then verify.',
                )}
              </p>
            )}
          </div>
        </div>
      </form>
    </WizardLayout>
  )
}
