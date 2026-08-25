import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
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
  resolveConnectBranch,
  useRepository,
  useVcsInstallations,
  useProject,
  buildFunctionUpdateParams,
} from '@/lib/react-query/hooks'
import { GitBranch, Lock, ExternalLink, Loader2, X } from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { RepositoryPicker } from '@/components/global/shared/RepositoryPicker'
import { VcsInstallationErrorAlert } from '@/components/global/shared/VcsInstallationError'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'
import { useT } from '@/lib/i18n/translate'
import {
  getVcsProvider,
  buildVcsAuthUrl,
  type VcsProviderId,
} from '@/lib/vcs/providers'

interface GitSettingsCardProps {
  func: Models.Function
}

export function GitSettingsCard({ func }: GitSettingsCardProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const [connectDialogOpen, setConnectDialogOpen] = useState(false)
  const [disconnectDialogOpen, setDisconnectDialogOpen] = useState(false)

  // Form state
  const [selectedBranch, setSelectedBranch] = useState(
    func.providerBranch || '',
  )
  const [selectedDir, setSelectedDir] = useState(
    func.providerRootDirectory || '',
  )

  // Connect repository modal state
  const [selectedInstallationId, setSelectedInstallationId] =
    useState<string>('')
  const [selectedRepositoryId, setSelectedRepositoryId] = useState<string>('')

  // Fetch repository details if connected. Deliberately derived from the
  // function itself, never from the lookup below: a repository we cannot read
  // is still a connected repository.
  const hasRepository = func.installationId && func.providerRepositoryId

  const {
    data: repository,
    isLoading: repositoryLoading,
    isFetching: repositoryFetching,
    error: repositoryError,
    refetch: refetchRepository,
  } = useRepository(
    projectId,
    func.installationId || null,
    func.providerRepositoryId || null,
  )

  // The lookup refreshes the installation token, so a dead installation fails
  // here and leaves us with no repository record to render. Untreated that
  // drops the repository row and the card reads as "nothing connected".
  const repositoryErrorKind = getVcsInstallationErrorKind(repositoryError)
  const {
    provider: installationProvider,
    organization: installationOrganization,
    reconnectUrl,
  } = useVcsInstallationReconnect(projectId, func.installationId)

  // Fetch installations for connect modal
  const { data: installationsData } = useVcsInstallations(projectId)

  const { project } = useProject(projectId ?? undefined)

  const getVcsAuthUrl = useMemo(() => {
    return (provider: VcsProviderId = 'github') => {
      if (typeof window === 'undefined' || !projectId || !func.$id) return '#'
      const origin = window.location.origin
      const redirectUrl = `${origin}/projects/${projectId}/functions/${func.$id}/settings`
      const projectEndpoint = getApiEndpoint(project?.region)
      return buildVcsAuthUrl({
        endpoint: projectEndpoint,
        provider,
        projectId,
        successUrl: redirectUrl,
        failureUrl: redirectUrl,
      })
    }
  }, [projectId, func.$id, project?.region])
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

  // Keep form in sync when server function changes (e.g. after connect or refetch)
  useEffect(() => {
    setSelectedBranch(func.providerBranch || '')
    setSelectedDir(func.providerRootDirectory || '')
  }, [
    func.providerBranch,
    func.providerRootDirectory,
    func.installationId,
    func.providerRepositoryId,
  ])

  // Update function mutation
  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !func.$id)
        throw new Error('Project ID and Function ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Function updated successfully'))
      queryClient.setQueryData(
        ['function', 'project', projectId, func.$id],
        updated,
      )
      // Refresh repository data if connected
      if (hasRepository) {
        queryClient.invalidateQueries({
          queryKey: [
            'vcs',
            'repository',
            projectId,
            func.installationId,
            func.providerRepositoryId,
          ],
        })
        queryClient.invalidateQueries({
          queryKey: [
            'vcs',
            'branches',
            projectId,
            func.installationId,
            func.providerRepositoryId,
          ],
        })
      }
    },
    onError: (error: unknown) => {
      toast.error(error.message || t('Failed to update function'))
    },
  })

  // Connect repository mutation
  const connectRepositoryMutation = useMutation({
    mutationFn: async () => {
      if (
        !projectId ||
        !func.$id ||
        !selectedInstallationId ||
        !selectedRepositoryId
      ) {
        throw new Error('Installation and Repository are required')
      }
      const providerBranch = await resolveConnectBranch(
        projectId,
        selectedInstallationId,
        selectedRepositoryId,
        func.providerBranch ?? '',
      )

      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, {
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
        ['function', 'project', projectId, func.$id],
        updated,
      )
    },
    onError: (error: unknown) => {
      toast.error(error.message || t('Failed to connect repository'))
    },
  })

  // Disconnect repository mutation
  const disconnectRepositoryMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !func.$id)
        throw new Error('Project ID and Function ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, {
          installationId: '',
          providerRepositoryId: '',
          providerBranch: '',
          providerSilentMode: true,
          providerRootDirectory: '',
        }),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Repository disconnected successfully'))
      setDisconnectDialogOpen(false)
      setSelectedBranch('')
      setSelectedDir('')
      queryClient.setQueryData(
        ['function', 'project', projectId, func.$id],
        updated,
      )
    },
    onError: (error: unknown) => {
      toast.error(error.message || t('Failed to disconnect repository'))
    },
  })

  const handleSaveConfiguration = () => {
    const hasChanges =
      selectedBranch !== func.providerBranch ||
      selectedDir !== (func.providerRootDirectory || '')

    if (!hasChanges) {
      toast.info(t('No changes to save'))
      return
    }

    updateFunctionMutation.mutate({
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
      selectedBranch !== func.providerBranch ||
      selectedDir !== (func.providerRootDirectory || '')
    )
  }, [selectedBranch, selectedDir, func])

  // Null while the repository is unreachable: only the provider knows its
  // owner and name, so the affected rows fall back to generic wording.
  const repositoryLabel = repository
    ? `${repository.organization}/${repository.name}`
    : null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Repository')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Connect your function to a Git repository for automatic deployments',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        {!hasRepository ? (
          // No repository connected state
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
                      'Select a Git installation and repository to connect to this function',
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
                    detectionType="runtime"
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
          // Repository connected state
          <div className="space-y-4">
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
                  'This function is still connected to its repository, but the repository details could not be loaded.',
                )}
              </VcsInstallationErrorAlert>
            )}

            {/* Repository info. Kept on screen for an unreachable repository so
                the connection stays visible and can still be removed. */}
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
                      {repositoryLabel ?? t('Repository details unavailable')}
                    </p>
                    {repository?.private && (
                      <Lock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    )}
                  </div>
                  {repository?.pushedAt && (
                    <p className="text-[12px] text-muted-foreground">
                      {t('Last updated')}{' '}
                      <DateTooltip date={repository.pushedAt} />
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  {repository?.url && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 p-0"
                      asChild
                      aria-label={t('Open repository in new tab')}
                    >
                      <a href={repository.url} target="_blank" rel="noreferrer">
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
                        <DialogTitle>{t('Disconnect Repository')}</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          {t('Are you sure you want to disconnect')}{' '}
                          <span className="font-medium text-foreground">
                            {repositoryLabel ?? t('this repository')}
                          </span>{' '}
                          {t(
                            'from this function? This will remove all Git configuration and you will need to reconnect the repository to enable automatic deployments.',
                          )}
                        </DialogDescription>
                      </DialogHeader>
                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          onClick={() => setDisconnectDialogOpen(false)}
                          disabled={disconnectRepositoryMutation.isPending}
                        >
                          {t('Cancel')}
                        </Button>
                        <Button
                          variant="destructive"
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

            <fieldset className="rounded-lg border border-border p-4 space-y-4">
              <legend className="text-[13px] font-medium text-foreground px-2">
                {t('Branch Settings')}
              </legend>

              {/* Branch selector */}
              <BranchSelector
                projectId={projectId}
                installationId={func.installationId}
                providerRepositoryId={func.providerRepositoryId}
                value={selectedBranch}
                onChange={setSelectedBranch}
                label={t('Production branch')}
                suppressInstallationError={!!repositoryErrorKind}
              />

              {/* Root directory selector */}
              <RootDirectoryPicker
                projectId={projectId}
                installationId={func.installationId}
                providerRepositoryId={func.providerRepositoryId}
                branch={selectedBranch || 'main'}
                value={selectedDir}
                onChange={setSelectedDir}
                label={t('Root directory')}
                description={t(
                  'Choose the directory containing your function code',
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
            disabled={!hasChanges || updateFunctionMutation.isPending}
            onClick={handleSaveConfiguration}
          >
            {t('Update')}
          </Button>
        </div>
      )}
    </div>
  )
}
