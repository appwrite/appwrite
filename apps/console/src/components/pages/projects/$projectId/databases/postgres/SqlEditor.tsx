import { PostgresSqlWorkbench } from './Workspace'

type SqlEditorProps = {
  databaseId: string
}

/** @deprecated SQL editor is rendered by the postgres database layout. */
export function View({ databaseId }: SqlEditorProps) {
  return <PostgresSqlWorkbench databaseId={databaseId} databaseTab="sql" />
}
