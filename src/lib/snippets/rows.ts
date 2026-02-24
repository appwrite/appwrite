import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'

export type RowSnippetSdk =
  | 'web'
  | 'node'
  | 'python'
  | 'php'
  | 'ruby'
  | 'go'
  | 'dart'
  | 'flutter'
  | 'kotlin'
  | 'swift'
  | 'dotnet'

export type RowSnippetSection = {
  title: string
  code: string
}

export const ROW_SNIPPET_SDK_OPTIONS: {
  id: RowSnippetSdk
  label: string
  language: CodeBlockLanguage
}[] = [
  { id: 'web', label: 'Web', language: 'javascript' },
  { id: 'node', label: 'Node.js', language: 'typescript' },
  { id: 'python', label: 'Python', language: 'python' },
  { id: 'php', label: 'PHP', language: 'php' },
  { id: 'ruby', label: 'Ruby', language: 'ruby' },
  { id: 'go', label: 'Go', language: 'go' },
  { id: 'dart', label: 'Dart', language: 'dart' },
  { id: 'flutter', label: 'Flutter', language: 'dart' },
  { id: 'kotlin', label: 'Kotlin', language: 'kotlin' },
  { id: 'swift', label: 'Swift', language: 'swift' },
  { id: 'dotnet', label: '.NET', language: 'csharp' },
]

