import { CodeEditor } from '@/components/global/shared/CodeEditor'

type PostgresSqlCodeEditorProps = {
  projectId: string
  databaseId: string
  sql: string
  onSqlChange: (value: string) => void
}

export function PostgresSqlCodeEditor({
  projectId,
  databaseId,
  sql,
  onSqlChange,
}: PostgresSqlCodeEditorProps) {
  return (
    <div className="relative min-h-0 flex-1">
      <div className="absolute inset-0 overflow-hidden">
        <CodeEditor
          value={sql}
          onChange={onSqlChange}
          language="sql"
          height="100%"
          modelPath={`postgres-sql/${projectId}/${databaseId}`}
          className="h-full rounded-none border-0"
        />
      </div>
    </div>
  )
}
