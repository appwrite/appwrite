import { MysqlSqlWorkbench } from './Workspace'

type SqlEditorProps = {
  databaseId: string
}

/** @deprecated SQL editor is rendered by the mysql database layout. */
export function View({ databaseId }: SqlEditorProps) {
  return <MysqlSqlWorkbench databaseId={databaseId} databaseTab="sql" />
}
