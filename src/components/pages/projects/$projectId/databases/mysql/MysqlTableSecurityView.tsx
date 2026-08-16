import { useEffect } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { useDatabaseTableOperationsAccess } from '../_components/DatabaseOperationsLockContext'
import { mysqlNav } from '@/lib/mysql-database-routes'
import { useMysqlTableHeaderSlot } from './_components/MysqlTableHeaderSlotContext'
import { MysqlTableSecurityPanel } from './_components/MysqlTableSecurityPanel'

export type MysqlTableSecurityViewProps = {
  databaseId: string
  tableId: string
}

export function MysqlTableSecurityView({
  databaseId,
  tableId,
}: MysqlTableSecurityViewProps) {
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { canWrite } = useDatabaseTableOperationsAccess()

  useMysqlTableHeaderSlot({})

  useEffect(() => {
    if (canWrite) return
    navigate({
      ...mysqlNav({ projectId, databaseId }).table({ tableId }).rows(),
      replace: true,
    })
  }, [canWrite, databaseId, navigate, projectId, tableId])

  if (!canWrite) {
    return null
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <MysqlTableSecurityPanel databaseId={databaseId} tableId={tableId} />
    </div>
  )
}
