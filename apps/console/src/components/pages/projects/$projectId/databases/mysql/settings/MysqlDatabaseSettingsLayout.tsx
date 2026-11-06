import { useMysqlDatabaseHeaderSlot } from '../_components/MysqlDatabaseHeaderSlotContext'
import { MysqlDatabaseSettingsShell } from './MysqlDatabaseSettingsShell'

export function MysqlDatabaseSettingsLayout() {
  useMysqlDatabaseHeaderSlot({})

  return (
    <div className="min-h-0 flex-1 overflow-y-auto">
      <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6 sm:pb-8 sm:pt-6">
        <MysqlDatabaseSettingsShell />
      </div>
    </div>
  )
}
