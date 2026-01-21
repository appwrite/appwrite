import { useState, useEffect, useMemo, useCallback } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
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
import { VCSDetectionType } from '@appwrite.io/console'
import {
  useRepository,
  useRepositoryBranches,
  useVcsInstallations,
  useRepositories,
  useRepositoryContents,
} from '@/lib/react-query/hooks'
import {
  GitBranch,
  Lock,
  ExternalLink,
  FolderOpen,
  ChevronRight,
  Loader2,
  X,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'

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
  const [rootDirectoryDialogOpen, setRootDirectoryDialogOpen] = useState(false)
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
  const [repositorySearch, setRepositorySearch] = useState('')
  const [repositoryPage, setRepositoryPage] = useState(0)
  const [debouncedSearch, setDebouncedSearch] = useState('')

  // Root directory picker state
  const [expandedPaths, setExpandedPaths] = useState<Set<string>>(new Set())
  const [directoryCache, setDirectoryCache] = useState<
    Map<string, { contents: Models.VcsContent[]; runtime?: string }>
  >(new Map())

  // Fetch repository details if connected
  const hasRepository = func.installationId && func.providerRepositoryId

  const { data: repository, isLoading: repositoryLoading } = useRepository(
    projectId,
    func.installationId || null,
    func.providerRepositoryId || null,
  )

  const { data: branchesData, isLoading: branchesLoading } =
    useRepositoryBranches(
      projectId,
      func.installationId || null,
      func.providerRepositoryId || null,
    )

  // Fetch installations for connect modal
  const { data: installationsData } = useVcsInstallations(projectId)

  // Fetch repositories for selected installation
  const { data: repositoriesData, isLoading: repositoriesLoading } =
    useRepositories(
      projectId,
      selectedInstallationId || null,
      VCSDetectionType.Runtime,
      repositoryPage,
      5,
      debouncedSearch || undefined,
    )

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(repositorySearch)
      setRepositoryPage(0) // Reset to first page on search
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

  // Sort branches: main/master first, then alphabetically
  const sortedBranches = useMemo(() => {
    if (!branchesData?.branches) return []
    const branches = [...branchesData.branches]
    branches.sort((a, b) => {
      if (a.name === 'main' || a.name === 'master') return -1
      if (b.name === 'main' || b.name === 'master') return 1
      return a.name.localeCompare(b.name)
    })
    return branches
  }, [branchesData])

  // Set default branch when branches load
  useEffect(() => {
    if (sortedBranches.length > 0 && !selectedBranch) {
      setSelectedBranch(func.providerBranch || sortedBranches[0].name)
    }
  }, [sortedBranches, func.providerBranch, selectedBranch])

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
    onError: (error: any) => {
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
      setRepositorySearch('')
      setRepositoryPage(0)
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, func.$id],
      })
    },
    onError: (error: any) => {
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
    onError: (error: any) => {
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

  // Root directory picker functions
  const loadDirectoryContents = useCallback(
    async (path: string): Promise<void> => {
      if (!projectId || !func.installationId || !func.providerRepositoryId)
        return
      if (directoryCache.has(path)) return

      try {
        const contents = await queryClient.fetchQuery({
          queryKey: [
            'vcs',
            'contents',
            projectId,
            func.installationId,
            func.providerRepositoryId,
            path,
            selectedBranch || 'main',
          ],
          queryFn: async () => {
            const projectSdk = sdk.forProject(projectId)
            // Normalize path: API expects './' for root, or path without './' prefix for nested
            let normalizedPath: string | undefined
            if (path === './') {
              normalizedPath = './'
            } else {
              // Remove leading ./ for nested directories
              normalizedPath = path.replace(/^\.\//, '')
              // If empty after removing ./, use undefined
              if (normalizedPath === '') {
                normalizedPath = undefined
              }
            }
            const response = await projectSdk.vcs.getRepositoryContents({
              installationId: func.installationId!,
              providerRepositoryId: func.providerRepositoryId!,
              providerRootDirectory: normalizedPath,
              providerReference: selectedBranch || 'main',
            })
            return response
          },
          staleTime: 5 * 60 * 1000, // Cache for 5 minutes
        })

        setDirectoryCache((prev) => {
          const newCache = new Map(prev)
          newCache.set(path, { contents: contents.contents })

          // Preload all subdirectories found in this directory
          const subdirectories = contents.contents.filter(
            (item) => item.isDirectory,
          )
          subdirectories.forEach((dir) => {
            // Construct path for subdirectory
            let subdirPath: string
            if (path === './') {
              subdirPath = `./${dir.name}`
            } else if (path.startsWith('./')) {
              subdirPath = `${path}/${dir.name}`
            } else {
              subdirPath = `./${path}/${dir.name}`
            }

            // Preload subdirectory contents if not already cached
            if (!newCache.has(subdirPath)) {
              // Load asynchronously without blocking
              queryClient
                .fetchQuery({
                  queryKey: [
                    'vcs',
                    'contents',
                    projectId,
                    func.installationId,
                    func.providerRepositoryId,
                    subdirPath,
                    selectedBranch || 'main',
                  ],
                  queryFn: async () => {
                    const projectSdk = sdk.forProject(projectId)
                    let normalizedPath: string | undefined
                    if (subdirPath === './') {
                      normalizedPath = './'
                    } else {
                      normalizedPath = subdirPath.replace(/^\.\//, '')
                      if (normalizedPath === '') {
                        normalizedPath = undefined
                      }
                    }
                    return await projectSdk.vcs.getRepositoryContents({
                      installationId: func.installationId!,
                      providerRepositoryId: func.providerRepositoryId!,
                      providerRootDirectory: normalizedPath,
                      providerReference: selectedBranch || 'main',
                    })
                  },
                  staleTime: 5 * 60 * 1000,
                })
                .then((subdirContents) => {
                  // Update cache with preloaded subdirectory contents
                  setDirectoryCache((currentCache) => {
                    const updatedCache = new Map(currentCache)
                    updatedCache.set(subdirPath, {
                      contents: subdirContents.contents,
                    })
                    return updatedCache
                  })
                })
                .catch((error) => {
                  console.error(
                    `Failed to preload subdirectory ${subdirPath}:`,
                    error,
                  )
                })
            }
          })

          return newCache
        })
      } catch (error) {
        console.error('Failed to load directory contents:', error)
      }
    },
    [
      projectId,
      func.installationId,
      func.providerRepositoryId,
      selectedBranch,
      directoryCache,
      queryClient,
    ],
  )

  const toggleDirectory = async (path: string) => {
    const isCurrentlyExpanded = expandedPaths.has(path)

    if (isCurrentlyExpanded) {
      // Collapse: remove from expanded paths
      setExpandedPaths((prev) => {
        const newSet = new Set(prev)
        newSet.delete(path)
        return newSet
      })
    } else {
      // Expand: load contents if not cached, then add to expanded paths
      if (!directoryCache.has(path)) {
        await loadDirectoryContents(path)
      }
      setExpandedPaths((prev) => {
        const newSet = new Set(prev)
        newSet.add(path)
        return newSet
      })
    }
  }

  const selectDirectory = (path: string) => {
    setSelectedDir(path)
    setRootDirectoryDialogOpen(false)
  }

  // Load root directory contents when dialog opens
  useEffect(() => {
    if (rootDirectoryDialogOpen && hasRepository && !directoryCache.has('./')) {
      loadDirectoryContents('./')
      // Auto-expand root to show first level
      setExpandedPaths(new Set(['./']))
    }
  }, [rootDirectoryDialogOpen, hasRepository, loadDirectoryContents])

  // Preload first level directories after root is loaded
  useEffect(() => {
    if (rootDirectoryDialogOpen && hasRepository) {
      const rootContents = directoryCache.get('./')
      if (rootContents) {
        const firstLevelDirs = rootContents.contents.filter(
          (item) => item.isDirectory,
        )
        // Preload all first-level directories in parallel
        firstLevelDirs.forEach((dir) => {
          const dirPath = `./${dir.name}`
          if (!directoryCache.has(dirPath)) {
            // Load asynchronously without blocking
            loadDirectoryContents(dirPath).catch((error) => {
              console.error(`Failed to preload directory ${dirPath}:`, error)
            })
          }
        })
      }
    }
  }, [
    rootDirectoryDialogOpen,
    hasRepository,
    directoryCache,
    loadDirectoryContents,
  ])

  const repositories = useMemo(() => {
    return repositoriesData?.runtimeProviderRepositories || []
  }, [repositoriesData])

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
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted ring-1 ring-border">
              <GitBranch className="h-6 w-6 text-muted-foreground" />
            </div>
            <p className="mb-1 text-[14px] font-medium text-foreground">
              No repository connected
            </p>
            <p className="mb-4 text-[13px] text-muted-foreground">
              Connect a repository to enable automatic deployments
            </p>
            <Dialog
              open={connectDialogOpen}
              onOpenChange={setConnectDialogOpen}
            >
              <DialogTrigger asChild>
                <Button size="sm" className="h-9 text-[13px]">
                  Connect repository
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-2xl p-0">
                <DialogHeader className="px-6 pt-6 text-left">
                  <DialogTitle>Connect Repository</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    Select a Git installation and repository to connect to this
                    function
                  </DialogDescription>
                </DialogHeader>
                <div className="border-t border-border" />
                <div className="px-6 pb-4 pt-0 max-h-[500px] overflow-y-auto">
                  <div className="space-y-4">
                    {/* Installation selector */}
                    <div>
                      <Label htmlFor="installation" className="text-[13px]">
                        Installation
                      </Label>
                      {installationsData?.installations &&
                      installationsData.installations.length > 0 ? (
                        <Select
                          value={selectedInstallationId}
                          onValueChange={setSelectedInstallationId}
                        >
                          <SelectTrigger
                            id="installation"
                            className="mt-2 h-9 border-border bg-background text-[13px]"
                          >
                            <SelectValue placeholder="Select installation" />
                          </SelectTrigger>
                          <SelectContent>
                            {installationsData.installations.map(
                              (installation) => (
                                <SelectItem
                                  key={installation.$id}
                                  value={installation.$id}
                                >
                                  {installation.organization}
                                </SelectItem>
                              ),
                            )}
                          </SelectContent>
                        </Select>
                      ) : (
                        <p className="mt-2 text-[13px] text-muted-foreground">
                          No installations available. Please add an installation
                          in project settings.
                        </p>
                      )}
                    </div>

                    {/* Repository search and list */}
                    {selectedInstallationId && (
                      <div>
                        <Label htmlFor="repository" className="text-[13px]">
                          Repository
                        </Label>
                        <Input
                          id="repository"
                          value={repositorySearch}
                          onChange={(e) => setRepositorySearch(e.target.value)}
                          placeholder="Search repositories..."
                          className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        />
                        {repositoriesLoading ? (
                          <div className="mt-4 flex items-center justify-center py-8">
                            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                          </div>
                        ) : repositories.length > 0 ? (
                          <div className="mt-4 space-y-2 max-h-[300px] overflow-y-auto">
                            {repositories.map((repo) => (
                              <button
                                key={repo.id}
                                onClick={() => setSelectedRepositoryId(repo.id)}
                                className={cn(
                                  'flex w-full items-center gap-3 rounded-md border border-border bg-background px-3 py-2 text-left transition-colors hover:bg-accent',
                                  selectedRepositoryId === repo.id &&
                                    'bg-accent',
                                )}
                              >
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded bg-muted">
                                  {repo.runtime ? (
                                    <RuntimeIcon
                                      runtime={repo.runtime}
                                      className="h-4 w-4"
                                    />
                                  ) : (
                                    <GitHubIcon className="h-4 w-4 text-muted-foreground" />
                                  )}
                                </div>
                                <div className="flex-1 min-w-0">
                                  <div className="flex items-center gap-2">
                                    <p className="truncate text-[13px] font-medium text-foreground">
                                      {repo.organization}/{repo.name}
                                    </p>
                                    {repo.private && (
                                      <Lock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                    )}
                                  </div>
                                  {repo.pushedAt && (
                                    <p className="text-[12px] text-muted-foreground">
                                      Updated{' '}
                                      <DateTooltip date={repo.pushedAt} />
                                    </p>
                                  )}
                                </div>
                                {selectedRepositoryId === repo.id && (
                                  <div className="h-2 w-2 shrink-0 rounded-full bg-primary" />
                                )}
                              </button>
                            ))}
                          </div>
                        ) : (
                          <p className="mt-4 text-[13px] text-muted-foreground">
                            No repositories found
                          </p>
                        )}
                      </div>
                    )}
                  </div>
                </div>
                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    onClick={() => setConnectDialogOpen(false)}
                    disabled={connectRepositoryMutation.isPending}
                  >
                    Cancel
                  </Button>
                  <Button
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
              <div>
                <Label htmlFor="branch" className="text-[13px]">
                  Production Branch
                </Label>
                {branchesLoading ? (
                  <div className="mt-2 flex items-center gap-2">
                    <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                    <p className="text-[13px] text-muted-foreground">
                      Loading branches...
                    </p>
                  </div>
                ) : sortedBranches.length > 0 ? (
                  <Select
                    value={selectedBranch}
                    onValueChange={setSelectedBranch}
                  >
                    <SelectTrigger
                      id="branch"
                      className="mt-2 h-9 border-border bg-background text-[13px]"
                    >
                      <SelectValue placeholder="Select branch" />
                    </SelectTrigger>
                    <SelectContent>
                      {sortedBranches.map((branch) => (
                        <SelectItem key={branch.name} value={branch.name}>
                          <div className="flex items-center gap-2">
                            <GitBranch className="h-3.5 w-3.5 text-muted-foreground" />
                            {branch.name}
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                ) : (
                  <Input
                    id="branch"
                    value={selectedBranch}
                    onChange={(e) => setSelectedBranch(e.target.value)}
                    placeholder="main"
                    className="mt-2 h-9 font-mono border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                )}
              </div>

              {/* Root directory selector */}
              <div>
                <Label htmlFor="rootDirectory" className="text-[13px]">
                  Root Directory
                </Label>
                <div className="mt-2 flex gap-2">
                  <Input
                    id="rootDirectory"
                    value={selectedDir}
                    onChange={(e) => setSelectedDir(e.target.value)}
                    placeholder="./"
                    className="h-9 font-mono border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                  <Dialog
                    open={rootDirectoryDialogOpen}
                    onOpenChange={setRootDirectoryDialogOpen}
                  >
                    <DialogTrigger asChild>
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-9 text-[13px]"
                      >
                        Select
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 text-left">
                        <DialogTitle>Select Root Directory</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          Choose the directory containing your function code
                        </DialogDescription>
                      </DialogHeader>
                      <div className="border-t border-border" />
                      <div className="px-6 pb-4 pt-0 max-h-[400px] overflow-y-auto">
                        {directoryCache.has('./') ? (
                          <DirectoryTree
                            path="./"
                            selectedPath={selectedDir}
                            onSelect={(path) => setSelectedDir(path)}
                            onToggle={toggleDirectory}
                            expandedPaths={expandedPaths}
                            directoryCache={directoryCache}
                            projectId={projectId!}
                            installationId={func.installationId!}
                            providerRepositoryId={func.providerRepositoryId!}
                            branch={selectedBranch || 'main'}
                            loadDirectoryContents={loadDirectoryContents}
                          />
                        ) : (
                          <div className="flex items-center justify-center py-8">
                            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                          </div>
                        )}
                      </div>
                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          onClick={() => setRootDirectoryDialogOpen(false)}
                        >
                          Cancel
                        </Button>
                        <Button
                          onClick={() => selectDirectory(selectedDir)}
                          disabled={!selectedDir}
                        >
                          Select
                        </Button>
                      </div>
                    </DialogContent>
                  </Dialog>
                </div>
              </div>

              {/* Silent mode toggle */}
              <div className="flex items-center justify-between">
                <div>
                  <Label htmlFor="silentMode" className="text-[13px]">
                    Silent Mode
                  </Label>
                  <p className="text-[12px] text-muted-foreground">
                    Skip build logs in deployment output
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

// Directory Tree Component
interface DirectoryTreeProps {
  path: string
  selectedPath: string
  onSelect: (path: string) => void
  onToggle: (path: string) => void
  expandedPaths: Set<string>
  directoryCache: Map<string, { contents: Models.VcsContent[] }>
  projectId: string
  installationId: string
  providerRepositoryId: string
  branch: string
  loadDirectoryContents: (path: string) => Promise<void>
  level?: number
}

function DirectoryTree({
  path,
  selectedPath,
  onSelect,
  onToggle,
  expandedPaths,
  directoryCache,
  projectId,
  installationId,
  providerRepositoryId,
  branch,
  loadDirectoryContents,
  level = 0,
}: DirectoryTreeProps) {
  const isExpanded = expandedPaths.has(path)
  const contents = directoryCache.get(path)
  const directories =
    contents?.contents.filter((item) => item.isDirectory) || []
  const hasSubdirectories = directories.length > 0
  const hasContents = !!contents

  // Load contents when expanded if not already loaded
  useEffect(() => {
    if (isExpanded && !hasContents) {
      loadDirectoryContents(path)
    }
  }, [isExpanded, hasContents, path, loadDirectoryContents])

  const handleRowClick = (e: React.MouseEvent) => {
    // Only toggle if clicking on the row itself, not on the folder icon/name (which selects)
    const target = e.target as HTMLElement
    if (target.closest('.directory-select')) {
      // Clicking folder icon or name selects the path
      onSelect(path)
      return
    }
    // Clicking elsewhere on the row toggles expand/collapse
    onToggle(path)
  }

  // Get directory name for display (last part of path)
  const displayName = path === './' ? './' : path.split('/').pop() || path

  // Only show expand arrow if we have loaded contents and know there are subdirectories
  const showExpandButton = hasContents && hasSubdirectories

  return (
    <div>
      <div
        onClick={handleRowClick}
        className={cn(
          'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] transition-colors hover:bg-accent cursor-pointer',
          selectedPath === path && 'bg-accent',
        )}
        style={{ paddingLeft: `${level * 16 + 8}px` }}
      >
        {showExpandButton ? (
          <ChevronRight
            className={cn(
              'h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform',
              isExpanded && 'rotate-90',
            )}
          />
        ) : (
          <div className="w-3.5 shrink-0" />
        )}
        <div
          className="directory-select flex items-center gap-2 flex-1 cursor-pointer"
          onClick={(e) => {
            e.stopPropagation()
            onSelect(path)
          }}
        >
          <FolderOpen className="h-4 w-4 shrink-0 text-muted-foreground" />
          <span className="font-mono truncate">{displayName}</span>
        </div>
      </div>
      {isExpanded && hasContents && hasSubdirectories && (
        <div>
          {directories.map((dir) => {
            // Construct path correctly for nested directories
            let dirPath: string
            if (path === './') {
              dirPath = `./${dir.name}`
            } else if (path.startsWith('./')) {
              dirPath = `${path}/${dir.name}`
            } else {
              dirPath = `./${path}/${dir.name}`
            }
            return (
              <DirectoryTree
                key={dirPath}
                path={dirPath}
                selectedPath={selectedPath}
                onSelect={onSelect}
                onToggle={onToggle}
                expandedPaths={expandedPaths}
                directoryCache={directoryCache}
                projectId={projectId}
                installationId={installationId}
                providerRepositoryId={providerRepositoryId}
                branch={branch}
                loadDirectoryContents={loadDirectoryContents}
                level={level + 1}
              />
            )
          })}
        </div>
      )}
    </div>
  )
}
