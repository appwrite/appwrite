/**
 * Add API Domain Wizard
 *
 * Full-screen wizard for adding an API domain (Settings > Domains).
 */

import { useState } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  VerifyDomainContent,
  dnsPendingVerificationError,
  type DomainVerificationError,
} from './VerifyDomainContent'
import {
  useCreateDomain,
  useProject,
  useVerifyDomain,
  useDeleteDomain,
} from '@/lib/react-query/hooks'
import {
  ensureApexDomainInOrganization,
  isValidDomain,
} from '@/lib/utils/proxy-domains'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

export function AddDomainWizard({
  initialDomain,
}: {
  initialDomain?: string
} = {}) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { project } = useProject(projectId)
  const region = project?.region

  const createMutation = useCreateDomain(projectId, region)
  const verifyMutation = useVerifyDomain(projectId, region)
  const deleteMutation = useDeleteDomain(projectId, region)

  const [domain, setDomain] = useState(initialDomain ?? '')
  const [error, setError] = useState('')
  const [rule, setRule] = useState<Models.ProxyRule | null>(null)
  const [verificationError, setVerificationError] =
    useState<DomainVerificationError | null>(null)

  const fallbackPath = `/projects/${projectId}/settings/domains`
  const isPending = createMutation.isPending || verifyMutation.isPending

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
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

    try {
      if (project?.teamId) {
        await ensureApexDomainInOrganization(project.teamId, d)
      }
    } catch (err: unknown) {
      const e = err as { type?: string }
      if (e?.type !== 'domain_already_exists') {
        toast.error(t('Failed to register domain'))
        return
      }
    }

    try {
      const created = await createMutation.mutateAsync(d)
      if (created.status === 'verified') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'project', projectId],
        })
        toast.success(t('Domain verified successfully'))
        navigate({
          to: '/projects/$projectId/settings/domains',
          params: { projectId: projectId! },
        })
      } else if (created.status === 'verifying') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'project', projectId],
        })
        toast.success(t('Verification in progress'))
        navigate({
          to: '/projects/$projectId/settings/domains',
          params: { projectId: projectId! },
        })
      } else {
        setRule(created)
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : t('Failed to add domain'))
    }
  }

  const handleVerify = async () => {
    if (!rule) return
    setVerificationError(null)
    try {
      const updated = await verifyMutation.mutateAsync(rule.$id)
      if (updated.status === 'verified') {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'project', projectId],
        })
        toast.success(t('Domain verified successfully'))
        navigate({
          to: '/projects/$projectId/settings/domains',
          params: { projectId: projectId! },
        })
      } else if (
        updated.status === 'created' ||
        updated.status === 'unverified'
      ) {
        setVerificationError(dnsPendingVerificationError(t))
      } else {
        await queryClient.refetchQueries({
          queryKey: ['proxy-rules', 'project', projectId],
        })
        toast.success(t('Verification in progress'))
        navigate({
          to: '/projects/$projectId/settings/domains',
          params: { projectId: projectId! },
        })
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

  // Step 2: Verify (rule created, needs DNS verification)
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
          rule={rule}
          region={region}
          verificationError={verificationError}
        />
      </WizardLayout>
    )
  }

  // Step 1: Configure domain
  return (
    <WizardLayout
      title={t('Add API domain')}
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
                to: '/projects/$projectId/settings/domains',
                params: { projectId: projectId! },
              })
            }
            disabled={isPending}
          >
            {t('Cancel')}
          </Button>
          <Button onClick={handleSubmit} disabled={isPending || !domain.trim()}>
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
            <Label htmlFor="domain" className="text-[12px] font-medium">
              {t('Domain name')}
            </Label>
            <Input
              id="domain"
              placeholder="api.example.com"
              value={domain}
              onChange={(e) => {
                setDomain(e.target.value)
                setError('')
              }}
              className={`font-mono mt-1.5 ${error ? 'border-destructive' : ''}`}
              autoFocus
              disabled={createMutation.isPending}
            />
            {error && (
              <p className="text-[11px] text-destructive mt-1">{error}</p>
            )}
          </div>
        </div>
      </form>
    </WizardLayout>
  )
}
