/**
 * Buy a domain from a project, function, or site domains tab, then assign a
 * proxy rule and wait for the certificate. Search and checkout reuse the
 * organization buy wizard. DNS for newly purchased domains is managed by
 * Appwrite, so this flow skips nameserver instructions.
 */

import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Loader2, ShieldCheck } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { BuyDomainWizard } from '@/components/pages/organizations/$orgId/domains/_components/BuyDomainWizard'
import type { BuyDomainPurchaseResult } from '@/components/pages/organizations/$orgId/domains/_components/BuyDomainCheckout'
import { DomainTargetCard } from './DomainTargetCard'
import {
  useCreateDomain,
  useCreateFunctionDomainRule,
  useCreateSiteDomainRule,
  useProject,
  useProjectFunction,
  useProjectSite,
  useVerifyDomain,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { getApexDomain, isValidDomain } from '@/lib/utils/proxy-domains'
import { getDomainStatusBadgeConfig } from '@/lib/utils/status-badge'
import type { BuyDomainWizardSearch } from '@/lib/domains/buy-wizard'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type BuyDomainResourceType = 'api' | 'function' | 'site'

const CERTIFICATE_POLL_MS = 3000

export function BuyDomainForResourceWizard({
  resourceType,
  fallbackPath,
  routeSearch,
}: {
  resourceType: BuyDomainResourceType
  fallbackPath: string
  routeSearch: BuyDomainWizardSearch
}) {
  const { projectId } = useParams({ strict: false })
  const { project } = useProject(projectId)
  const orgId = project?.teamId
  const [purchase, setPurchase] = useState<BuyDomainPurchaseResult | null>(null)
  const [rule, setRule] = useState<Models.ProxyRule | null>(null)

  if (rule && projectId) {
    return (
      <CertificateStage
        projectId={projectId}
        region={project?.region}
        resourceType={resourceType}
        fallbackPath={fallbackPath}
        organizationDomainId={purchase?.domainId}
        rule={rule}
      />
    )
  }

  if (purchase && orgId) {
    return (
      <AssignRuleStage
        resourceType={resourceType}
        fallbackPath={fallbackPath}
        purchasedDomain={purchase.domain}
        organizationDomainId={purchase.domainId}
        onAssigned={setRule}
      />
    )
  }

  return (
    <BuyDomainWizard
      routeSearch={routeSearch}
      orgId={orgId}
      fallbackPath={fallbackPath}
      onPurchaseComplete={setPurchase}
    />
  )
}

function AssignRuleStage({
  resourceType,
  fallbackPath,
  purchasedDomain,
  organizationDomainId,
  onAssigned,
}: {
  resourceType: BuyDomainResourceType
  fallbackPath: string
  purchasedDomain: string
  organizationDomainId: string
  onAssigned: (rule: Models.ProxyRule) => void
}) {
  const t = useT()
  const navigate = useNavigate()
  const { projectId, functionId, siteId } = useParams({ strict: false })
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const { data: func } = useProjectFunction(
    resourceType === 'function' ? projectId : undefined,
    resourceType === 'function' ? functionId : undefined,
  )
  const { data: site } = useProjectSite(
    resourceType === 'site' ? projectId : undefined,
    resourceType === 'site' ? siteId : undefined,
  )

  const createApiRule = useCreateDomain(projectId, project?.region)
  const createFunctionRule = useCreateFunctionDomainRule(projectId)
  const createSiteRule = useCreateSiteDomainRule(projectId)
  const verifyMutation = useVerifyDomain(projectId, project?.region)

  const [domain, setDomain] = useState(purchasedDomain)
  const [error, setError] = useState('')
  const [behaviour, setBehaviour] = useState<'active' | 'branch' | 'redirect'>(
    'active',
  )
  const [branch, setBranch] = useState('')
  const [redirectUrl, setRedirectUrl] = useState('')
  const [statusCode, setStatusCode] = useState('302')

  const resource = resourceType === 'function' ? func : site
  const hasRepo = !!(resource?.installationId && resource?.providerRepositoryId)
  const isPending =
    createApiRule.isPending ||
    createFunctionRule.isPending ||
    createSiteRule.isPending ||
    verifyMutation.isPending

  const description =
    resourceType === 'api'
      ? t(
          'Assign this domain to the project API. DNS is already managed by Appwrite.',
        )
      : resourceType === 'function'
        ? t(
            'Assign this domain to the function. DNS is already managed by Appwrite.',
          )
        : t(
            'Assign this domain to the site. DNS is already managed by Appwrite.',
          )

  const handleSubmit = async (e?: FormEvent) => {
    e?.preventDefault()
    const d = domain.trim().toLowerCase()
    setError('')
    if (!d) {
      setError(t('Required'))
      return
    }
    if (!isValidDomain(d)) {
      setError(t('Invalid format'))
      return
    }
    const purchasedApex =
      getApexDomain(purchasedDomain) ?? purchasedDomain.toLowerCase()
    const assignedApex = getApexDomain(d)
    if (assignedApex !== purchasedApex) {
      setError(t('Use the domain you bought or a subdomain of it'))
      return
    }
    if (resourceType !== 'api' && behaviour === 'branch' && !hasRepo) {
      toast.error(t('Connect repository first'))
      return
    }
    if (resourceType !== 'api' && behaviour === 'branch' && !branch) {
      toast.error(t('Select branch'))
      return
    }
    if (
      resourceType !== 'api' &&
      behaviour === 'redirect' &&
      !redirectUrl.trim()
    ) {
      toast.error(t('Enter URL'))
      return
    }
    if (resourceType !== 'api' && behaviour === 'redirect') {
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

    try {
      let created: Models.ProxyRule
      if (resourceType === 'api') {
        created = await createApiRule.mutateAsync(d)
      } else if (resourceType === 'function') {
        created = await createFunctionRule.mutateAsync({
          domain: d,
          functionId: functionId!,
          behaviour,
          ...(behaviour === 'branch' && { branch }),
          ...(behaviour === 'redirect' && {
            redirectUrl: redirectUrl.trim(),
            statusCode,
          }),
        })
      } else {
        created = await createSiteRule.mutateAsync({
          domain: d,
          siteId: siteId!,
          behaviour,
          ...(behaviour === 'branch' && { branch }),
          ...(behaviour === 'redirect' && {
            redirectUrl: redirectUrl.trim(),
            statusCode,
          }),
        })
      }

      await queryClient.refetchQueries({ queryKey: ['proxy-rules'] })

      if (created.status === 'unverified') {
        try {
          created = await verifyMutation.mutateAsync({
            ruleId: created.$id,
            organizationDomainId,
          })
        } catch {
          // DNS for a just-purchased domain can take a moment; the next stage
          // already explains that certificate issuance can take a few minutes.
        }
      }

      onAssigned(created)
    } catch (err) {
      toast.error(
        err instanceof Error ? err.message : t('Failed to assign domain'),
      )
    }
  }

  return (
    <WizardLayout
      title={t('Connect domain')}
      fallbackPath={fallbackPath}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-4xl"
      footer={
        <div className="flex gap-2 justify-end w-full">
          <Button
            variant="outline"
            onClick={() => {
              if (resourceType === 'function' && functionId) {
                navigate({
                  to: '/projects/$projectId/functions/$functionId/domains',
                  params: { projectId: projectId!, functionId },
                })
                return
              }
              if (resourceType === 'site' && siteId) {
                navigate({
                  to: '/projects/$projectId/sites/$siteId/domains',
                  params: { projectId: projectId!, siteId },
                })
                return
              }
              navigate({
                to: '/projects/$projectId/settings/domains',
                params: { projectId: projectId! },
              })
            }}
            disabled={isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={() => handleSubmit()}
            disabled={isPending || !domain.trim()}
          >
            {t('Connect')}
          </Button>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-6">
        <div>
          <h2 className="text-[15px] font-semibold text-foreground">
            {t('Assign a domain rule')}
          </h2>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {description}
          </p>
        </div>

        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Domain')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Use the domain you bought, or a subdomain of it.')}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <Label
              htmlFor="buy-assign-domain"
              className="text-[12px] font-medium"
            >
              {t('Domain name')}
            </Label>
            <Input
              id="buy-assign-domain"
              placeholder={purchasedDomain}
              value={domain}
              onChange={(e) => {
                setDomain(e.target.value)
                setError('')
              }}
              className={cn('font-mono mt-1.5', error && 'border-destructive')}
              autoFocus
              disabled={isPending}
            />
            {error ? (
              <p className="text-[11px] text-destructive mt-1">{error}</p>
            ) : null}
          </div>
        </div>

        {resourceType !== 'api' ? (
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
            installationId={resource?.installationId}
            providerRepositoryId={resource?.providerRepositoryId}
            hasRepository={hasRepo}
            disabled={isPending}
          />
        ) : null}
      </form>
    </WizardLayout>
  )
}

function CertificateStage({
  projectId,
  region,
  resourceType,
  fallbackPath,
  organizationDomainId,
  rule: initialRule,
}: {
  projectId: string
  region?: string
  resourceType: BuyDomainResourceType
  fallbackPath: string
  organizationDomainId?: string
  rule: Models.ProxyRule
}) {
  const t = useT()
  const navigate = useNavigate()
  const { functionId, siteId } = useParams({ strict: false })
  const queryClient = useQueryClient()
  const verifyMutation = useVerifyDomain(projectId, region)

  const { data: polledRule } = useQuery({
    queryKey: ['proxy-rule', 'project', projectId, region, initialRule.$id],
    queryFn: async () => {
      const projectSdk = sdk.forProject(projectId, region)
      return projectSdk.proxy.getRule({ ruleId: initialRule.$id })
    },
    initialData: initialRule,
    staleTime: 0,
    refetchInterval: (query) =>
      query.state.data?.status === 'verified' ? false : CERTIFICATE_POLL_MS,
  })

  const rule = polledRule ?? initialRule
  const verified = rule.status === 'verified'
  const issuing = rule.status === 'verifying' || rule.status === 'unverified'
  const statusConfig = getDomainStatusBadgeConfig(rule.status)

  useEffect(() => {
    if (!verified) return
    void queryClient.refetchQueries({ queryKey: ['proxy-rules'] })
  }, [verified, queryClient])

  const handleDone = () => {
    if (resourceType === 'function' && functionId) {
      navigate({
        to: '/projects/$projectId/functions/$functionId/domains',
        params: { projectId, functionId },
      })
      return
    }
    if (resourceType === 'site' && siteId) {
      navigate({
        to: '/projects/$projectId/sites/$siteId/domains',
        params: { projectId, siteId },
      })
      return
    }
    navigate({
      to: '/projects/$projectId/settings/domains',
      params: { projectId },
    })
  }

  const handleRetry = async () => {
    try {
      const updated = await verifyMutation.mutateAsync({
        ruleId: rule.$id,
        organizationDomainId,
      })
      queryClient.setQueryData(
        ['proxy-rule', 'project', projectId, region, initialRule.$id],
        updated,
      )
    } catch {
      toast.error(t('Certificate is still being issued'))
    }
  }

  return (
    <WizardLayout
      title={t('Issuing certificate')}
      fallbackPath={fallbackPath}
      fullscreen
      useSidebar={false}
      maxWidth="max-w-4xl"
      footer={
        <div className="flex gap-2 justify-end w-full">
          {rule.status === 'unverified' && (
            <Button
              variant="outline"
              onClick={handleRetry}
              disabled={verifyMutation.isPending}
            >
              {t('Retry')}
            </Button>
          )}
          <Button onClick={handleDone}>{t('Done')}</Button>
        </div>
      }
    >
      <div className="space-y-6">
        {issuing ? (
          <Alert variant="default" className="border-blue-500/30 bg-blue-500/5">
            <AlertDescription className="text-[13px] text-muted-foreground">
              {t(
                'SSL certificate is being issued. This usually takes a couple of minutes - no action needed on your end.',
              )}
            </AlertDescription>
          </Alert>
        ) : null}

        {verified ? (
          <Alert
            variant="default"
            className="border-emerald-500/30 bg-emerald-500/5"
          >
            <AlertDescription className="text-[13px] text-emerald-700 dark:text-emerald-400">
              {t('Certificate issued. Your domain is ready.')}
            </AlertDescription>
          </Alert>
        ) : null}

        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h3 className="text-[15px] font-semibold text-foreground">
                  {t('Connecting your domain')}
                </h3>
                <p className="text-[13px] text-muted-foreground font-mono mt-2">
                  {rule.domain}
                </p>
              </div>
              <Badge
                variant={statusConfig.variant}
                className="text-[10px] shrink-0 gap-1.5"
              >
                {rule.status === 'verifying' && (
                  <Loader2 className="h-3 w-3 animate-spin" />
                )}
                {t(statusConfig.label)}
              </Badge>
            </div>
          </div>
          <div className="border-t border-border" />
          <ol className="divide-y divide-border">
            <ProcessStep
              state="done"
              title={t('Domain registered')}
              description={t('The name is now in your organization.')}
            />
            <ProcessStep
              state="done"
              title={t('DNS configured')}
              description={t(
                'Appwrite DNS is already managing this domain. No nameserver changes are required.',
              )}
            />
            <ProcessStep
              state={verified ? 'done' : 'active'}
              title={t('SSL certificate')}
              description={
                verified
                  ? t('HTTPS is ready for this domain.')
                  : t(
                      'Certificate generation can take a few minutes to complete.',
                    )
              }
              icon={
                verified ? undefined : (
                  <Loader2 className="h-4 w-4 animate-spin text-blue-600 dark:text-blue-400" />
                )
              }
            />
          </ol>
        </div>
      </div>
    </WizardLayout>
  )
}

function ProcessStep({
  state,
  title,
  description,
  icon,
}: {
  state: 'done' | 'active'
  title: string
  description: string
  icon?: ReactNode
}) {
  return (
    <li className="flex gap-3 px-6 py-4">
      <span
        className={cn(
          'mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border',
          state === 'done'
            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
            : 'border-blue-500/30 bg-blue-500/10 text-blue-600 dark:text-blue-400',
        )}
      >
        {icon ??
          (state === 'done' ? (
            <Check className="h-3.5 w-3.5" />
          ) : (
            <ShieldCheck className="h-3.5 w-3.5" />
          ))}
      </span>
      <div className="min-w-0">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <p className="mt-0.5 text-[12px] leading-relaxed text-muted-foreground">
          {description}
        </p>
      </div>
    </li>
  )
}
