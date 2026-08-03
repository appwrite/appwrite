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
import {
  useCreateVcsRepository,
  useNamespacesForInstallations,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { RepositoryPicker } from '@/components/global/shared/RepositoryPicker'
import { BranchSelector } from '@/components/global/shared/BranchSelector'
import { RootDirectoryPicker } from '@/components/global/shared/RootDirectoryPicker'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { GitBranch } from 'lucide-react'
import {
  buildVcsOrgOptions,
  getVcsProvider,
  VCS_PROVIDERS,
  VcsIcon,
  type VcsProviderId,
} from '@/lib/vcs/providers'

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
  /** Build the OAuth authorize URL for a specific provider/mode. Falls back to getGitHubAuthUrl (create-mode GitHub) when omitted. */
  getVcsAuthUrl?: (
    provider?: VcsProviderId,
    mode?: 'create' | 'update',
  ) => string
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
  getVcsAuthUrl,
  defaultRepositoryName,
  detectionType,
  value,
  onValueChange,
  showBranchAndRoot = false,
  branch = '',
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
  const t = useT()
  const vcsAuthUrl = (
    provider?: VcsProviderId,
    mode: 'create' | 'update' = 'create',
  ) => (getVcsAuthUrl ? getVcsAuthUrl(provider, mode) : getGitHubAuthUrl)
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
  const [selectedNamespace, setSelectedNamespace] = useState<{
    providerNamespace?: string
    providerNamespaceId?: string
  }>({})
  const [repositoryName, setRepositoryName] = useState(
    value.repositoryName || suggestedRepoName,
  )
  const [repositoryPrivate, setRepositoryPrivate] = useState(true)

  const { namespacesByInstallation } = useNamespacesForInstallations(
    projectId,
    installations,
  )
  const orgOptions = useMemo(
    () => buildVcsOrgOptions(installations, namespacesByInstallation),
    [installations, namespacesByInstallation],
  )
  const selectedOrgKey = selectedNamespace.providerNamespace
    ? `${selectedInstallationId}:${selectedNamespace.providerNamespace}`
    : selectedInstallationId
  const selectOrgOption = (key: string) => {
    const option = orgOptions.find((o) => o.key === key)
    if (!option) return
    setSelectedInstallationId(option.installationId)
    setSelectedNamespace({
      providerNamespace: option.providerNamespace,
      providerNamespaceId: option.providerNamespaceId,
    })
  }

  // Keep the selection valid: defaults to the first option whenever the
  // current installation/namespace pair no longer matches one (initial
  // load, or the installations/namespaces list changing underneath it).
  useEffect(() => {
    if (!orgOptions.length) return
    const stillValid = orgOptions.some((o) => o.key === selectedOrgKey)
    if (!stillValid) {
      const first = orgOptions[0]
      setSelectedInstallationId(first.installationId)
      setSelectedNamespace({
        providerNamespace: first.providerNamespace,
        providerNamespaceId: first.providerNamespaceId,
      })
    }
  }, [orgOptions, selectedOrgKey])

  const connectedInstallation = installations.find(
    (installation) => installation.$id === value.installationId,
  )
  const {
    Icon: ConnectedRepositoryIcon,
    label: connectedRepositoryProviderLabel,
  } = getVcsProvider(connectedInstallation?.provider)

  const createRepositoryMutation = useCreateVcsRepository(projectId)

  const hasRepository = !!value.installationId && !!value.providerRepositoryId
  const hasInstallations = installations.length > 0

  // Sync default repo name when parent changes it (e.g. site name)
  useEffect(() => {
    if (!hasRepository && defaultRepositoryName) {
      setRepositoryName((prev) => prev || defaultRepositoryName)
    }
  }, [defaultRepositoryName, hasRepository])

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
        providerNamespace: selectedNamespace.providerNamespaceId,
      })
      onValueChange({
        installationId: selectedInstallationId,
        providerRepositoryId: repo.id,
        repositoryName: repo.name,
        repositoryOwner: repo.organization,
      })
    } catch (error: unknown) {
      toast.error(error?.message ?? t('Failed to create repository'))
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
    setRepositoryBehaviour('existing')
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
            {t(emptyStateTitle)}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(emptyStateDescription)}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-6 flex flex-col items-center">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-muted mb-4">
            <GitBranch className="h-6 w-6 text-muted-foreground" />
          </div>
          <div className="flex flex-wrap items-center justify-center gap-2">
            <Button variant="secondary" asChild>
              <a href={getGitHubAuthUrl}>
                <VcsIcon type="github" className="me-1.5 h-4 w-4" />
                {t('Connect to GitHub')}
              </a>
            </Button>
            <Button variant="secondary" asChild>
              <a href={vcsAuthUrl('gitlab')}>
                <VcsIcon type="gitlab" className="me-1.5 h-4 w-4" />
                {t('Connect to GitLab')}
              </a>
            </Button>
          </div>
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
            {t('Git repository')}
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-3 min-w-0">
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                <ConnectedRepositoryIcon className="h-5 w-5" />
              </div>
              <div className="min-w-0">
                <p className="text-[13px] font-medium text-foreground truncate">
                  {value.repositoryOwner}/{value.repositoryName}
                </p>
                <p className="text-[11px] text-muted-foreground">
                  {t(`${connectedRepositoryProviderLabel} repository`)}
                </p>
              </div>
            </div>
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px] shrink-0"
              onClick={handleClearRepository}
            >
              {t('Update')}
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
          {t('Git repository')}
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
            <RadioGroupItem
              value="new"
              id="repo-new"
              className="mt-1 shrink-0"
            />
            <div>
              <span className="text-[14px] font-medium text-foreground">
                {t('Create new repository')}
              </span>
              <p className="text-[12px] text-muted-foreground mt-1">
                {t(
                  'Create a new Git repository and clone the template into it.',
                )}
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
                {t('Connect existing repository')}
              </span>
              <p className="text-[12px] text-muted-foreground mt-1">
                {t('Link this deployment to an existing repository.')}
              </p>
            </div>
          </Label>
        </RadioGroup>

        {repositoryBehaviour === 'new' && (
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="git-org" className="text-[13px]">
                {t('Git organization')}
              </Label>
              <Select value={selectedOrgKey} onValueChange={selectOrgOption}>
                <SelectTrigger id="git-org" className="h-9 text-[13px]">
                  <SelectValue placeholder={t('Select organization')} />
                </SelectTrigger>
                <SelectContent>
                  {orgOptions.map((org) => (
                    <SelectItem key={org.key} value={org.key}>
                      <span className="flex items-center gap-2">
                        <VcsIcon
                          type={org.provider}
                          className="h-4 w-4 shrink-0"
                        />
                        <span>{org.label}</span>
                      </span>
                    </SelectItem>
                  ))}
                  <div className="border-t border-border mt-1 pt-1">
                    {Object.values(VCS_PROVIDERS).map((p) => (
                      <a
                        key={p.id}
                        href={vcsAuthUrl(p.id)}
                        className="flex items-center gap-2 px-2 py-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                      >
                        <p.Icon className="h-3 w-3" />
                        {t(`Add ${p.label} account`)}
                      </a>
                    ))}
                  </div>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="repo-name" className="text-[13px]">
                {t('Repository name')}
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
                {t('Keep repository private')}
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
              {t('Create')}
            </Button>
          </div>
        )}

        {repositoryBehaviour === 'existing' && (
          <RepositoryPicker
            projectId={projectId}
            getGitHubAuthUrl={getGitHubAuthUrl}
            getVcsAuthUrl={getVcsAuthUrl}
            installations={installations}
            selectedInstallationId={selectedInstallationId}
            onInstallationChange={setSelectedInstallationId}
            onRepositorySelect={
              handleConnectExisting as (
                repo: Models.ProviderRepositoryFramework,
              ) => void
            }
            mode="create"
            detectionType={
              detectionType === VCSDetectionType.Runtime
                ? 'runtime'
                : 'framework'
            }
            className="mt-0"
          />
        )}
      </div>
    </div>
  )
}
