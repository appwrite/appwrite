/**
 * Create deployment from Git – branch selector and optional repo connect.
 * Used for both Functions and Sites. If repo is already linked, show branch + activate.
 * If not, show repository picker then branch, then update + createVcsDeployment.
 */

import { useState, useEffect, useMemo } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ArrowLeft, ExternalLink } from 'lucide-react'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk, getApiEndpoint } from '@/lib/appwrite/sdk'
import { buildVcsAuthUrl, type VcsProviderId } from '@/lib/vcs/providers'
import { VCSReferenceType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import {
  useRepository,
  repositoryBranchesQueryOptions,
  useVcsInstallations,
  useProject,
  buildSiteUpdateParams,
  buildFunctionUpdateParams,
} from '@/lib/react-query/hooks'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RepositoryPicker } from '@/components/global/shared/RepositoryPicker'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { VcsInstallationErrorAlert } from '@/components/global/shared/VcsInstallationError'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'
import { useT } from '@/lib/i18n/translate'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'

export type CreateGitDeploymentResourceType = 'function' | 'site'

export interface CreateGitDeploymentModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  resourceType: CreateGitDeploymentResourceType
  projectId: string
  resourceId: string
  /** Current function or site (needed for update + branch default) */
  resource: Models.Function | Models.Site
  onSuccess?: () => void
}

const FUNCTIONS_DEPLOY_DOCS =
  '/docs/products/functions/deployments#create-deployment'
const SITES_DEPLOY_DOCS = '/docs/products/sites/deployments#create-deployment'

