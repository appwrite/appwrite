import { useParams } from '@tanstack/react-router'
import { useActiveEnvironmentId } from '@/lib/active-environment'
import { ProjectResourceSettingsShell } from '../../shared/ProjectResourceSettingsShell'
import { FUNCTION_SETTINGS_NAV } from './nav'

export function FunctionSettingsLayout() {
  const { projectId } = useParams({ strict: false })
  // Git installations cannot be connected from inside an environment
  const navItems = useActiveEnvironmentId(projectId)
    ? FUNCTION_SETTINGS_NAV.filter((item) => item.id !== 'git')
    : FUNCTION_SETTINGS_NAV
  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <ProjectResourceSettingsShell kind="function" navItems={navItems} />
      </div>
    </div>
  )
}
