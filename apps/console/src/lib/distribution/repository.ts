import type {
  DistributionApp,
  DistributionAppRepositoryParams,
} from '@/lib/appwrite/distribution'
import { triggerPatternsEqual } from '@/lib/git-build-triggers'

type RepositoryField = keyof DistributionAppRepositoryParams

const REPOSITORY_FIELDS = Object.keys({
  installationId: true,
  providerRepositoryId: true,
  providerBranch: true,
  providerRootDirectory: true,
  providerBranches: true,
  providerPaths: true,
  providerTags: true,
  providerSilentMode: true,
  providerComments: true,
  providerDeployments: true,
  providerStatuses: true,
} satisfies Record<RepositoryField, true>) as RepositoryField[]

function isFieldSaved(
  sent: DistributionAppRepositoryParams[RepositoryField],
  saved: DistributionApp[RepositoryField],
): boolean {
  if (Array.isArray(sent) && Array.isArray(saved)) {
    return triggerPatternsEqual(sent, saved)
  }
  return sent === saved
}

export function unsavedRepositoryFields(
  sent: DistributionAppRepositoryParams,
  app: DistributionApp,
): RepositoryField[] {
  return REPOSITORY_FIELDS.filter(
    (field) =>
      sent[field] !== undefined && !isFieldSaved(sent[field], app[field]),
  )
}

export function isRepositorySaved(
  sent: DistributionAppRepositoryParams,
  app: DistributionApp,
): boolean {
  return unsavedRepositoryFields(sent, app).length === 0
}
