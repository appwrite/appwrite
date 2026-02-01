/**
 * Git Repository Card Component
 *
 * Manages Git repository connection and configuration for the site.
 * Only shown if the site has a connected repository.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import type { Models } from '@appwrite.io/console'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildSiteUpdateParams,
  useRepository,
  useVcsInstallations,
  useRepositories,
  useRepositoryBranches,
} from '@/lib/react-query/hooks'
import { VCSDetectionType } from '@appwrite.io/console'
import { GitBranch, Lock, ExternalLink, Loader2, X } from 'lucide-react'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { cn } from '@/lib/utils'

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
  const [silentMode, setSilentMode] = useState(site?.providerSilentMode ?? false)

  // Connect repository modal state
  const [selectedInstallationId, setSelectedInstallationId] =
    useState<string>('')
  const [selectedRepositoryId, setSelectedRepositoryId] = useState<string>('')
  const [repositorySearch, setRepositorySearch] = useState('')
  const [repositoryPage, setRepositoryPage] = useState(0)
  const [debouncedSearch, setDebouncedSearch] = useState('')

  // Fetch repository details if connected
  const hasRepository = site?.installationId && site.providerRepositoryId

  const { data: repository, isLoading: repositoryLoading } = useRepository(
    projectId,
    site?.installationId || null,
    site?.providerRepositoryId || null,
  )

  // Fetch installations for connect modal
  const { data: installationsData } = useVcsInstallations(projectId)

  // Fetch repositories for selected installation
  const { data: repositoriesData, isLoading: repositoriesLoading } =
    useRepositories(
      projectId,
      selectedInstallationId || null,
      VCSDetectionType.Framework,
      repositoryPage,
      5,
      debouncedSearch || undefined,
    )

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(repositorySearch)
      setRepositoryPage(0)
    }, 300)
    return () => clearTimeout(timer)
  }, [repositorySearch])

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
      setSilentMode(site.providerSilentMode ?? false)
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
    onSuccess: () => {
      toast.success('Repository settings updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
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
    onError: (error: any) => {
      toast.error(getErrorMessage(error, 'Failed to update repository settings'))
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
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.sites.update(
        buildSiteUpdateParams(site, {
          installationId: selectedInstallationId,
          providerRepositoryId: selectedRepositoryId,
          providerBranch: 'main',
        }),
      )
    },
    onSuccess: () => {
      toast.success('Repository connected successfully')
      setConnectDialogOpen(false)
      setSelectedRepositoryId('')
      setRepositorySearch('')
      setRepositoryPage(0)
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
    },
    onError: (error: any) => {
      toast.error(getErrorMessage(error, 'Failed to connect repository'))
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
    onSuccess: () => {
      toast.success('Repository disconnected successfully')
      setDisconnectDialogOpen(false)
      setSelectedBranch('')
      setSelectedDir('')
      setSilentMode(false)
      queryClient.invalidateQueries({
        queryKey: ['site', 'project', projectId, siteId],
      })
    },
    onError: (error: any) => {
      toast.error(getErrorMessage(error, 'Failed to disconnect repository'))
    },
  })

  const handleSaveConfiguration = () => {
    const hasChanges =
      selectedBranch !== site?.providerBranch ||
      selectedDir !== (site?.providerRootDirectory || '') ||
      silentMode !== (site?.providerSilentMode ?? false)

    if (!hasChanges) {
      toast.info('No changes to save')
      return
    }

    updateSiteMutation.mutate({
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

  const repositories = useMemo(() => {
    return repositoriesData?.runtimeProviderRepositories || []
  }, [repositoriesData])

  const hasChanges = useMemo(() => {
    return (
      selectedBranch !== site?.providerBranch ||
      selectedDir !== (site?.providerRootDirectory || '') ||
      silentMode !== (site?.providerSilentMode ?? false)
    )
  }, [selectedBranch, selectedDir, silentMode, site])

  // Only show if repository is connected
  if (!hasRepository) {
    return null
  }

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Git Repository
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Manage Git repository connection and configuration
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="space-y-4">
            {/* Repository Connection */}
            <div>
              <Label className="text-[13px]">Repository</Label>
              <div className="mt-2 flex items-center gap-3 rounded-lg border border-border bg-background p-3">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted">
                  <GitHubIcon className="h-4 w-4 text-muted-foreground" />
                </div>
                <div className="flex-1 min-w-0">
                  {repositoryLoading ? (
                    <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                  ) : repository ? (
                    <>
                      <p className="text-[13px] font-medium text-foreground truncate">
                        {repository.organization}/{repository.name}
                      </p>
                      {repository.private && (
                        <p className="text-[11px] text-muted-foreground flex items-center gap-1">
                          <Lock className="h-3 w-3" />
                          Private
                        </p>
                      )}
                    </>
                  ) : (
                    <p className="text-[13px] text-muted-foreground">
                      Loading repository...
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  {repository?.url && (
                    <a
                      href={repository.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-muted-foreground hover:text-foreground"
                    >
                      <ExternalLink className="h-4 w-4" />
                    </a>
                  )}
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 text-[12px]"
                    onClick={() => setConnectDialogOpen(true)}
                  >
                    Change
                  </Button>
                </div>
              </div>
            </div>

            {/* Branch Selection */}
            {hasRepository && (
              <BranchSelector
                projectId={projectId}
                installationId={site.installationId}
                providerRepositoryId={site.providerRepositoryId}
                value={selectedBranch}
                onChange={setSelectedBranch}
                label="Branch"
              />
            )}

            {/* Root Directory */}
            {hasRepository && (
              <RootDirectoryPicker
                projectId={projectId}
                installationId={site.installationId}
                providerRepositoryId={site.providerRepositoryId}
                branch={selectedBranch || 'main'}
                value={selectedDir}
                onChange={setSelectedDir}
                label="Root Directory"
                description="Choose the directory containing your site code"
              />
            )}

            {/* Silent Mode */}
            <div className="flex items-center justify-between">
              <div>
                <Label htmlFor="silent-mode" className="text-[13px]">
                  Silent Mode
                </Label>
                <p className="text-[11px] text-muted-foreground">
                  Disable automated comments on repository commits
                </p>
              </div>
              <Switch
                id="silent-mode"
                checked={silentMode}
                onCheckedChange={setSilentMode}
              />
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <div className="flex items-center justify-between">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setDisconnectDialogOpen(true)}
              disabled={disconnectRepositoryMutation.isPending}
            >
              Disconnect
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={!hasChanges || updateSiteMutation.isPending}
              onClick={handleSaveConfiguration}
            >
              Update
            </Button>
          </div>
        </div>
      </div>

      {/* Connect Repository Dialog */}
      <Dialog open={connectDialogOpen} onOpenChange={setConnectDialogOpen}>
        <DialogContent className="sm:max-w-2xl p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Connect repository</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Select a GitHub installation and repository to connect to this
              site.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0 max-h-[60vh] overflow-y-auto">
            <div className="space-y-4">
              {/* Installation Selector */}
              <div>
                <Label htmlFor="installation" className="text-[13px]">
                  Installation
                </Label>
                <Select
                  value={selectedInstallationId}
                  onValueChange={setSelectedInstallationId}
                >
                  <SelectTrigger
                    id="installation"
                    className="mt-2 h-9 text-[13px]"
                  >
                    <SelectValue placeholder="Select installation" />
                  </SelectTrigger>
                  <SelectContent>
                    {installationsData?.installations?.map((inst) => (
                      <SelectItem key={inst.$id} value={inst.$id}>
                        {inst.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Repository Search */}
              {selectedInstallationId && (
                <div>
                  <Label htmlFor="repository-search" className="text-[13px]">
                    Search repositories
                  </Label>
                  <Input
                    id="repository-search"
                    value={repositorySearch}
                    onChange={(e) => setRepositorySearch(e.target.value)}
                    placeholder="Search repositories..."
                    className="mt-2 h-9 text-[13px]"
                  />
                </div>
              )}

              {/* Repository List */}
              {selectedInstallationId && (
                <div>
                  <Label className="text-[13px]">Repository</Label>
                  {repositoriesLoading ? (
                    <div className="mt-2 flex items-center justify-center py-8">
                      <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                    </div>
                  ) : repositories.length === 0 ? (
                    <EmptyState
                      title="No repositories found"
                      description="Try a different search term or installation"
                      className="mt-2"
                    />
                  ) : (
                    <div className="mt-2 space-y-2 max-h-[300px] overflow-y-auto">
                      {repositories.map((repo) => (
                        <div
                          key={repo.$id}
                          className={cn(
                            'flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-colors',
                            selectedRepositoryId === repo.$id
                              ? 'border-primary bg-primary/5'
                              : 'border-border hover:bg-muted/30',
                          )}
                          onClick={() => setSelectedRepositoryId(repo.$id)}
                        >
                          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted">
                            <GitHubIcon className="h-4 w-4 text-muted-foreground" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className="text-[13px] font-medium text-foreground truncate">
                              {repo.organization}/{repo.name}
                            </p>
                            {repo.private && (
                              <p className="text-[11px] text-muted-foreground flex items-center gap-1">
                                <Lock className="h-3 w-3" />
                                Private
                              </p>
                            )}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
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

      {/* Disconnect Repository Dialog */}
      <Dialog
        open={disconnectDialogOpen}
        onOpenChange={setDisconnectDialogOpen}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Disconnect repository</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to disconnect the repository from this site?
              This will remove the Git integration but won't affect your
              deployments.
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
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDisconnectRepository}
              disabled={disconnectRepositoryMutation.isPending}
            >
              Disconnect
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
