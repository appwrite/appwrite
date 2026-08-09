/**
 * Repository card - Git connection and configuration for the site.
 * Shows an empty state with "Connect repository" when no repo is connected.
 */

import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk, getApiEndpoint } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import {
  getErrorMessage,
  getVcsInstallationErrorKind,
} from '@/lib/utils/error-formatting'
import {
  buildSiteUpdateParams,
  resolveConnectBranch,
  useRepository,
  useVcsInstallations,
  useProject,
} from '@/lib/react-query/hooks'
import { GitBranch, Lock, ExternalLink, Loader2, X } from 'lucide-react'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RepositoryPicker } from '@/components/global/shared/RepositoryPicker'
import { VcsInstallationErrorAlert } from '@/components/global/shared/VcsInstallationError'
import { useT } from '@/lib/i18n/translate'
import {
  getVcsProvider,
  buildVcsAuthUrl,
  type VcsProviderId,
} from '@/lib/vcs/providers'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'

interface GitRepositoryCardProps {
  projectId: string | null | undefined
  siteId: string | null | undefined
  site: Models.Site | null | undefined
}

export function GitRepositoryCard({
  projectId,
  siteId,
  site,
}: GitRepositoryCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [connectDialogOpen, setConnectDialogOpen] = useState(false)
  const [disconnectDialogOpen, setDisconnectDialogOpen] = useState(false)

  // Form state
  const [selectedBranch, setSelectedBranch] = useState(
    site?.providerBranch || '',
  )
  const [selectedDir, setSelectedDir] = useState(
    site?.providerRootDirectory || '',
  )

  // Connect repository modal state
  const [selectedInstallationId, setSelectedInstallationId] =
    useState<string>('')
  const [selectedRepositoryId, setSelectedRepositoryId] = useState<string>('')

  // Fetch repository details if connected
  const hasRepository = site?.installationId && site.providerRepositoryId

  const {
    data: repository,
    isLoading: repositoryLoading,
    isFetching: repositoryFetching,
    error: repositoryError,
    refetch: refetchRepository,
  } = useRepository(
    projectId,
    site?.installationId || null,
    site?.providerRepositoryId || null,
  )

  // A dead installation token makes this lookup fail, which used to render as
  // "no repository row at all" even though the site is still connected.
  const repositoryErrorKind = getVcsInstallationErrorKind(repositoryError)
  const {
    provider: installationProvider,
    organization: installationOrganization,
    reconnectUrl,
  } = useVcsInstallationReconnect(projectId, site?.installationId)

  // Fetch installations for connect modal
  const { data: installationsData } = useVcsInstallations(projectId)

  const { project } = useProject(projectId ?? undefined)

  const getVcsAuthUrl = useMemo(() => {
    return (provider: VcsProviderId = 'github') => {
      if (typeof window === 'undefined' || !projectId || !siteId) return '#'
      const origin = window.location.origin
      const redirectUrl = `${origin}/projects/${projectId}/sites/${siteId}/settings`
      const projectEndpoint = getApiEndpoint(project?.region)
      return buildVcsAuthUrl({
        endpoint: projectEndpoint,
        provider,
        projectId,
        successUrl: redirectUrl,
        failureUrl: redirectUrl,
      })
    }
  }, [projectId, siteId, project?.region])
  const getGitHubAuthUrl = getVcsAuthUrl('github')

  // Initialize selected installation when installations load
  useEffect(() => {
    if (
      installationsData?.installations &&
      installationsData.installations.length > 0 &&
      !selectedInstallationId
    ) {
      setSelectedInstallationId(installationsData.installations[0].$id)
    }
  }, [installationsData, selectedInstallationId])

  // Update form state when site changes
  useEffect(() => {
    if (site) {
      setSelectedBranch(site.providerBranch || '')
      setSelectedDir(site.providerRootDirectory || '')
    }
  }, [site])

  // Update site mutation
  const updateSiteMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Site>) => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(buildSiteUpdateParams(site, updates))
    },
    onSuccess: (updated) => {
      toast.success(t('Repository settings updated successfully'))
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
      if (hasRepository) {
        queryClient.invalidateQueries({
          queryKey: [
            'vcs',
            'repository',
            projectId,
            site.installationId,
            site.providerRepositoryId,
          ],
        })
        queryClient.invalidateQueries({
          queryKey: [
            'vcs',
            'branches',
            projectId,
            site.installationId,
            site.providerRepositoryId,
          ],
        })
      }
    },
    onError: (error: unknown) => {
      toast.error(
        getErrorMessage(error, t('Failed to update repository settings')),
      )
    },
  })

  // Connect repository mutation
  const connectRepositoryMutation = useMutation({
    mutationFn: async () => {
      if (
        !projectId ||
        !siteId ||
        !site ||
        !selectedInstallationId ||
        !selectedRepositoryId
      ) {
        throw new Error('Installation and Repository are required')
      }
      const providerBranch = await resolveConnectBranch(
        projectId,
        selectedInstallationId,
        selectedRepositoryId,
        site.providerBranch ?? '',
      )

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(
        buildSiteUpdateParams(site, {
          installationId: selectedInstallationId,
          providerRepositoryId: selectedRepositoryId,
          providerBranch,
        }),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Repository connected successfully'))
      setConnectDialogOpen(false)
      setSelectedRepositoryId('')
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to connect repository')))
    },
  })

  // Disconnect repository mutation
  const disconnectRepositoryMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !siteId || !site)
        throw new Error('Project ID, Site ID, and Site are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(
        buildSiteUpdateParams(site, {
          installationId: undefined,
          providerRepositoryId: undefined,
          providerBranch: undefined,
          providerSilentMode: undefined,
          providerRootDirectory: undefined,
        }),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Repository disconnected successfully'))
      setDisconnectDialogOpen(false)
      setSelectedBranch('')
      setSelectedDir('')
      queryClient.setQueryData(
        ['site', 'project', projectId, siteId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['sites', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to disconnect repository')))
    },
  })

  const handleSaveConfiguration = () => {
    const hasChanges =
      selectedBranch !== site?.providerBranch ||
      selectedDir !== (site?.providerRootDirectory || '')

    if (!hasChanges) {
      toast.info(t('No changes to save'))
      return
    }

    updateSiteMutation.mutate({
      providerBranch: selectedBranch || undefined,
      providerRootDirectory: selectedDir || undefined,
    })
  }

  const handleConnectRepository = () => {
    if (!selectedInstallationId || !selectedRepositoryId) {
      toast.error(t('Please select an installation and repository'))
      return
    }
    connectRepositoryMutation.mutate()
  }

  const handleDisconnectRepository = () => {
    disconnectRepositoryMutation.mutate()
  }

  const hasChanges = useMemo(() => {
    return (
      selectedBranch !== site?.providerBranch ||
      selectedDir !== (site?.providerRootDirectory || '')
    )
  }, [selectedBranch, selectedDir, site])

  // The site document stores the provider's repository id, not its name, so a
  // failed lookup falls back to the installation owner instead of an empty row.
  const repositoryLabel = repository
    ? `${repository.organization}/${repository.name}`
    : installationOrganization || t('Connected repository')

  const installationId: string | undefined =
    site?.installationId != null ? site.installationId : undefined
  const providerRepositoryId: string | undefined =
    site?.providerRepositoryId != null ? site.providerRepositoryId : undefined

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Repository')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Connect your site to a Git repository for automatic deployments')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        {!hasRepository ? (
          <div className="flex flex-col items-center justify-center py-8 text-center">
            <EmptyState
              icon={GitBranch}
              title={t('No repository connected')}
              description={t(
                'Connect a repository to enable automatic deployments',
              )}
              isEmpty={true}
              iconSize="md"
            />
            <Dialog
              open={connectDialogOpen}
              onOpenChange={(open) => {
                setConnectDialogOpen(open)
                if (open) setSelectedRepositoryId('')
              }}
            >
              <DialogTrigger asChild>
                <Button size="sm" className="h-9 text-[13px] mt-4">
                  {t('Connect repository')}
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-2xl p-0">
                <DialogHeader className="px-6 pt-6 text-start">
                  <DialogTitle>{t('Connect repository')}</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    {t(
                      'Select a Git installation and repository to connect to this site. You can connect an existing repository or create a new site from a template.',
                    )}
                  </DialogDescription>
                </DialogHeader>
                <div className="border-t border-border" />
                <div className="px-6 pb-4 pt-4 max-h-[70dvh] overflow-y-auto">
                  <RepositoryPicker
                    projectId={projectId}
                    getGitHubAuthUrl={getGitHubAuthUrl}
                    getVcsAuthUrl={getVcsAuthUrl}
                    installations={installationsData?.installations ?? []}
                    selectedInstallationId={selectedInstallationId}
                    onInstallationChange={setSelectedInstallationId}
                    selectedRepositoryId={selectedRepositoryId}
                    onRepositorySelect={(repo) =>
                      setSelectedRepositoryId(repo.id)
                    }
                    mode="connect"
                    showCreateNewSiteLink
                  />
                </div>
                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => setConnectDialogOpen(false)}
                    disabled={connectRepositoryMutation.isPending}
                  >
                    {t('Cancel')}
                  </Button>
                  <Button
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={handleConnectRepository}
                    disabled={
                      !selectedInstallationId ||
                      !selectedRepositoryId ||
                      connectRepositoryMutation.isPending
                    }
                  >
                    {t('Connect')}
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </div>
        ) : (
          <div className="space-y-4">
            {repositoryLoading ? (
              <div className="flex items-center justify-center py-4">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
              </div>
            ) : repository || repositoryErrorKind ? (
              <div className="flex items-center gap-3 rounded-md border border-border bg-background px-3 py-2">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
                  {(() => {
                    const { Icon: RepositoryProviderIcon } = getVcsProvider(
                      repository?.provider ?? installationProvider,
                    )
                    return (
                      <RepositoryProviderIcon className="h-4 w-4 text-muted-foreground" />
                    )
                  })()}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="truncate text-[13px] font-medium text-foreground">
                      {repositoryLabel}
                    </p>
                    {repository?.private && (
                      <Lock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    )}
                  </div>
                  {!repository ? (
                    <p className="text-[12px] text-muted-foreground">
                      {t('Repository details unavailable')}
                    </p>
                  ) : (
                    'pushedAt' in repository &&
                    repository.pushedAt && (
                      <p className="text-[12px] text-muted-foreground">
                        {t('Last updated')}{' '}
                        <DateTooltip date={repository.pushedAt} />
                      </p>
                    )
                  )}
                </div>
                <div className="flex items-center gap-2">
                  {repository && (repository as { url?: string }).url && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 p-0"
                      asChild
                      aria-label={t('Open repository in new tab')}
                    >
                      <a
                        href={(repository as { url?: string }).url!}
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        <ExternalLink className="h-4 w-4" />
                      </a>
                    </Button>
                  )}
                  <Dialog
                    open={disconnectDialogOpen}
                    onOpenChange={setDisconnectDialogOpen}
                  >
                    <DialogTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-8 text-[13px] text-foreground hover:text-foreground"
                      >
                        <X className="me-1.5 h-4 w-4" />
                        {t('Disconnect')}
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 text-start">
                        <DialogTitle>{t('Disconnect repository')}</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          {t('Are you sure you want to disconnect')}{' '}
                          <span className="font-medium text-foreground">
                            {repositoryLabel}
                          </span>{' '}
                          {t(
                            "from this site? This will remove the Git integration but won't affect your deployments.",
                          )}
                        </DialogDescription>
                      </DialogHeader>
                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={() => setDisconnectDialogOpen(false)}
                          disabled={disconnectRepositoryMutation.isPending}
                        >
                          {t('Cancel')}
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={handleDisconnectRepository}
                          disabled={disconnectRepositoryMutation.isPending}
                        >
                          {t('Disconnect')}
                        </Button>
                      </div>
                    </DialogContent>
                  </Dialog>
                </div>
              </div>
            ) : null}

            {repositoryErrorKind && (
              <VcsInstallationErrorAlert
                kind={repositoryErrorKind}
                provider={installationProvider}
                organization={installationOrganization}
                reconnectUrl={reconnectUrl}
                onRetry={() => refetchRepository()}
                isRetrying={repositoryFetching}
              >
                {t(
                  "This site's repository details could not be loaded. Its Git connection is unchanged, but branch and root directory options are unavailable until access is restored.",
                )}
              </VcsInstallationErrorAlert>
            )}

            <fieldset className="rounded-lg border border-border p-4 space-y-4">
              <legend className="text-[13px] font-medium text-foreground px-2">
                {t('Branch Settings')}
              </legend>

              <BranchSelector
                projectId={projectId ?? undefined}
                installationId={installationId}
                providerRepositoryId={providerRepositoryId}
                value={selectedBranch}
                onChange={setSelectedBranch}
                label={t('Production branch')}
                suppressInstallationError={!!repositoryErrorKind}
              />

              <RootDirectoryPicker
                projectId={projectId ?? undefined}
                installationId={installationId}
                providerRepositoryId={providerRepositoryId}
                branch={selectedBranch || 'main'}
                value={selectedDir}
                onChange={setSelectedDir}
                label={t('Root directory')}
                description={t(
                  'Choose the directory containing your site code',
                )}
              />
            </fieldset>
          </div>
        )}
      </div>
      {hasRepository && (
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={!hasChanges || updateSiteMutation.isPending}
            onClick={handleSaveConfiguration}
          >
            {t('Update')}
          </Button>
        </div>
      )}
    </div>
  )
}
