import { useOnboarding } from './OnboardingLayout'
import {
  DeploySitePath,
  AddAuthPath,
  CreateDatabasePath,
  ManageFilesPath,
  RunFunctionsPath,
  ExplorePath,
} from './paths'

export function OnboardingPathContent() {
  const { state } = useOnboarding()

  switch (state.selectedIntent) {
    case 'deploy-site':
      return <DeploySitePath />
    case 'add-auth':
      return <AddAuthPath />
    case 'create-database':
      return <CreateDatabasePath />
    case 'manage-files':
      return <ManageFilesPath />
    case 'run-functions':
      return <RunFunctionsPath />
    case 'explore':
      return <ExplorePath />
    default:
      return null
  }
}
