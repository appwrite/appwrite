import { DatabaseSettingsShell } from './DatabaseSettingsShell'

export function DatabaseSettingsLayout() {
  return (
    <div className="min-h-0 flex-1 overflow-y-auto">
      <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6 sm:pb-8 sm:pt-6">
        <DatabaseSettingsShell />
      </div>
    </div>
  )
}
