import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
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
  useRepository,
  useVcsInstallations,
  useProject,
} from '@/lib/react-query/hooks'
import { GitBranch, Lock, ExternalLink, Loader2, X } from 'lucide-react'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { RepositoryPicker } from '@/components/global/shared/RepositoryPicker'

// GitHub Icon Component
function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

interface GitSettingsCardProps {
  func: Models.Function
}

export function GitSettingsCard({ func }: GitSettingsCardProps) {
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
  const [silentMode, setSilentMode] = useState(func.providerSilentMode ?? false)

  // Connect repository modal state
  const [selectedInstallationId, setSelectedInstallationId] =
    useState<string>('')
  const [selectedRepositoryId, setSelectedRepositoryId] = useState<string>('')

  // Fetch repository details if connected
  const hasRepository = func.installationId && func.providerRepositoryId

  const { data: repository, isLoading: repositoryLoading } = useRepository(
    projectId,
    func.installationId || null,
    func.providerRepositoryId || null,
  )

  // Fetch installations for connect modal
  const { data: installationsData } = useVcsInstallations(projectId)

  const { project } = useProject(projectId ?? undefined)

  const getGitHubAuthUrl = useMemo(() => {
    if (typeof window === 'undefined' || !projectId || !func.$id) return '#'
    const origin = window.location.origin
    const redirectUrl = `${origin}/projects/${projectId}/functions/${func.$id}/settings`
    const successUrl = encodeURIComponent(redirectUrl)
    const failureUrl = encodeURIComponent(redirectUrl)
    const projectEndpoint = getApiEndpoint(project?.region)
    return `${projectEndpoint}/vcs/github/authorize?project=${projectId}&success=${successUrl}&failure=${failureUrl}&mode=admin`
  }, [projectId, func.$id, project?.region])

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

  // Update function mutation
  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !func.$id)
        throw new Error('Project ID and Function ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update({
        functionId: func.$id,
        name: func.name,
        runtime: func.runtime,
        execute: func.execute || undefined,
        events: func.events || undefined,
        schedule: func.schedule || undefined,
        timeout: func.timeout || undefined,
        enabled: func.enabled ?? undefined,
        logging: func.logging ?? undefined,
        entrypoint: func.entrypoint || undefined,
        commands: func.commands || undefined,
        scopes: func.scopes || undefined,
        ...updates,
      })
    },
    onSuccess: () => {
      toast.success('Function updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, func.$id],
      })
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
      toast.error(error.message || 'Failed to update function')
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
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update({
        functionId: func.$id,
        name: func.name,
        runtime: func.runtime,
        execute: func.execute || undefined,
        events: func.events || undefined,
        schedule: func.schedule || undefined,
        timeout: func.timeout || undefined,
        enabled: func.enabled ?? undefined,
        logging: func.logging ?? undefined,
        entrypoint: func.entrypoint || undefined,
        commands: func.commands || undefined,
        scopes: func.scopes || undefined,
        installationId: selectedInstallationId,
        providerRepositoryId: selectedRepositoryId,
        providerBranch: 'main',
      })
    },
    onSuccess: () => {
      toast.success('Repository connected successfully')
      setConnectDialogOpen(false)
      setSelectedRepositoryId('')
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, func.$id],
      })
    },
    onError: (error: unknown) => {
      toast.error(error.message || 'Failed to connect repository')
    },
  })

  // Disconnect repository mutation
  const disconnectRepositoryMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !func.$id)
        throw new Error('Project ID and Function ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update({
        functionId: func.$id,
        name: func.name,
        runtime: func.runtime,
        execute: func.execute || undefined,
        events: func.events || undefined,
        schedule: func.schedule || undefined,
        timeout: func.timeout || undefined,
        enabled: func.enabled ?? undefined,
        logging: func.logging ?? undefined,
        entrypoint: func.entrypoint || undefined,
        commands: func.commands || undefined,
        scopes: func.scopes || undefined,
        installationId: '',
        providerRepositoryId: '',
        providerBranch: '',
        providerSilentMode: true,
        providerRootDirectory: '',
      })
    },
    onSuccess: () => {
      toast.success('Repository disconnected successfully')
      setDisconnectDialogOpen(false)
      setSelectedBranch('')
      setSelectedDir('')
      setSilentMode(false)
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, func.$id],
      })
    },
    onError: (error: unknown) => {
      toast.error(error.message || 'Failed to disconnect repository')
    },
  })

  const handleSaveConfiguration = () => {
    const hasChanges =
      selectedBranch !== func.providerBranch ||
      selectedDir !== (func.providerRootDirectory || '') ||
      silentMode !== (func.providerSilentMode ?? false)

    if (!hasChanges) {
      toast.info('No changes to save')
      return
    }

    updateFunctionMutation.mutate({
      providerBranch: selectedBranch || undefined,
      providerRootDirectory: selectedDir || undefined,
      providerSilentMode: silentMode,
    })
  }

  const handleConnectRepository = () => {
    if (!selectedInstallationId || !selectedRepositoryId) {
      toast.error('Please select an installation and repository')
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
      selectedDir !== (func.providerRootDirectory || '') ||
      silentMode !== (func.providerSilentMode ?? false)
    )
  }, [selectedBranch, selectedDir, silentMode, func])

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Git Repository
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Connect your function to a Git repository for automatic deployments
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        {!hasRepository ? (
          // No repository connected state
          <div className="flex flex-col items-center justify-center py-8 text-center">
            <EmptyState
              icon={GitBranch}
              title="No repository connected"
              description="Connect a repository to enable automatic deployments"
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
                  Connect repository
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-2xl p-0">
                <DialogHeader className="px-6 pt-6 text-left">
                  <DialogTitle>Connect repository</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    Select a GitHub installation and repository to connect to
                    this function
                  </DialogDescription>
                </DialogHeader>
                <div className="border-t border-border" />
                <div className="px-6 pb-4 pt-4 max-h-[70vh] overflow-y-auto">
                  <RepositoryPicker
                    projectId={projectId}
                    getGitHubAuthUrl={getGitHubAuthUrl}
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
                    Cancel
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
                    Connect
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </div>
        ) : (
          // Repository connected state
          <div className="space-y-4">
            {/* Repository info */}
            {repositoryLoading ? (
              <div className="flex items-center justify-center py-4">
                <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
              </div>
            ) : repository ? (
              <div className="flex items-center gap-3 rounded-md border border-border bg-background px-3 py-2">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
                  <GitHubIcon className="h-4 w-4 text-muted-foreground" />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="truncate text-[13px] font-medium text-foreground">
                      {repository.organization}/{repository.name}
                    </p>
                    {repository.private && (
                      <Lock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    )}
                  </div>
                  {repository.pushedAt && (
                    <p className="text-[12px] text-muted-foreground">
                      Last updated <DateTooltip date={repository.pushedAt} />
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  {repository.url && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 p-0"
                      asChild
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
                        <X className="mr-1.5 h-4 w-4" />
                        Disconnect
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 text-left">
                        <DialogTitle>Disconnect Repository</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          Are you sure you want to disconnect{' '}
                          <span className="font-medium text-foreground">
                            {repository.organization}/{repository.name}
                          </span>{' '}
                          from this function? This will remove all Git
                          configuration and you will need to reconnect the
                          repository to enable automatic deployments.
                        </DialogDescription>
                      </DialogHeader>
                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          onClick={() => setDisconnectDialogOpen(false)}
                          disabled={disconnectRepositoryMutation.isPending}
                        >
                          Cancel
                        </Button>
                        <Button
                          variant="destructive"
                          onClick={handleDisconnectRepository}
                          disabled={disconnectRepositoryMutation.isPending}
                        >
                          Disconnect
                        </Button>
                      </div>
                    </DialogContent>
                  </Dialog>
                </div>
              </div>
            ) : null}

            <fieldset className="rounded-lg border border-border p-4 space-y-4">
              <legend className="text-[13px] font-medium text-foreground px-2">
                Branch Settings
              </legend>

              {/* Branch selector */}
              <BranchSelector
                projectId={projectId}
                installationId={func.installationId}
                providerRepositoryId={func.providerRepositoryId}
                value={selectedBranch}
                onChange={setSelectedBranch}
                label="Production branch"
              />

              {/* Root directory selector */}
              <RootDirectoryPicker
                projectId={projectId}
                installationId={func.installationId}
                providerRepositoryId={func.providerRepositoryId}
                branch={selectedBranch || 'main'}
                value={selectedDir}
                onChange={setSelectedDir}
                label="Root directory"
                description="Choose the directory containing your function code"
              />

              {/* Silent mode toggle */}
              <div className="flex items-center justify-between">
                <div>
                  <Label htmlFor="silentMode" className="text-[13px]">
                    Silent mode
                  </Label>
                  <p className="text-[12px] text-muted-foreground">
                    Disable automated comments on repository commits
                  </p>
                </div>
                <Switch
                  id="silentMode"
                  checked={silentMode}
                  onCheckedChange={setSilentMode}
                  disabled={updateFunctionMutation.isPending}
                />
              </div>
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
            Update
          </Button>
        </div>
      )}
    </div>
  )
}