export function getRowSnippetSections(
  sdk: RowSnippetSdk,
  databaseId: string,
  tableId: string,
  rowId: string,
): { language: CodeBlockLanguage; sections: RowSnippetSection[] } {
  const language =
    ROW_SNIPPET_SDK_OPTIONS.find((option) => option.id === sdk)?.language ??
    'plaintext'

  const jsGetRow = `const result = await tablesDB.getRow({
  databaseId: '${databaseId}',
  tableId: '${tableId}',
  rowId: '${rowId}',
})`
  const jsListRows = `const result = await tablesDB.listRows({
  databaseId: '${databaseId}',
  tableId: '${tableId}',
  queries: [], // optional
})`
  const jsUpdateRow = `const result = await tablesDB.updateRow({
  databaseId: '${databaseId}',
  tableId: '${tableId}',
  rowId: '${rowId}',
  data: {
    // field: 'value'
  },
  permissions: ['read(\"any\")'], // optional
})`
  const jsDeleteRow = `await tablesDB.deleteRow({
  databaseId: '${databaseId}',
  tableId: '${tableId}',
  rowId: '${rowId}',
})`

  switch (sdk) {
    case 'python': {
      return {
        language,
        sections: [
          {
            title: 'Get row',
            code: `result = tables_db.get_row(\n    database_id="${databaseId}",\n    table_id="${tableId}",\n    row_id="${rowId}",\n)`,
          },
          {
            title: 'List rows',
            code: `result = tables_db.list_rows(\n    database_id="${databaseId}",\n    table_id="${tableId}",\n    queries=[],  # optional\n)`,
          },
          {
            title: 'Update row',
            code: `result = tables_db.update_row(\n    database_id="${databaseId}",\n    table_id="${tableId}",\n    row_id="${rowId}",\n    data={\n        # "field": "value"\n    },\n    permissions=['read("any")'],  # optional\n)`,
          },
          {
            title: 'Delete row',
            code: `tables_db.delete_row(\n    database_id="${databaseId}",\n    table_id="${tableId}",\n    row_id="${rowId}",\n)`,
          },
        ],
      }
    }
    case 'php': {
      return {
        language,
        sections: [
          {
            title: 'Get row',
            code: `$result = $tablesDB->getRow([\n  'databaseId' => '${databaseId}',\n  'tableId' => '${tableId}',\n  'rowId' => '${rowId}',\n]);`,
          },
          {
            title: 'List rows',
            code: `$result = $tablesDB->listRows([\n  'databaseId' => '${databaseId}',\n  'tableId' => '${tableId}',\n  'queries' => [], // optional\n]);`,
          },
          {
            title: 'Update row',
            code: `$result = $tablesDB->updateRow([\n  'databaseId' => '${databaseId}',\n  'tableId' => '${tableId}',\n  'rowId' => '${rowId}',\n  'data' => [\n    // 'field' => 'value'\n  ],\n  'permissions' => ['read(\"any\")'], // optional\n]);`,
          },
          {
            title: 'Delete row',
            code: `$tablesDB->deleteRow([\n  'databaseId' => '${databaseId}',\n  'tableId' => '${tableId}',\n  'rowId' => '${rowId}',\n]);`,
          },
        ],
      }
    }
    case 'ruby': {
      return {
        language,
        sections: [
          {
            title: 'Get row',
            code: `result = tables_db.get_row(\n  database_id: '${databaseId}',\n  table_id: '${tableId}',\n  row_id: '${rowId}'\n)`,
          },
          {
            title: 'List rows',
            code: `result = tables_db.list_rows(\n  database_id: '${databaseId}',\n  table_id: '${tableId}',\n  queries: [] # optional\n)`,
          },
          {
            title: 'Update row',
            code: `result = tables_db.update_row(\n  database_id: '${databaseId}',\n  table_id: '${tableId}',\n  row_id: '${rowId}',\n  data: {\n    # field: 'value'\n  },\n  permissions: ['read(\"any\")'] # optional\n)`,
          },
          {
            title: 'Delete row',
            code: `tables_db.delete_row(\n  database_id: '${databaseId}',\n  table_id: '${tableId}',\n  row_id: '${rowId}'\n)`,
          },
        ],
      }
    }
    case 'go': {
      return {
        language,
        sections: [
          {
            title: 'Get row',
            code: `result, err := tablesDB.GetRow(context.Background(), map[string]interface{}{\n  \"databaseId\": \"${databaseId}\",\n  \"tableId\": \"${tableId}\",\n  \"rowId\": \"${rowId}\",\n})`,
          },
          {
            title: 'List rows',
            code: `result, err := tablesDB.ListRows(context.Background(), map[string]interface{}{\n  \"databaseId\": \"${databaseId}\",\n  \"tableId\": \"${tableId}\",\n  \"queries\": []interface{}{},\n})`,
          },
          {
            title: 'Update row',
            code: `result, err := tablesDB.UpdateRow(context.Background(), map[string]interface{}{\n  \"databaseId\": \"${databaseId}\",\n  \"tableId\": \"${tableId}\",\n  \"rowId\": \"${rowId}\",\n  \"data\": map[string]interface{}{\n    // \"field\": \"value\",\n  },\n  \"permissions\": []string{\"read(\\\"any\\\")\"},\n})`,
          },
          {
            title: 'Delete row',
            code: `err := tablesDB.DeleteRow(context.Background(), map[string]interface{}{\n  \"databaseId\": \"${databaseId}\",\n  \"tableId\": \"${tableId}\",\n  \"rowId\": \"${rowId}\",\n})`,
          },
        ],
      }
    }
    case 'dart':
    case 'flutter': {
      return {
        language,
        sections: [
          {
            title: 'Get row',
            code: `final result = await tablesDB.getRow(\n  databaseId: '${databaseId}',\n  tableId: '${tableId}',\n  rowId: '${rowId}',\n);`,
          },
          {
            title: 'List rows',
            code: `final result = await tablesDB.listRows(\n  databaseId: '${databaseId}',\n  tableId: '${tableId}',\n  queries: [], // optional\n);`,
          },
          {
            title: 'Update row',
            code: `final result = await tablesDB.updateRow(\n  databaseId: '${databaseId}',\n  tableId: '${tableId}',\n  rowId: '${rowId}',\n  data: {\n    // 'field': 'value'\n  },\n  permissions: ['read(\"any\")'], // optional\n);`,
          },
          {
            title: 'Delete row',
            code: `await tablesDB.deleteRow(\n  databaseId: '${databaseId}',\n  tableId: '${tableId}',\n  rowId: '${rowId}',\n);`,
          },
        ],
      }
    }
    case 'kotlin': {
      return {
        language,
        sections: [
          {
            title: 'Get row',
            code: `val result = tablesDB.getRow(\n    databaseId = \"${databaseId}\",\n    tableId = \"${tableId}\",\n    rowId = \"${rowId}\",\n)`,
          },
          {
            title: 'List rows',
            code: `val result = tablesDB.listRows(\n    databaseId = \"${databaseId}\",\n    tableId = \"${tableId}\",\n    queries = listOf(), // optional\n)`,
          },
          {
            title: 'Update row',
            code: `val result = tablesDB.updateRow(\n    databaseId = \"${databaseId}\",\n    tableId = \"${tableId}\",\n    rowId = \"${rowId}\",\n    data = mapOf(\n        // \"field\" to \"value\",\n    ),\n    permissions = listOf(\"read(\\\"any\\\")\"), // optional\n)`,
          },
          {
            title: 'Delete row',
            code: `tablesDB.deleteRow(\n    databaseId = \"${databaseId}\",\n    tableId = \"${tableId}\",\n    rowId = \"${rowId}\",\n)`,
          },
        ],
      }
    }
    case 'swift': {
      return {
        language,
        sections: [
          {
            title: 'Get row',
            code: `let result = try await tablesDB.getRow(\n  databaseId: \"${databaseId}\",\n  tableId: \"${tableId}\",\n  rowId: \"${rowId}\"\n)`,
          },
          {
            title: 'List rows',
            code: `let result = try await tablesDB.listRows(\n  databaseId: \"${databaseId}\",\n  tableId: \"${tableId}\",\n  queries: []\n)`,
          },
          {
            title: 'Update row',
            code: `let result = try await tablesDB.updateRow(\n  databaseId: \"${databaseId}\",\n  tableId: \"${tableId}\",\n  rowId: \"${rowId}\",\n  data: [\n    // \"field\": \"value\"\n  ],\n  permissions: [\"read(\\\"any\\\")\"]\n)`,
          },
          {
            title: 'Delete row',
            code: `try await tablesDB.deleteRow(\n  databaseId: \"${databaseId}\",\n  tableId: \"${tableId}\",\n  rowId: \"${rowId}\"\n)`,
          },
        ],
      }
    }
    case 'dotnet': {
      return {
        language,
        sections: [
          {
            title: 'Get row',
            code: `var result = await tablesDB.GetRow(new Dictionary<string, object>\n{\n  [\"databaseId\"] = \"${databaseId}\",\n  [\"tableId\"] = \"${tableId}\",\n  [\"rowId\"] = \"${rowId}\",\n});`,
          },
          {
            title: 'List rows',
            code: `var result = await tablesDB.ListRows(new Dictionary<string, object>\n{\n  [\"databaseId\"] = \"${databaseId}\",\n  [\"tableId\"] = \"${tableId}\",\n  [\"queries\"] = new List<string>(),\n});`,
          },
          {
            title: 'Update row',
            code: `var result = await tablesDB.UpdateRow(new Dictionary<string, object>\n{\n  [\"databaseId\"] = \"${databaseId}\",\n  [\"tableId\"] = \"${tableId}\",\n  [\"rowId\"] = \"${rowId}\",\n  [\"data\"] = new Dictionary<string, object>\n  {\n    // [\"field\"] = \"value\"\n  },\n  [\"permissions\"] = new List<string> { \"read(\\\"any\\\")\" },\n});`,
          },
          {
            title: 'Delete row',
            code: `await tablesDB.DeleteRow(new Dictionary<string, object>\n{\n  [\"databaseId\"] = \"${databaseId}\",\n  [\"tableId\"] = \"${tableId}\",\n  [\"rowId\"] = \"${rowId}\",\n});`,
          },
        ],
      }
    }
    case 'node':
    case 'web':
    default:
      return {
        language,
        sections: [
          { title: 'Get row', code: jsGetRow },
          { title: 'List rows', code: jsListRows },
          { title: 'Update row', code: jsUpdateRow },
          { title: 'Delete row', code: jsDeleteRow },
        ],
      }
  }
}
