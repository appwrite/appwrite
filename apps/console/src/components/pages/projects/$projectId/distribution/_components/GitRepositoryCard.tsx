import { useMemo, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ExternalLink, Loader2, Lock, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RepositoryPicker } from '@/components/global/shared/RepositoryPicker'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { VcsInstallationErrorAlert } from '@/components/global/shared/VcsInstallationError'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import type {
  DistributionApp,
  UpdateDistributionAppParams,
} from '@/lib/appwrite/distribution'
import { isRepositorySaved } from '@/lib/distribution/repository'
import {
  resolveConnectBranch,
  useProject,
  useRepository,
  useUpdateDistributionApp,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  getErrorMessage,
  getVcsInstallationErrorKind,
} from '@/lib/utils/error-formatting'
import {
  buildVcsAuthUrl,
  getVcsProvider,
  type VcsProviderId,
} from '@/lib/vcs/providers'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'

interface GitRepositoryCardProps {
  projectId: string
  app: DistributionApp
}

export function GitRepositoryCard({ projectId, app }: GitRepositoryCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const updateApp = useUpdateDistributionApp(projectId)
  const [disconnectDialogOpen, setDisconnectDialogOpen] = useState(false)
  const [selectedBranch, setSelectedBranch] = useSyncStateFromServer(
    app.providerBranch,
  )
  const [selectedDirectory, setSelectedDirectory] = useSyncStateFromServer(
    app.providerRootDirectory,
  )
  const [selectedInstallationId, setSelectedInstallationId] = useState('')
  const [selectedRepositoryId, setSelectedRepositoryId] = useState('')
  const [isConnecting, setIsConnecting] = useState(false)

  const hasRepository = Boolean(app.installationId && app.providerRepositoryId)

  const {
    data: repository,
    isLoading: repositoryLoading,
    isFetching: repositoryFetching,
    error: repositoryError,
    refetch: refetchRepository,
  } = useRepository(
    projectId,
    app.installationId || null,
    app.providerRepositoryId || null,
  )
  const repositoryErrorKind = getVcsInstallationErrorKind(repositoryError)
  const {
    provider: installationProvider,
    organization: installationOrganization,
    reconnectUrl,
  } = useVcsInstallationReconnect(projectId, app.installationId)

  const { data: installationsData, isLoading: installationsLoading } =
    useVcsInstallations(projectId)
  const installations = installationsData?.installations ?? []
  const hasInstallations = installations.length > 0
  const installationId = selectedInstallationId || installations[0]?.$id || ''

  const { project } = useProject(projectId)

  const getVcsAuthUrl = useMemo(() => {
    return (provider: VcsProviderId = 'github') => {
      if (typeof window === 'undefined') return '#'
      const redirectUrl = `${window.location.origin}/projects/${projectId}/distribution/${app.$id}/settings`
      return buildVcsAuthUrl({
        endpoint: getApiEndpoint(project?.region),
        provider,
        projectId,
        successUrl: redirectUrl,
        failureUrl: redirectUrl,
      })
    }
  }, [projectId, app.$id, project?.region])

  const refreshRepository = () => {
    for (const resource of ['repository', 'branches']) {
      queryClient.invalidateQueries({
        queryKey: [
          'vcs',
          resource,
          projectId,
          app.installationId,
          app.providerRepositoryId,
        ],
      })
    }
  }

  const branchChanged = selectedBranch !== app.providerBranch
  const directoryChanged = selectedDirectory !== app.providerRootDirectory
  const hasChanges = branchChanged || directoryChanged

  const handleSaveConfiguration = () => {
    if (!hasChanges) {
      toast.info(t('No changes to save'))
      return
    }
    const params: UpdateDistributionAppParams = {
      appId: app.$id,
      ...(branchChanged ? { providerBranch: selectedBranch } : {}),
      ...(directoryChanged ? { providerRootDirectory: selectedDirectory } : {}),
    }
    updateApp.mutate(params, {
      onSuccess: (updated) => {
        refreshRepository()
        if (!isRepositorySaved(params, updated)) {
          toast.error(t('Failed to update repository settings'), {
            description: t('The server did not save these changes.'),
          })
          return
        }
        toast.success(t('Repository settings updated successfully'))
      },
      onError: (error) => {
        toast.error(
          getErrorMessage(error, t('Failed to update repository settings')),
        )
      },
    })
  }

  const handleConnectRepository = async () => {
    if (!installationId || !selectedRepositoryId) {
      toast.error(t('Please select an installation and repository'))
      return
    }
    setIsConnecting(true)
    try {
      const providerBranch = await resolveConnectBranch(
        projectId,
        installationId,
        selectedRepositoryId,
        app.providerBranch,
      )
      const params: UpdateDistributionAppParams = {
        appId: app.$id,
        installationId,
        providerRepositoryId: selectedRepositoryId,
        providerBranch,
        providerSilentMode: false,
      }
      const updated = await updateApp.mutateAsync(params)
      if (!isRepositorySaved(params, updated)) {
        toast.error(t('Failed to connect repository'), {
          description: t('The server did not save these changes.'),
        })
        return
      }
      toast.success(t('Repository connected successfully'))
      setSelectedRepositoryId('')
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to connect repository')))
    } finally {
      setIsConnecting(false)
    }
  }

  const handleDisconnectRepository = () => {
    const params: UpdateDistributionAppParams = {
      appId: app.$id,
      installationId: '',
      providerRepositoryId: '',
      providerBranch: '',
      providerRootDirectory: '',
    }
    updateApp.mutate(params, {
      onSuccess: (updated) => {
        setDisconnectDialogOpen(false)
        if (!isRepositorySaved(params, updated)) {
          toast.error(t('Failed to disconnect repository'), {
            description: t('The server did not save these changes.'),
          })
          return
        }
        toast.success(t('Repository disconnected successfully'))
      },
      onError: (error) => {
        toast.error(
          getErrorMessage(error, t('Failed to disconnect repository')),
        )
      },
    })
  }

  const repositoryLabel = repository
    ? `${repository.organization}/${repository.name}`
    : installationOrganization || t('Connected repository')
  const repositoryUrl =
    repository && 'url' in repository && typeof repository.url === 'string'
      ? repository.url
      : ''
  const { Icon: RepositoryProviderIcon } = getVcsProvider(
    repository?.provider ?? installationProvider,
  )
  const isSaving = updateApp.isPending || isConnecting

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Repository')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Connect your app to a Git repository to build it when you push.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        {!hasRepository ? (
          <RepositoryPicker
            projectId={projectId}
            getGitHubAuthUrl={getVcsAuthUrl('github')}
            getVcsAuthUrl={getVcsAuthUrl}
            installations={installations}
            isLoadingInstallations={installationsLoading}
            selectedInstallationId={installationId}
            onInstallationChange={setSelectedInstallationId}
            selectedRepositoryId={selectedRepositoryId}
            onRepositorySelect={(selected) =>
              setSelectedRepositoryId(selected.id)
            }
            mode="connect"
            detectionType="framework"
          />
        ) : (
          <div className="space-y-4">
            {repositoryLoading ? (
              <div className="flex items-center justify-center py-4">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
              </div>
            ) : repository || repositoryErrorKind ? (
              <div className="flex items-center gap-3 rounded-md border border-border bg-background px-3 py-2">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
                  <RepositoryProviderIcon className="h-4 w-4 text-muted-foreground" />
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
                  {repositoryUrl && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 p-0"
                      asChild
                      aria-label={t('Open repository in new tab')}
                    >
                      <a
                        href={repositoryUrl}
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
                        disabled={isSaving}
                      >
                        <X className="me-1.5 h-4 w-4" />
                        {t('Disconnect')}
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 pb-4 text-start">
                        <DialogTitle>{t('Disconnect repository')}</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          {t(
                            'Pushes to this repository will stop building this app. Existing builds are kept.',
                          )}
                        </DialogDescription>
                      </DialogHeader>
                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={() => setDisconnectDialogOpen(false)}
                          disabled={updateApp.isPending}
                        >
                          {t('Cancel')}
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={handleDisconnectRepository}
                          disabled={updateApp.isPending}
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
                {repositoryErrorKind === 'missing'
                  ? t(
                      "This app's Git installation can no longer reach its repository, so pushes can't build. Disconnect the repository, then connect it again through the installation that has it now.",
                    )
                  : t(
                      "This app's repository details could not be loaded. Its Git connection is unchanged, but branch and root directory options are unavailable until access is restored.",
                    )}
              </VcsInstallationErrorAlert>
            )}

            <BranchSelector
              projectId={projectId}
              installationId={app.installationId}
              providerRepositoryId={app.providerRepositoryId}
              value={selectedBranch}
              onChange={setSelectedBranch}
              label={t('Production branch')}
              disabled={isSaving}
              suppressInstallationError={Boolean(repositoryErrorKind)}
            />

            <RootDirectoryPicker
              projectId={projectId}
              installationId={app.installationId}
              providerRepositoryId={app.providerRepositoryId}
              branch={selectedBranch || 'main'}
              value={selectedDirectory}
              onChange={setSelectedDirectory}
              label={t('Root directory')}
              description={t('Choose the directory containing your app code')}
              disabled={isSaving}
            />
          </div>
        )}
      </div>
      {hasRepository ? (
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={!hasChanges || isSaving}
            onClick={handleSaveConfiguration}
          >
            {t('Update')}
          </Button>
        </div>
      ) : hasInstallations ? (
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleConnectRepository}
            disabled={!installationId || !selectedRepositoryId || isSaving}
          >
            {t('Connect')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