export function CreateGitDeploymentModal({
  open,
  onOpenChange,
  resourceType,
  projectId,
  resourceId,
  resource,
  onSuccess,
}: CreateGitDeploymentModalProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const hasLinkedRepo = Boolean(
    resource.installationId && resource.providerRepositoryId,
  )

  const [branch, setBranch] = useState(resource.providerBranch || 'main')
  const [activate, setActivate] = useState(true)
  const [selectedInstallationId, setSelectedInstallationId] = useState('')
  const [selectedRepositoryId, setSelectedRepositoryId] = useState('')
  const [selectedRepoPushedAt, setSelectedRepoPushedAt] = useState<
    string | null
  >(null)
  const [selectedRepoDisplayName, setSelectedRepoDisplayName] = useState<
    string | null
  >(null)

  const {
    data: repository,
    error: repositoryError,
    isFetching: repositoryFetching,
    refetch: refetchRepository,
  } = useRepository(
    projectId,
    hasLinkedRepo ? (resource.installationId ?? null) : null,
    hasLinkedRepo ? (resource.providerRepositoryId ?? null) : null,
  )
  const { data: installationsData } = useVcsInstallations(projectId)
  const installations = useMemo(
    () => installationsData?.installations ?? [],
    [installationsData?.installations],
  )
  const { project } = useProject(projectId ?? undefined)

  const getVcsAuthUrl = useMemo(() => {
    return (provider: VcsProviderId = 'github') => {
      if (typeof window === 'undefined' || !projectId) return '#'
      const origin = window.location.origin
      const path =
        resourceType === 'site'
          ? `/projects/${projectId}/sites/${resourceId}`
          : `/projects/${projectId}/functions/${resourceId}`
      const redirectUrl = `${origin}${path}`
      const projectEndpoint = getApiEndpoint(project?.region)
      return buildVcsAuthUrl({
        endpoint: projectEndpoint,
        provider,
        projectId,
        successUrl: redirectUrl,
        failureUrl: redirectUrl,
      })
    }
  }, [projectId, resourceId, resourceType, project?.region])
  const getGitHubAuthUrl = getVcsAuthUrl('github')

  useEffect(() => {
    if (resource.providerBranch) setBranch(resource.providerBranch)
    else setBranch('main')
  }, [resource.providerBranch, open])

  useEffect(() => {
    if (installations.length > 0 && !selectedInstallationId) {
      setSelectedInstallationId(installations[0].$id)
    }
  }, [installations, selectedInstallationId])

  const linkRepoThenDeployMutation = useMutation({
    mutationFn: async () => {
      const projectSdk = sdk.forProject(projectId)
      const installationId = hasLinkedRepo
        ? resource.installationId!
        : selectedInstallationId
      const providerRepositoryId = hasLinkedRepo
        ? resource.providerRepositoryId!
        : selectedRepositoryId
      const ref = branch.trim()

      if (!hasLinkedRepo && resourceType === 'function') {
        const func = resource as Models.Function
        await projectSdk.functions.update(
          buildFunctionUpdateParams(func, {
            installationId,
            providerRepositoryId,
            providerBranch: ref,
          }),
        )
      }
      if (!hasLinkedRepo && resourceType === 'site') {
        const site = resource as Models.Site
        await projectSdk.sites.update(
          buildSiteUpdateParams(site, {
            installationId,
            providerRepositoryId,
            providerBranch: ref,
          }),
        )
      }

      if (resourceType === 'function') {
        return await projectSdk.functions.createVcsDeployment({
          functionId: resourceId,
          type: VCSReferenceType.Branch,
          reference: ref,
          activate,
        })
      }
      return await projectSdk.sites.createVcsDeployment({
        siteId: resourceId,
        type: VCSReferenceType.Branch,
        reference: ref,
        activate,
      })
    },
    onSuccess: () => {
      closeDialogBeforeOverlayUnmount(() => {
        onOpenChange(false)
      })
      const deployKey =
        resourceType === 'function'
          ? ['deployments', 'function', projectId, resourceId]
          : ['deployments', 'site', projectId, resourceId]
      queryClient.refetchQueries({ queryKey: deployKey })
      if (resourceType === 'site') {
        queryClient.invalidateQueries({
          queryKey: ['site', 'project', projectId, resourceId],
        })
      }
      if (resourceType === 'function') {
        queryClient.invalidateQueries({
          queryKey: ['function', 'project', projectId, resourceId],
        })
      }
      if (activate) {
        toast.success(
          t(
            'Deployment is in progress. It will be automatically activated after build step completes.',
          ),
        )
      } else {
        toast.success(
          t(
            'Deployment is in progress. You can activate it after build step completes.',
          ),
        )
      }
      onSuccess?.()
    },
    onError: (err: Error) => {
      toast.error(err?.message ?? t('Failed to create deployment'))
    },
  })

  const handleSubmit = () => {
    if (!hasLinkedRepo && (!selectedInstallationId || !selectedRepositoryId)) {
      toast.error(t('Please select an installation and repository'))
      return
    }
    const ref = branch.trim()
    if (!ref) {
      toast.error(t('Please select a branch'))
      return
    }
    linkRepoThenDeployMutation.mutate()
  }

  const installationId = hasLinkedRepo
    ? (resource.installationId ?? undefined)
    : selectedInstallationId || undefined
  const providerRepositoryId = hasLinkedRepo
    ? (resource.providerRepositoryId ?? undefined)
    : selectedRepositoryId || undefined

  // Same query key as BranchSelector's, so this reads its result rather than
  // issuing a second request. Kept off until the modal opens, since the modal
  // stays mounted for the whole page.
  const {
    error: branchesError,
    isFetching: branchesFetching,
    refetch: refetchBranches,
  } = useQuery({
    ...repositoryBranchesQueryOptions(
      projectId,
      installationId,
      providerRepositoryId,
    ),
    enabled: open && !!installationId && !!providerRepositoryId,
  })

  // Every call the modal makes refreshes the same installation token, so any of
  // them failing means the deployment it would create cannot be built either.
  const installationErrorKind =
    getVcsInstallationErrorKind(repositoryError) ??
    getVcsInstallationErrorKind(branchesError) ??
    getVcsInstallationErrorKind(linkRepoThenDeployMutation.error)
  const {
    provider: reconnectProvider,
    organization: reconnectOrganization,
    reconnectUrl,
  } = useVcsInstallationReconnect(projectId, installationId)

  const handleRetryInstallation = () => {
    linkRepoThenDeployMutation.reset()
    refetchRepository()
    refetchBranches()
  }

  const docsUrl =
    resourceType === 'function' ? FUNCTIONS_DEPLOY_DOCS : SITES_DEPLOY_DOCS
  const isPending = linkRepoThenDeployMutation.isPending

  const showRepoPicker = !hasLinkedRepo && !selectedRepositoryId
  const showNextSteps = hasLinkedRepo || selectedRepositoryId

  const handleRepositorySelect = (repo: Models.ProviderRepositoryFramework) => {
    setSelectedRepositoryId(repo.id)
    setSelectedRepoPushedAt(repo.pushedAt ?? null)
    setSelectedRepoDisplayName(
      [repo.organization, repo.name].filter(Boolean).join('/') || null,
    )
  }

  const handleBackToRepoPicker = () => {
    setSelectedRepositoryId('')
    setSelectedRepoPushedAt(null)
    setSelectedRepoDisplayName(null)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create git deployment')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {showRepoPicker
              ? t(
                  'Select a repository to deploy from. You can change it later in settings.',
                )
              : t(
                  'Choose the production branch and whether to activate the deployment after the build completes.',
                )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0 space-y-4">
          {showRepoPicker ? (
            <div>
              <Label className="text-[13px]">{t('Repository')}</Label>
              <RepositoryPicker
                projectId={projectId}
                getGitHubAuthUrl={getGitHubAuthUrl}
                getVcsAuthUrl={getVcsAuthUrl}
                installations={installations}
                selectedInstallationId={selectedInstallationId}
                onInstallationChange={setSelectedInstallationId}
                selectedRepositoryId={selectedRepositoryId}
                onRepositorySelect={handleRepositorySelect}
                mode="connect"
                detectionType={
                  resourceType === 'function' ? 'runtime' : 'framework'
                }
                className="mt-2"
              />
            </div>
          ) : (
            <>
              {installationErrorKind && (
                <VcsInstallationErrorAlert
                  kind={installationErrorKind}
                  provider={reconnectProvider}
                  organization={reconnectOrganization}
                  reconnectUrl={reconnectUrl}
                  onRetry={handleRetryInstallation}
                  isRetrying={repositoryFetching || branchesFetching}
                >
                  {t(
                    'Appwrite could not read this repository, so a deployment created now would fail to build.',
                  )}
                </VcsInstallationErrorAlert>
              )}
              {hasLinkedRepo && repository && (
                <div className="rounded-lg border border-border bg-muted/20 px-3 py-2">
                  <p className="text-[13px] font-medium text-foreground truncate">
                    {repository.organization}/{repository.name}
                  </p>
                  {repository.pushedAt && (
                    <p className="text-[12px] text-muted-foreground mt-1">
                      {t('Last updated')}{' '}
                      <DateTooltip date={repository.pushedAt} />
                    </p>
                  )}
                  {repository.url && (
                    <a
                      href={repository.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1 link-neutral text-[12px] mt-1"
                    >
                      {t('Open')} <ExternalLink className="h-3 w-3" />
                    </a>
                  )}
                </div>
              )}
              {!hasLinkedRepo && selectedRepositoryId && (
                <div className="rounded-lg border border-border bg-muted/20 px-3 py-2">
                  <p className="text-[13px] font-medium text-foreground truncate">
                    {selectedRepoDisplayName ?? t('Repository')}
                  </p>
                  {selectedRepoPushedAt && (
                    <p className="text-[12px] text-muted-foreground mt-1">
                      {t('Last updated')}{' '}
                      <DateTooltip date={selectedRepoPushedAt} />
                    </p>
                  )}
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-7 text-[12px] text-muted-foreground hover:text-foreground mt-1 -ms-1"
                    onClick={handleBackToRepoPicker}
                  >
                    <ArrowLeft className="h-3 w-3 me-1" />
                    {t('Change repository')}
                  </Button>
                </div>
              )}
              <BranchSelector
                projectId={projectId}
                installationId={installationId}
                providerRepositoryId={providerRepositoryId}
                value={branch}
                onChange={setBranch}
                label={t('Production branch')}
                placeholder={t('Select branch')}
                suppressInstallationError={!!installationErrorKind}
              />
              <div className="flex items-center gap-2">
                <Checkbox
                  id="activate-after-build"
                  checked={activate}
                  onCheckedChange={(v) => setActivate(v === true)}
                />
                <Label
                  htmlFor="activate-after-build"
                  className="text-[13px] font-normal cursor-pointer"
                >
                  {t('Activate deployment after build')}
                </Label>
              </div>
              <DocsRouteLink
                href={docsUrl}
                className="inline-flex items-center gap-1 link-neutral text-[12px]"
              >
                {t('Deployment docs')} <ExternalLink className="h-3 w-3" />
              </DocsRouteLink>
            </>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {showNextSteps && (
            <>
              {!hasLinkedRepo && (
                <Button
                  variant="ghost"
                  onClick={handleBackToRepoPicker}
                  disabled={isPending}
                  className="h-9 text-[13px] me-auto sm:me-0 sm:order-first"
                >
                  <ArrowLeft className="h-3.5 w-3.5 me-1.5" />
                  {t('Back')}
                </Button>
              )}
              <Button
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={isPending}
                className="h-9 text-[13px]"
              >
                {t('Cancel')}
              </Button>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">
                    <Button
                      onClick={handleSubmit}
                      disabled={
                        isPending || !branch?.trim() || !!installationErrorKind
                      }
                      className="h-9 text-[13px]"
                    >
                      {t('Create deployment')}
                    </Button>
                  </span>
                </TooltipTrigger>
                {installationErrorKind ? (
                  <TooltipContent className="max-w-xs text-[13px]">
                    {t(
                      'The Git installation could not be reached, so this deployment cannot be created.',
                    )}
                  </TooltipContent>
                ) : null}
              </Tooltip>
            </>
          )}
          {showRepoPicker && (
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              className="h-9 text-[13px] ms-auto"
            >
              {t('Cancel')}
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
