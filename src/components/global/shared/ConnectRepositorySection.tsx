/**
 * Connect Repository Section
 *
 * Reusable Git repository section for template wizards (sites, functions).
 * Supports: Connect to GitHub empty state, Create new repository, Connect existing repository,
 * and after-selection summary with optional branch/root directory.
 */

import { useState, useEffect, useMemo } from 'react'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { VCSDetectionType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { Loader2, Plus } from 'lucide-react'
import { useCreateVcsRepository } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { RepositoryPicker } from '@/components/global/shared/RepositoryPicker'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { cn } from '@/lib/utils'

const REPO_PAGE_SIZE = 10

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

function ProviderIcon({
  provider,
  className,
}: {
  provider?: string
  className?: string
}) {
  const normalizedProvider = provider?.toLowerCase() || 'github'
  if (normalizedProvider === 'github') {
    return <GitHubIcon className={className} />
  }
  return <GitHubIcon className={className} />
}

export interface ConnectRepositoryValue {
  installationId: string | undefined
  providerRepositoryId: string | undefined
  repositoryName: string | undefined
  repositoryOwner: string | undefined
}

export interface ConnectRepositorySectionProps {
  projectId: string | null | undefined
  installations: Models.Installation[]
  getGitHubAuthUrl: string
  /** Default repository name (e.g. derived from site/function name) */
  defaultRepositoryName: string
  /** Framework for sites, Runtime for functions */
  detectionType: VCSDetectionType
  value: ConnectRepositoryValue
  onValueChange: (value: ConnectRepositoryValue) => void
  /** When true, show branch and root directory after repo is selected */
  showBranchAndRoot?: boolean
  branch?: string
  onBranchChange?: (branch: string) => void
  rootDirectory?: string
  onRootDirectoryChange?: (root: string) => void
  /** Branch label (default matches repository wizard) */
  branchLabel?: string
  /** Branch tooltip (default matches repository wizard) */
  branchLabelTooltip?: string
  /** Root directory label (default matches repository wizard) */
  rootDirectoryLabel?: string
  /** Root directory tooltip (default matches repository wizard) */
  rootDirectoryLabelTooltip?: string
  /** Root directory dialog description (default matches repository wizard) */
  rootDirectoryDescription?: string
  /** Optional label overrides for empty state */
  emptyStateTitle?: string
  emptyStateDescription?: string
  className?: string
}

export function ConnectRepositorySection({
  projectId,
  installations,
  getGitHubAuthUrl,
  defaultRepositoryName,
  detectionType,
  value,
  onValueChange,
  showBranchAndRoot = false,
  branch = 'main',
  onBranchChange,
  rootDirectory = './',
  onRootDirectoryChange,
  branchLabel = 'Branch',
  branchLabelTooltip = 'Production branch for the repo linked to the site. Successful deployments from this branch get activated automatically.',
  rootDirectoryLabel = 'Root directory',
  rootDirectoryLabelTooltip = 'Path to site code in the linked repo. Use the repository root (./) or a subdirectory that contains your app (e.g. ./apps/web).',
  rootDirectoryDescription = 'Choose the directory containing your site code',
  emptyStateTitle = 'Connect Git repository',
  emptyStateDescription = 'Create and deploy with a connected git repository.',
  className,
}: ConnectRepositorySectionProps) {
  const suggestedRepoName = useMemo(
    () =>
      defaultRepositoryName
        .split(' ')
        .join('-')
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '')
        .slice(0, 100) || 'my-repository',
    [defaultRepositoryName],
  )

  const [repositoryBehaviour, setRepositoryBehaviour] = useState<
    'new' | 'existing'
  >('new')
  const [selectedInstallationId, setSelectedInstallationId] = useState(
    value.installationId || '',
  )
  const [repositoryName, setRepositoryName] = useState(
    value.repositoryName || suggestedRepoName,
  )
  const [repositoryPrivate, setRepositoryPrivate] = useState(true)

  const createRepositoryMutation = useCreateVcsRepository(projectId)

  const hasRepository =
    !!value.installationId && !!value.providerRepositoryId
  const hasInstallations = installations.length > 0

  // Sync default repo name when parent changes it (e.g. site name)
  useEffect(() => {
    if (!hasRepository && defaultRepositoryName) {
      setRepositoryName((prev) => prev || defaultRepositoryName)
    }
  }, [defaultRepositoryName, hasRepository])

  // Initialize selected installation when installations load
  useEffect(() => {
    if (
      hasInstallations &&
      !selectedInstallationId &&
      installations[0]?.$id
    ) {
      setSelectedInstallationId(installations[0].$id)
    }
  }, [hasInstallations, installations, selectedInstallationId])

  const handleCreateRepository = async () => {
    if (
      !projectId ||
      !selectedInstallationId ||
      !repositoryName.trim() ||
      createRepositoryMutation.isPending
    )
      return
    try {
      const repo = await createRepositoryMutation.mutateAsync({
        installationId: selectedInstallationId,
        name: repositoryName.trim(),
        xprivate: repositoryPrivate,
      })
      onValueChange({
        installationId: selectedInstallationId,
        providerRepositoryId: repo.id,
        repositoryName: repo.name,
        repositoryOwner: repo.organization,
      })
    } catch (error: any) {
      toast.error(error?.message ?? 'Failed to create repository')
    }
  }

  const handleConnectExisting = (repo: Models.ProviderRepositoryFramework) => {
    onValueChange({
      installationId: selectedInstallationId,
      providerRepositoryId: repo.id,
      repositoryName: repo.name,
      repositoryOwner: repo.organization,
    })
  }

  const handleClearRepository = () => {
    onValueChange({
      installationId: undefined,
      providerRepositoryId: undefined,
      repositoryName: undefined,
      repositoryOwner: undefined,
    })
    setRepositoryName(suggestedRepoName)
    setRepositoryBehaviour('new')
  }

  // No installations: show Connect to GitHub only
  if (!hasInstallations) {
    return (
      <div
        className={cn(
          'rounded-xl border border-border bg-card/50 overflow-hidden',
          className,
        )}
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {emptyStateTitle}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {emptyStateDescription}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-6 flex flex-col items-center">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-muted mb-4">
            <GitHubIcon className="h-6 w-6 text-muted-foreground" />
          </div>
          <Button asChild>
            <a href={getGitHubAuthUrl}>
              <GitHubIcon className="mr-1.5 h-4 w-4" />
              Connect to GitHub
            </a>
          </Button>
        </div>
      </div>
    )
  }

  // Repository already selected: show summary + Update, optionally branch/root
  if (hasRepository) {
    return (
      <div
        className={cn(
          'rounded-xl border border-border bg-card/50 overflow-hidden space-y-0',
          className,
        )}
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Git repository
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-3 min-w-0">
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                <GitHubIcon className="h-5 w-5" />
              </div>
              <div className="min-w-0">
                <p className="text-[13px] font-medium text-foreground truncate">
                  {value.repositoryOwner}/{value.repositoryName}
                </p>
                <p className="text-[11px] text-muted-foreground">
                  GitHub repository
                </p>
              </div>
            </div>
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px] shrink-0"
              onClick={handleClearRepository}
            >
              Update
            </Button>
          </div>
        </div>
        {showBranchAndRoot && (
          <>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-4">
              <BranchSelector
                projectId={projectId}
                installationId={value.installationId}
                providerRepositoryId={value.providerRepositoryId}
                value={branch}
                onChange={onBranchChange || (() => {})}
                label={branchLabel}
                labelTooltip={branchLabelTooltip}
                placeholder="Select branch"
              />
              <RootDirectoryPicker
                projectId={projectId}
                installationId={value.installationId}
                providerRepositoryId={value.providerRepositoryId}
                branch={branch || 'main'}
                value={rootDirectory}
                onChange={onRootDirectoryChange || (() => {})}
                label={rootDirectoryLabel}
                labelTooltip={rootDirectoryLabelTooltip}
                description={rootDirectoryDescription}
                placeholder="./"
              />
            </div>
          </>
        )}
      </div>
    )
  }

  // Has installations, no repo selected: Create new | Connect existing
  return (
    <div
      className={cn(
        'rounded-xl border border-border bg-card/50 overflow-hidden',
        className,
      )}
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Git repository
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <RadioGroup
          value={repositoryBehaviour}
          onValueChange={(v) => setRepositoryBehaviour(v as 'new' | 'existing')}
          className="flex gap-4 mb-6"
        >
          <Label
            htmlFor="repo-new"
            className={cn(
              'flex flex-1 items-start gap-3 rounded-xl border p-4 cursor-pointer transition-all',
              repositoryBehaviour === 'new'
                ? 'border-foreground bg-card/80'
                : 'border-border bg-card/50 hover:border-border/80',
            )}
          >
            <RadioGroupItem value="new" id="repo-new" className="mt-1 shrink-0" />
            <div>
              <span className="text-[14px] font-medium text-foreground">
                Create new repository
              </span>
              <p className="text-[12px] text-muted-foreground mt-1">
                Create a new Git repository and clone the template into it.
              </p>
            </div>
          </Label>
          <Label
            htmlFor="repo-existing"
            className={cn(
              'flex flex-1 items-start gap-3 rounded-xl border p-4 cursor-pointer transition-all',
              repositoryBehaviour === 'existing'
                ? 'border-foreground bg-card/80'
                : 'border-border bg-card/50 hover:border-border/80',
            )}
          >
            <RadioGroupItem
              value="existing"
              id="repo-existing"
              className="mt-1 shrink-0"
            />
            <div>
              <span className="text-[14px] font-medium text-foreground">
                Connect existing repository
              </span>
              <p className="text-[12px] text-muted-foreground mt-1">
                Link this deployment to an existing repository.
              </p>
            </div>
          </Label>
        </RadioGroup>

        {repositoryBehaviour === 'new' && (
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="git-org" className="text-[13px]">
                Git organization
              </Label>
              <Select
                value={selectedInstallationId}
                onValueChange={setSelectedInstallationId}
              >
                <SelectTrigger id="git-org" className="h-9 text-[13px]">
                  <SelectValue placeholder="Select organization" />
                </SelectTrigger>
                <SelectContent>
                  {installations.map((inst) => (
                    <SelectItem key={inst.$id} value={inst.$id}>
                      <span className="flex items-center gap-2">
                        <ProviderIcon
                          provider={inst.provider}
                          className="h-4 w-4 shrink-0"
                        />
                        <span>{inst.organization}</span>
                      </span>
                    </SelectItem>
                  ))}
                  <div className="border-t border-border mt-1 pt-1">
                    <a
                      href={getGitHubAuthUrl}
                      className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                    >
                      <Plus className="h-3 w-3" />
                      Add installation
                    </a>
                  </div>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="repo-name" className="text-[13px]">
                Repository name
              </Label>
              <Input
                id="repo-name"
                value={repositoryName}
                onChange={(e) => setRepositoryName(e.target.value)}
                placeholder="my-repository"
                className="h-9 text-[13px]"
              />
            </div>
            <div className="flex items-center gap-2">
              <Checkbox
                id="repo-private"
                checked={repositoryPrivate}
                onCheckedChange={(v) => setRepositoryPrivate(v === true)}
              />
              <Label
                htmlFor="repo-private"
                className="text-[13px] font-normal cursor-pointer"
              >
                Keep repository private
              </Label>
            </div>
            <Button
              onClick={handleCreateRepository}
              disabled={
                !repositoryName.trim() ||
                !selectedInstallationId ||
                createRepositoryMutation.isPending
              }
              className="h-9 text-[13px]"
            >
              {createRepositoryMutation.isPending ? (
                <>
                  <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                  Creating…
                </>
              ) : (
                'Create'
              )}
            </Button>
          </div>
        )}

        {repositoryBehaviour === 'existing' && (
          <RepositoryPicker
            projectId={projectId}
            getGitHubAuthUrl={getGitHubAuthUrl}
            installations={installations}
            selectedInstallationId={selectedInstallationId}
            onInstallationChange={setSelectedInstallationId}
            onRepositorySelect={handleConnectExisting as (repo: Models.ProviderRepositoryFramework) => void}
            mode="create"
            detectionType={detectionType === VCSDetectionType.Runtime ? 'runtime' : 'framework'}
            className="mt-0"
          />
        )}
      </div>
    </div>
  )
}
