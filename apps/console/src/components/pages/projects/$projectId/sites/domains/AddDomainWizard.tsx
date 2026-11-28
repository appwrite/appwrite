/**
 * Add Site Domain Wizard
 *
 * Full-screen wizard for adding a domain to a site.
 * Uses DomainTargetCard for behaviour selection (Active, Branch, Redirect).
 */

import { useState } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { DomainTargetCard } from '../../shared/DomainTargetCard'
import { VerifyDomainContent } from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import {
  dnsPendingVerificationError,
  domainVerificationError,
  type DomainVerificationError,
} from '@/components/global/shared/dns/DomainVerificationAlert'
import { ClaimDomainContent } from '@/components/pages/projects/$projectId/settings/domains/ClaimDomainContent'
import {
  useCreateSiteDomainRule,
  useProjectSite,
  useProject,
  useProjectDomain,
  useVerifyDomain,
  useDeleteDomain,
} from '@/lib/react-query/hooks'
import {
  DomainPlanLimitNotice,
  useNewApexPlanLimit,
} from '@/components/pages/projects/$projectId/shared/NewApexPlanLimit'
import {
  domainFieldError,
  ensureApexDomainInOrganization,
  getDomainClaimReason,
  type DomainClaimReason,
  isValidDomain,
} from '@/lib/utils/proxy-domains'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import type { Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'

export function AddDomainWizard() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { data: site } = useProjectSite(projectId, siteId)

  const createMutation = useCreateSiteDomainRule(projectId)
  const verifyMutation = useVerifyDomain(projectId, project?.region)
  const deleteMutation = useDeleteDomain(projectId, project?.region)

  const [domain, setDomain] = useState('')
  const { blocked: apexPlanBlocked, limit: domainsLimit } = useNewApexPlanLimit(
    project?.teamId,
    domain,
    project?.region,
  )
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)
  const [behaviour, setBehaviour] = useState<'active' | 'branch' | 'redirect'>(
    'active',
  )
  const [branch, setBranch] = useState('')
  const [redirectUrl, setRedirectUrl] = useState('')
  const [statusCode, setStatusCode] = useState('302')
  const [domainTouched, setDomainTouched] = useState(false)
  // The server's reason the last add failed, such as a domain that exists already
  const [submitError, setSubmitError] = useState('')
  const domainError = domainFieldError(domain, domainTouched, t) || submitError
  const [rule, setRule] = useState<Models.ProxyRule | null>(null)
  // A verify, failed or not, saves the checked records on the stored rule.
  const { rule: liveRule } = useProjectDomain(
    projectId,
    project?.region,
    rule?.$id,
  )
  // Set while the create waits for the domain's ownership TXT record
  const [claimDomain, setClaimDomain] = useState<string | null>(null)
  const [claimReason, setClaimReason] = useState<DomainClaimReason>('pointed')
  const [registering, setRegistering] = useState(false)

  const fallbackPath = `/projects/${projectId}/sites/${siteId}/domains`
  const isPending =
    registering ||
    createMutation.isPending ||
    verifyMutation.isPending ||
    deleteMutation.isPending

  const hasRepo = !!(site?.installationId && site?.providerRepositoryId)

  const createRule = async (d: string) => {
    const created = await createMutation.mutateAsync({
      domain: d,
      siteId: siteId!,
      behaviour,
      ...(behaviour === 'branch' && { branch }),
      ...(behaviour === 'redirect' && {
        redirectUrl: redirectUrl.trim(),
        statusCode,
      }),
    })
    setClaimDomain(null)
    if (created.status === 'verified') {
      toast.success(t('Domain verified'))
      navigate({
        to: '/projects/$projectId/sites/$siteId/domains',
        params: { projectId: projectId!, siteId: siteId! },
      })
    } else if (created.status === 'verifying') {
      toast.success(t('Verification in progress'))
      navigate({
        to: '/projects/$projectId/sites/$siteId/domains',
        params: { projectId: projectId!, siteId: siteId! },
      })
    } else {
      setRule(created)
    }
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (isPending) return
    setDomainTouched(true)
    if (!isValidDomain(domain)) return
    const d = domain.trim().toLowerCase()
    if (behaviour === 'branch' && !hasRepo) {
      toast.error(t('Connect repository first'))
      return
    }
    if (behaviour === 'branch' && !branch) {
      toast.error(t('Select branch'))
      return
    }
    if (behaviour === 'redirect' && !redirectUrl.trim()) {
      toast.error(t('Enter URL'))
      return
    }
    if (behaviour === 'redirect') {
      try {
        new URL(
          redirectUrl.startsWith('http')
            ? redirectUrl
            : `https://${redirectUrl}`,
        )
      } catch {
        toast.error(t('Invalid URL'))
        return
      }
    }
    if (apexPlanBlocked) return

    setRegistering(true)
    try {
      await ensureApexDomainInOrganization(project?.teamId ?? '', d, {
        region: project?.region,
      })
    } catch (err: unknown) {
      const e = err as { type?: string }
      if (e?.type !== 'domain_already_exists') {
        const message = getErrorMessage(err, 'Failed to register domain')
        setSubmitError(message)
        toast.error(message)
        return
      }
    } finally {
      setRegistering(false)
    }

    try {
      await createRule(d)
    } catch (err) {
      const reason = getDomainClaimReason(err)
      if (reason) {
        setClaimReason(reason)
        setClaimDomain(d)
        return
      }
      const message = getErrorMessage(err, 'Failed to add domain')
      setSubmitError(message)
      toast.error(message)
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
      const updated = await verifyMutation.mutateAsync(rule.$id)
      if (updated.status === 'verified') {
        toast.success(t('Domain verified'))
        navigate({
          to: '/projects/$projectId/sites/$siteId/domains',
          params: { projectId: projectId!, siteId: siteId! },
        })
      } else if (
        updated.status === 'created' ||
        updated.status === 'unverified'
      ) {
        setVerificationError(dnsPendingVerificationError(t))
      } else {
        toast.success(t('Verification in progress'))
        navigate({
          to: '/projects/$projectId/sites/$siteId/domains',
          params: { projectId: projectId!, siteId: siteId! },
        })
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

  // Step 2: Verify
  if (rule) {
    return (
      <WizardLayout
        title={t('Verify domain')}
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
        <VerifyDomainContent
          rule={liveRule ?? rule}
          region={project?.region}
          resourceType="site"
          verificationError={verificationError}
        />
      </WizardLayout>
    )
  }

  // Ownership claim
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
          reason={claimReason}
          projectId={projectId!}
          verificationError={verificationError}
        />
      </WizardLayout>
    )
  }

  // Step 1: Configure
  return (
    <WizardLayout
      title={t('Add domain')}
      fallbackPath={fallbackPath}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-4xl"
      footer={
        <div className="flex gap-2 justify-end w-full">
          <Button
            variant="outline"
            onClick={() =>
              navigate({
                to: '/projects/$projectId/sites/$siteId/domains',
                params: { projectId: projectId!, siteId: siteId! },
              })
            }
            disabled={isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isPending || !isValidDomain(domain) || apexPlanBlocked}
          >
            {t('Add')}
          </Button>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Domain')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <Label className="text-[12px]">{t('Domain name')}</Label>
            <Input
              placeholder="app.example.com"
              value={domain}
              onChange={(e) => {
                setDomain(e.target.value)
                setSubmitError('')
              }}
              onBlur={() => setDomainTouched(true)}
              aria-invalid={domainError ? true : undefined}
              className="font-mono mt-1.5"
              disabled={createMutation.isPending}
            />
            {domainError ? (
              <p className="text-[11px] text-destructive mt-1">{domainError}</p>
            ) : null}
            {apexPlanBlocked ? (
              <DomainPlanLimitNotice
                orgId={project?.teamId}
                limit={domainsLimit}
              />
            ) : null}
          </div>
        </div>

        <DomainTargetCard
          behaviour={behaviour}
          onBehaviourChange={setBehaviour}
          branch={branch}
          onBranchChange={setBranch}
          redirectUrl={redirectUrl}
          onRedirectUrlChange={setRedirectUrl}
          statusCode={statusCode}
          onStatusCodeChange={setStatusCode}
          projectId={projectId}
          installationId={site?.installationId}
          providerRepositoryId={site?.providerRepositoryId}
          hasRepository={hasRepo}
          disabled={createMutation.isPending}
        />
      </form>
    </WizardLayout>
  )
}
