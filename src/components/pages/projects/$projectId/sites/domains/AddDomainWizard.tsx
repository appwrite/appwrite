/**
 * Add Site Domain Wizard
 *
 * Full-screen wizard for adding a domain to a site.
 * Uses DomainTargetCard for behaviour selection (Active, Branch, Redirect).
 */

import { useState } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { DomainTargetCard } from '../../shared/DomainTargetCard'
import { VerifyDomainContent } from '@/components/pages/projects/$projectId/settings/domains/VerifyDomainContent'
import {
  useCreateSiteDomainRule,
  useProjectSite,
  useRepositoryBranches,
  useProject,
  useVerifyDomain,
  useDeleteDomain,
} from '@/lib/react-query/hooks'
import {
  ensureApexDomainInOrganization,
  isValidDomain,
} from '@/lib/utils/proxy-domains'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'

export function AddDomainWizard() {
  const { projectId, siteId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const { data: site } = useProjectSite(projectId, siteId)
  const { data: branchesData } = useRepositoryBranches(
    projectId,
    site?.installationId,
    site?.providerRepositoryId,
  )

  const createMutation = useCreateSiteDomainRule(projectId)
  const verifyMutation = useVerifyDomain(projectId, project?.region)
  const deleteMutation = useDeleteDomain(projectId, project?.region)

  const [domain, setDomain] = useState('')
  const [verificationError, setVerificationError] = useState<string | null>(null)
  const [behaviour, setBehaviour] = useState<'active' | 'branch' | 'redirect'>('active')
  const [branch, setBranch] = useState('')
  const [redirectUrl, setRedirectUrl] = useState('')
  const [statusCode, setStatusCode] = useState('302')
  const [error, setError] = useState('')
  const [rule, setRule] = useState<Models.ProxyRule | null>(null)

  const fallbackPath = `/projects/${projectId}/sites/${siteId}/domains`
  const isPending =
    createMutation.isPending || verifyMutation.isPending || deleteMutation.isPending

  const hasRepo = !!(site?.installationId && site?.providerRepositoryId)
  const branches = branchesData?.branches ?? []

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    const d = domain.trim().toLowerCase()
    setError('')
    if (!d) {
      setError('Required')
      return
    }
    if (!isValidDomain(d)) {
      setError('Invalid')
      return
    }
    if (behaviour === 'branch' && !hasRepo) {
      toast.error('Connect repository first')
      return
    }
    if (behaviour === 'branch' && !branch) {
      toast.error('Select branch')
      return
    }
    if (behaviour === 'redirect' && !redirectUrl.trim()) {
      toast.error('Enter URL')
      return
    }
    if (behaviour === 'redirect') {
      try {
        new URL(
          redirectUrl.startsWith('http') ? redirectUrl : `https://${redirectUrl}`,
        )
      } catch {
        toast.error('Invalid URL')
        return
      }
    }

    try {
      await ensureApexDomainInOrganization(project?.teamId ?? '', d, {
        skipForSites: true,
      })
    } catch (err: unknown) {
      const e = err as { type?: string }
      if (e?.type !== 'domain_already_exists') {
        toast.error('Failed to register domain')
        return
      }
    }

    try {
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
      if (created.status === 'verified') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'site', projectId, siteId],
        })
        toast.success('Domain verified')
        navigate({
          to: '/projects/$projectId/sites/$siteId/domains',
          params: { projectId: projectId!, siteId: siteId! },
        })
      } else if (created.status === 'verifying') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'site', projectId, siteId],
        })
        toast.success('Verification in progress')
        navigate({
          to: '/projects/$projectId/sites/$siteId/domains',
          params: { projectId: projectId!, siteId: siteId! },
        })
      } else {
        setRule(created)
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to add domain')
    }
  }

  const handleVerify = async () => {
    if (!rule) return
    setVerificationError(null)
    try {
      const updated = await verifyMutation.mutateAsync(rule.$id)
      if (updated.status === 'verified') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'site', projectId, siteId],
        })
        toast.success('Domain verified')
        navigate({
          to: '/projects/$projectId/sites/$siteId/domains',
          params: { projectId: projectId!, siteId: siteId! },
        })
      } else if (updated.status === 'created' || updated.status === 'unverified') {
        setVerificationError('Verification failed. Check DNS and retry.')
      } else {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'site', projectId, siteId],
        })
        toast.success('Verification in progress')
        navigate({
          to: '/projects/$projectId/sites/$siteId/domains',
          params: { projectId: projectId!, siteId: siteId! },
        })
      }
    } catch {
      setVerificationError('Failed to verify domain')
    }
  }

  const handleChange = async () => {
    if (!rule) return
    setVerificationError(null)
    try {
      await deleteMutation.mutateAsync(rule.$id)
      setRule(null)
    } catch {
      toast.error('Failed to remove domain')
    }
  }

  // Step 2: Verify
  if (rule) {
    return (
      <WizardLayout
        title="Verify domain"
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
              Change
            </Button>
            <Button onClick={handleVerify} disabled={isPending}>
              Verify
            </Button>
          </div>
        }
      >
        <VerifyDomainContent
          rule={rule}
          region={project?.region}
          verificationError={verificationError}
        />
      </WizardLayout>
    )
  }

  // Step 1: Configure
  return (
    <WizardLayout
      title="Add domain"
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
            Cancel
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isPending || !domain.trim()}
          >
            Add
          </Button>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Domain
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <Label className="text-[12px]">Domain name</Label>
            <Input
              placeholder="app.example.com"
              value={domain}
              onChange={(e) => {
                setDomain(e.target.value)
                setError('')
              }}
              className={`font-mono mt-1.5 ${error ? 'border-destructive' : ''}`}
              disabled={createMutation.isPending}
            />
            {error && (
              <p className="text-[11px] text-destructive mt-1">{error}</p>
            )}
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
          branches={branches}
          hasRepository={hasRepo}
          disabled={createMutation.isPending}
        />
      </form>
    </WizardLayout>
  )
}
