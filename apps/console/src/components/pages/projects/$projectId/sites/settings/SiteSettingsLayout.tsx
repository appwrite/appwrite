import { ProjectResourceSettingsShell } from '../../shared/ProjectResourceSettingsShell'
import { SITE_SETTINGS_NAV } from './nav'

export function SiteSettingsLayout() {
  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <ProjectResourceSettingsShell
          kind="site"
          navItems={SITE_SETTINGS_NAV}
        />
      </div>
    </div>
  )
}
