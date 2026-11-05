/**
 * Root Directory Picker Component
 *
 * A reusable input with a directory tree picker dialog for selecting
 * the root directory in a Git repository.
 * Used in function settings and site creation wizards.
 */

import { useState, useEffect, useCallback } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { FolderOpen, Info, ChevronRight, Loader2 } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useT } from '@/lib/i18n/translate'
import { sdk } from '@/lib/appwrite/sdk'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { VcsInstallationErrorState } from '@/components/global/shared/VcsInstallationError'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

interface RootDirectoryPickerProps {
  projectId: string | undefined
  installationId: string | null | undefined
  providerRepositoryId: string | null | undefined
  branch: string
  value: string
  onChange: (directory: string) => void
  label?: string
  /** Optional tooltip text shown next to the label (e.g. for sites: path to site code) */
  labelTooltip?: string
  placeholder?: string
  description?: string
  disabled?: boolean
  className?: string
}

export function RootDirectoryPicker({
  projectId,
  installationId,
  providerRepositoryId,
  branch,
  value,
  onChange,
  label = 'Root directory',
  labelTooltip,
  placeholder = './',
  description = 'Choose the directory containing your code',
  disabled = false,
  className,
}: RootDirectoryPickerProps) {
  const t = useT()
  const queryClient = useQueryClient()

  const labelContent = (
    <>
      {t(label)}
      {labelTooltip && (
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              className="inline-flex ms-1.5 align-middle text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
              aria-label={t('More info')}
            >
              <Info className="h-3.5 w-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent side="top" className="max-w-[240px] z-[200]">
            {t(labelTooltip)}
          </TooltipContent>
        </Tooltip>
      )}
    </>
  )
  const [dialogOpen, setDialogOpen] = useState(false)
  const [selectedDir, setSelectedDir] = useState(value || './')
  const [expandedPaths, setExpandedPaths] = useState<Set<string>>(new Set())
  const [directoryCache, setDirectoryCache] = useState<
    Map<string, { contents: Models.VcsContent[] }>
  >(new Map())
  // Root listing failure only. A failed subdirectory preload still leaves a
  // usable tree, so it stays a console warning rather than taking over the
  // dialog.
  const [rootError, setRootError] = useState<unknown>(null)

  const hasRepository = installationId && providerRepositoryId
  const rootErrorKind = getVcsInstallationErrorKind(rootError)
  const { provider, organization, reconnectUrl } = useVcsInstallationReconnect(
    projectId,
    installationId,
  )

  // Load directory contents
  const loadDirectoryContents = useCallback(
    async (path: string): Promise<void> => {
      if (!projectId || !installationId || !providerRepositoryId) return
      if (directoryCache.has(path)) return

      try {
        const contents = await queryClient.fetchQuery({
          queryKey: [
            'vcs',
            'contents',
            projectId,
            installationId,
            providerRepositoryId,
            path,
            branch || 'main',
          ],
          queryFn: async () => {
            const projectSdk = sdk.forProject(projectId)
            // Normalize path: API expects './' for root, or path without './' prefix for nested
            let normalizedPath: string | undefined
            if (path === './') {
              normalizedPath = './'
            } else {
              normalizedPath = path.replace(/^\.\//, '')
              if (normalizedPath === '') {
                normalizedPath = undefined
              }
            }
            const response = await projectSdk.vcs.getRepositoryContents({
              installationId: installationId!,
              providerRepositoryId: providerRepositoryId!,
              providerRootDirectory: normalizedPath,
              providerReference: branch || 'main',
            })
            return response
          },
          staleTime: 5 * 60 * 1000,
        })

        setDirectoryCache((prev) => {
          const newCache = new Map(prev)
          newCache.set(path, { contents: contents.contents })

          // Preload subdirectories
          const subdirectories = contents.contents.filter(
            (item) => item.isDirectory,
          )
          subdirectories.forEach((dir) => {
            let subdirPath: string
            if (path === './') {
              subdirPath = `./${dir.name}`
            } else if (path.startsWith('./')) {
              subdirPath = `${path}/${dir.name}`
            } else {
              subdirPath = `./${path}/${dir.name}`
            }

            if (!newCache.has(subdirPath)) {
              queryClient
                .fetchQuery({
                  queryKey: [
                    'vcs',
                    'contents',
                    projectId,
                    installationId,
                    providerRepositoryId,
                    subdirPath,
                    branch || 'main',
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
                      installationId: installationId!,
                      providerRepositoryId: providerRepositoryId!,
                      providerRootDirectory: normalizedPath,
                      providerReference: branch || 'main',
                    })
                  },
                  staleTime: 5 * 60 * 1000,
                })
                .then((subdirContents) => {
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

        if (path === './') setRootError(null)
      } catch (error) {
        console.error('Failed to load directory contents:', error)
        if (path === './') setRootError(error)
      }
    },
    [
      projectId,
      installationId,
      providerRepositoryId,
      branch,
      directoryCache,
      queryClient,
    ],
  )

  const toggleDirectory = async (path: string) => {
    const isCurrentlyExpanded = expandedPaths.has(path)

    if (isCurrentlyExpanded) {
      setExpandedPaths((prev) => {
        const newSet = new Set(prev)
        newSet.delete(path)
        return newSet
      })
    } else {
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

  const handleSelect = () => {
    onChange(selectedDir)
    setDialogOpen(false)
  }

  // Load root directory contents when dialog opens
  useEffect(() => {
    if (dialogOpen && hasRepository && !directoryCache.has('./')) {
      loadDirectoryContents('./')
      setExpandedPaths(new Set(['./']))
    }
  }, [dialogOpen, hasRepository, loadDirectoryContents, directoryCache])

  // Preload first level directories after root is loaded
  useEffect(() => {
    if (dialogOpen && hasRepository) {
      const rootContents = directoryCache.get('./')
      if (rootContents) {
        const firstLevelDirs = rootContents.contents.filter(
          (item) => item.isDirectory,
        )
        firstLevelDirs.forEach((dir) => {
          const dirPath = `./${dir.name}`
          if (!directoryCache.has(dirPath)) {
            loadDirectoryContents(dirPath).catch((error) => {
              console.error(`Failed to preload directory ${dirPath}:`, error)
            })
          }
        })
      }
    }
  }, [dialogOpen, hasRepository, directoryCache, loadDirectoryContents])

  // Sync selectedDir with value prop
  useEffect(() => {
    setSelectedDir(value || './')
  }, [value])

  return (
    <div className={className}>
      {label && (
        <Label htmlFor="root-directory" className="text-[13px] mb-2 block">
          {labelContent}
        </Label>
      )}
      <div className="flex gap-2">
        <Input
          id="root-directory"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          disabled={disabled}
          className="h-9 font-mono text-[13px]"
        />
        {hasRepository && (
          <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
            <DialogTrigger asChild>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 text-[13px] shrink-0"
                disabled={disabled}
              >
                {t('Select')}
              </Button>
            </DialogTrigger>
            <DialogContent
              className="z-[10000] sm:max-w-md p-0"
              overlayClassName="z-[9999]"
            >
              <DialogHeader className="px-6 pt-6 text-start">
                <DialogTitle>{t('Select root directory')}</DialogTitle>
                <DialogDescription className="text-[13px] mt-2">
                  {t(description)}
                </DialogDescription>
              </DialogHeader>
              <div className="border-t border-border" />
              <div className="px-6 pb-4 pt-4 max-h-[400px] overflow-y-auto">
                {directoryCache.has('./') ? (
                  <DirectoryTree
                    path="./"
                    selectedPath={selectedDir}
                    onSelect={(path) => setSelectedDir(path)}
                    onToggle={toggleDirectory}
                    expandedPaths={expandedPaths}
                    directoryCache={directoryCache}
                    loadDirectoryContents={loadDirectoryContents}
                    level={0}
                  />
                ) : rootErrorKind ? (
                  <VcsInstallationErrorState
                    kind={rootErrorKind}
                    provider={provider}
                    organization={organization}
                    reconnectUrl={reconnectUrl}
                    onRetry={() => {
                      setRootError(null)
                      loadDirectoryContents('./')
                    }}
                    className="py-2"
                  />
                ) : (
                  <div className="flex items-center justify-center py-8">
                    <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                  </div>
                )}
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  type="button"
                  onClick={handleSelect}
                  disabled={!selectedDir}
                >
                  {t('Select')}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        )}
      </div>
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
  loadDirectoryContents: (path: string) => Promise<void>
  level: number
}

function DirectoryTree({
  path,
  selectedPath,
  onSelect,
  onToggle,
  expandedPaths,
  directoryCache,
  loadDirectoryContents,
  level,
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
    const target = e.target as HTMLElement
    if (target.closest('.directory-select')) {
      onSelect(path)
      return
    }
    onToggle(path)
  }

  const displayName = path === './' ? './' : path.split('/').pop() || path
  const showExpandButton = hasContents && hasSubdirectories

  return (
    <div>
      <div
        onClick={handleRowClick}
        className={cn(
          'flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-[13px] transition-colors hover:bg-accent cursor-pointer',
          selectedPath === path && 'bg-accent',
        )}
        style={{ paddingInlineStart: `${level * 16 + 8}px` }}
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
