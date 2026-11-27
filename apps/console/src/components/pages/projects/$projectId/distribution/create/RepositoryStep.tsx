import { useMemo } from 'react'
import { GitBranch, Upload } from 'lucide-react'
import { VCSDetectionType } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import {
  ConnectRepositorySection,
  type ConnectRepositoryValue,
} from '@/components/global/shared/ConnectRepositorySection'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useT } from '@/lib/i18n/translate'
import { buildVcsAuthUrl, type VcsProviderId } from '@/lib/vcs/providers'
import { ChoiceCard } from './ChoiceCard'
import type { AppSource } from './steps'
import { StepSection } from './StepSection'

export function RepositoryStep({
  projectId,
  region,
  name,
  installations,
  source,
  onSourceChange,
  repository,
  onRepositoryChange,
  branch,
  onBranchChange,
  rootDirectory,
  onRootDirectoryChange,
  disabled,
}: {
  projectId: string
  region: string | undefined
  name: string
  installations: Models.Installation[]
  source: AppSource
  onSourceChange: (source: AppSource) => void
  repository: ConnectRepositoryValue
  onRepositoryChange: (repository: ConnectRepositoryValue) => void
  branch: string
  onBranchChange: (branch: string) => void
  rootDirectory: string
  onRootDirectoryChange: (rootDirectory: string) => void
  disabled: boolean
}) {
  const t = useT()

  const getVcsAuthUrl = useMemo(
    () =>
      (provider: VcsProviderId = 'github') => {
        if (typeof window === 'undefined') return '#'
        const redirectUrl = `${window.location.origin}/projects/${projectId}/distribution/create`
        return buildVcsAuthUrl({
          endpoint: getApiEndpoint(region),
          provider,
          projectId,
          successUrl: redirectUrl,
          failureUrl: redirectUrl,
        })
      },
    [projectId, region],
  )

  return (
    <StepSection
      title={t('Connect a repository')}
      description={t(
        'Pushes to the repository start builds. You can also upload builds yourself and connect a repository later.',
      )}
    >
      <div
        role="radiogroup"
        aria-label={t('Source')}
        className="grid grid-cols-1 gap-3 sm:grid-cols-2"
      >
        <ChoiceCard
          selected={source === 'repository'}
          icon={<GitBranch className="h-4 w-4" />}
          title={t('Connect a Git repository')}
          description={t('Build from a branch on every push or tag.')}
          disabled={disabled}
          onSelect={() => onSourceChange('repository')}
        />
        <ChoiceCard
          selected={source === 'upload'}
          icon={<Upload className="h-4 w-4" />}
          title={t('Upload builds later')}
          description={t(
            'Upload signed builds yourself. A repository can be connected in settings.',
          )}
          disabled={disabled}
          onSelect={() => onSourceChange('upload')}
        />
      </div>

      {source === 'repository' && (
        <ConnectRepositorySection
          projectId={projectId}
          installations={installations}
          getGitHubAuthUrl={getVcsAuthUrl('github')}
          getVcsAuthUrl={getVcsAuthUrl}
          defaultRepositoryName={name}
          detectionType={VCSDetectionType.Framework}
          value={repository}
          onValueChange={onRepositoryChange}
          showBranchAndRoot={
            !!repository.installationId && !!repository.providerRepositoryId
          }
          branch={branch}
          onBranchChange={onBranchChange}
          rootDirectory={rootDirectory}
          onRootDirectoryChange={onRootDirectoryChange}
          branchLabelTooltip={t(
            'Production branch of the app. Pushes to this branch start a build for every target.',
          )}
          rootDirectoryLabelTooltip={t(
            'Path to the app in the linked repository. Use the repository root (./) or the directory that holds the app project.',
          )}
          rootDirectoryDescription={t(
            'Choose the directory containing your app project',
          )}
          emptyStateTitle={t('Connect Git repository')}
          emptyStateDescription={t(
            'Build the app from a connected Git repository.',
          )}
        />
      )}
    </StepSection>
  )
}
