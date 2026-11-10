/**
 * Add SMTP (inbound email) domain wizard.
 *
 * Mirrors AddDomainWizard but creates an SMTP rule. Two kinds: a label under
 * the Appwrite email zone (`{label}.appwrite.email`), suggested from the
 * function name with a random part and verified on create, or a custom
 * domain verified by MX + TXT records instead of HTTP CNAME / redirect
 * behaviour.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { DomainInput } from '@/components/global/shared/DomainInput'
import { DomainTypeCard } from './DomainTypeCard'
import {
  VerifySmtpDomainContent,
  dnsPendingVerificationError,
  domainVerificationError,
} from './VerifySmtpDomainContent'
import type { DomainVerificationError } from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import { ClaimDomainContent } from '@/components/pages/projects/$projectId/settings/domains/ClaimDomainContent'
import {
  useCreateSmtpDomainRule,
  useProjectFunction,
  useProject,
  useVerifyDomain,
  useDeleteDomain,
} from '@/lib/react-query/hooks'
import {
  ensureApexDomainInOrganization,
  isDomainClaimError,
  isValidDomain,
} from '@/lib/utils/proxy-domains'
import {
  isFunctionEmailEnabled,
  suggestFunctionEmailLabel,
  type FunctionWithEmail,
} from '@/lib/function-email'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import type { SmtpProxyRule } from '@/lib/function-smtp-domain'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { isCloudProfile } from '@/lib/console-profiles'
import { cn } from '@/lib/utils'

type EmailDomainKind = 'appwrite' | 'custom'

function kindOption(selected: boolean) {
  return cn(
    'text-start rounded-lg border p-4 transition-all cursor-pointer',
    selected
      ? 'border-foreground bg-primary/5'
      : 'border-border hover:border-muted-foreground/50',
  )
}

export function AddSmtpDomainWizard() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { data: func } = useProjectFunction(projectId, functionId)
  // Inbound email is Cloud-only.
  const { isCloud: enabled } = useConsoleProfile()

  const createMutation = useCreateSmtpDomainRule(projectId)
  const verifyMutation = useVerifyDomain(projectId, project?.region)
  const deleteMutation = useDeleteDomain(projectId, project?.region)

  const { emailDomain } = useConsoleVariables(project?.region)
  const [kind, setKind] = useState<EmailDomainKind>('appwrite')
  // Without an email zone in this region only custom domains are possible.
  const effectiveKind: EmailDomainKind = emailDomain ? kind : 'custom'
  const suggestion = useMemo(
    () =>
      emailDomain
        ? `${suggestFunctionEmailLabel(func?.name)}.${emailDomain}`
        : '',
    [emailDomain, func?.name],
  )
  const [appwriteDomain, setAppwriteDomain] = useState('')
  const [appwriteDomainValid, setAppwriteDomainValid] = useState(false)
  useEffect(() => {
    if (appwriteDomain === '' && suggestion !== '')
      setAppwriteDomain(suggestion)
  }, [appwriteDomain, suggestion])

  const [domain, setDomain] = useState('')
  const [error, setError] = useState('')
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)
  const [rule, setRule] = useState<SmtpProxyRule | null>(null)
  const [claimDomain, setClaimDomain] = useState<string | null>(null)
  const [claimRequired, setClaimRequired] = useState(false)
  const [registering, setRegistering] = useState(false)

  const fallbackPath = `/projects/${projectId}/functions/${functionId}/domains`
  const isPending =
    registering ||
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
    // useConsoleProfile applies debug overrides only after mount, so read the
    // profile directly here or a debug override closes the wizard on open.
    if (!isCloudProfile()) {
      navigate({
        to: '/projects/$projectId/functions/$functionId/domains',
        params: { projectId: projectId!, functionId: functionId! },
      })
    }
  }, [navigate, projectId, functionId])

  if (!enabled) {
    return null
  }

  const createRule = async (d: string) => {
    const created = await createMutation.mutateAsync({
      domain: d,
      functionId: functionId!,
    })
    setClaimDomain(null)
    if (created.status === 'verified') {
      toast.success(t('Email domain verified'))
      leave()
    } else {
      setRule(created)
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (isPending) return
    if (effectiveKind === 'appwrite') {
      if (!emailEnabled || !appwriteDomainValid) return
      try {
        await createRule(appwriteDomain)
      } catch (err) {
        toast.error(getErrorMessage(err, t('Failed to add email domain')))
      }
      return
    }
    const d = domain.trim().toLowerCase()
    setError('')
    if (!d) {
      setError(t('Required'))
      return
    }
    if (!isValidDomain(d)) {
      setError(t('Please enter a valid domain'))
      return
    }
    if (!emailEnabled) {
      return
    }

    setRegistering(true)
    try {
      await ensureApexDomainInOrganization(project?.teamId ?? '', d, {
        region: project?.region,
      })
    } catch (err: unknown) {
      const e = err as { type?: string }
      if (e?.type !== 'domain_already_exists') {
        toast.error(getErrorMessage(err, 'Failed to register domain'))
        return
      }
    } finally {
      setRegistering(false)
    }

    try {
      await createRule(d)
    } catch (err) {
      if (isDomainClaimError(err)) {
        setClaimRequired(
          getAppwriteErrorInfo(err).type === 'rule_claim_required',
        )
        setClaimDomain(d)
        return
      }
      toast.error(getErrorMessage(err, t('Failed to add email domain')))
    }
  }

  const handleClaim = async () => {
    if (!claimDomain) return
    setVerificationError(null)
    try {
      await createRule(claimDomain)
    } catch (err) {
      setVerificationError(domainVerificationError(t, err))
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
        toast.success(t('Email domain verified'))
        leave()
      } else {
        setRule(updated)
        setVerificationError(dnsPendingVerificationError(t))
      }
    } catch (err) {
      setVerificationError(domainVerificationError(t, err))
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

  if (claimDomain) {
    return (
      <WizardLayout
        title={t('Verify domain ownership')}
        fallbackPath={fallbackPath}
        fullscreen
        useSidebar={false}
        maxWidth="max-w-4xl"
        footer={
          <div className="flex gap-2 justify-end w-full">
            <Button
              variant="outline"
              onClick={() => {
                setClaimDomain(null)
                setVerificationError(null)
              }}
              disabled={isPending}
            >
              {t('Back')}
            </Button>
            <Button onClick={handleClaim} disabled={isPending}>
              {t('Verify')}
            </Button>
          </div>
        }
      >
        <ClaimDomainContent
          domain={claimDomain}
          claimRequired={claimRequired}
          projectId={projectId!}
          verificationError={verificationError}
        />
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title={t('Add domain')}
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
            disabled={
              isPending ||
              !emailEnabled ||
              (effectiveKind === 'appwrite'
                ? !appwriteDomainValid
                : !domain.trim())
            }
          >
            {t('Add')}
          </Button>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-6">
        <DomainTypeCard
          value="smtp"
          onChange={(type) => {
            if (type === 'http') {
              navigate({
                to: '/projects/$projectId/functions/$functionId/domains/add',
                params: { projectId: projectId!, functionId: functionId! },
                search: {},
                replace: true,
              })
            }
          }}
          emailEnabled={emailEnabled}
          disabled={createMutation.isPending}
        />
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Domain')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            {emailDomain ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => setKind('appwrite')}
                  disabled={createMutation.isPending}
                  className={kindOption(effectiveKind === 'appwrite')}
                >
                  <div className="text-[13px] font-semibold">
                    {t('Appwrite domain')}
                  </div>
                  <p className="text-[11px] text-muted-foreground mt-0.5">
                    {t('Ready right away, no DNS records needed')}
                  </p>
                  <code className="mt-2 block text-[11px] text-muted-foreground font-mono truncate">
                    *.{emailDomain}
                  </code>
                </button>
                <button
                  type="button"
                  onClick={() => setKind('custom')}
                  disabled={createMutation.isPending}
                  className={kindOption(effectiveKind === 'custom')}
                >
                  <div className="text-[13px] font-semibold">
                    {t('Custom domain')}
                  </div>
                  <p className="text-[11px] text-muted-foreground mt-0.5">
                    {t('Use your own domain, verified with DNS records')}
                  </p>
                </button>
              </div>
            ) : null}
            {effectiveKind === 'appwrite' ? (
              <div className="space-y-1">
                <DomainInput
                  value={appwriteDomain}
                  onChange={setAppwriteDomain}
                  onValidChange={setAppwriteDomainValid}
                  baseDomain={emailDomain ?? ''}
                  placeholder="my-function"
                  disabled={createMutation.isPending || !emailEnabled}
                />
                <p className="text-[12px] text-muted-foreground">
                  {t(
                    'Mail sent to any address at this domain runs the function.',
                  )}
                </p>
              </div>
            ) : (
              <div>
                <Label
                  htmlFor="email-domain"
                  className="text-[12px] font-medium"
                >
                  {t('Domain name')}
                </Label>
                <Input
                  id="email-domain"
                  autoFocus
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
                      'Use a domain or subdomain that receives no other mail, since its MX record will point to Appwrite.',
                    )}
                  </p>
                )}
              </div>
            )}
          </div>
        </div>
      </form>
    </WizardLayout>
  )
}
