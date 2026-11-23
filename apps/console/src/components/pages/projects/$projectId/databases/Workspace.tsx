import { useParams } from '@tanstack/react-router'
import { DatabaseType as ApiDatabaseType } from '@/lib/databases/database-type'
import {
  databaseRouteKindFromApiType,
  isDatabaseRouteKind,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { useProjectDatabase } from '@/lib/react-query/hooks'
import type { WorkspaceProps } from './workspace-types'
import { Workspace as TablesDbWorkspace } from './tablesdb/Workspace'
import { Workspace as DocumentsDbWorkspace } from './documentsdb/Workspace'
import { Workspace as VectorsDbWorkspace } from './vectorsdb/Workspace'

/**
 * Picks the isolated per-product workspace under `tablesdb/`, `documentsdb/`,
 * or `vectorsdb/` from the URL (and loaded database type when needed).
 */
export function Workspace(props: WorkspaceProps) {
  const params = useParams({ strict: false })
  const projectId = params.projectId as string
  const routeDbKind = (
    isDatabaseRouteKind(params.dbKind ?? '') ? params.dbKind : 'tablesdb'
  ) as DatabaseRouteKind
  const { database } = useProjectDatabase(
    projectId,
    props.databaseId,
    routeDbKind,
  )
  const kind: DatabaseRouteKind =
    (params.dbKind as DatabaseRouteKind | undefined) ??
    databaseRouteKindFromApiType(
      (database as { databaseType?: ApiDatabaseType } | null)?.databaseType,
    )

  if (kind === 'documentsdb') {
    return <DocumentsDbWorkspace {...props} />
  }
  if (kind === 'vectorsdb') {
    return <VectorsDbWorkspace {...props} />
  }
  return <TablesDbWorkspace {...props} />
}
