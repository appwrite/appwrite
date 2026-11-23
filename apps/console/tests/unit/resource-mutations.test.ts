import { describe, expect, test } from 'bun:test'
import {
  classifyResourceMutation,
  resolveCatalogToolName,
} from '@/lib/assistant/resource-mutations'

describe('resolveCatalogToolName', () => {
  test('unwraps catalog names from both MCP call tools', () => {
    expect(
      resolveCatalogToolName({
        name: 'appwrite_call_tool',
        input: { tool_name: 'users_list' },
      }),
    ).toBe('users_list')
    expect(
      resolveCatalogToolName({
        name: 'appwrite_call_write_tool',
        argumentsJson: JSON.stringify({
          tool_name: 'users_create',
          confirm_write: true,
        }),
      }),
    ).toBe('users_create')
  })
})

describe('classifyResourceMutation', () => {
  test('never counts an MCP call tool as a mutation itself', () => {
    expect(classifyResourceMutation('appwrite_call_tool')).toBeNull()
    expect(classifyResourceMutation('appwrite_call_write_tool')).toBeNull()
  })

  test('classifies the unwrapped catalog operation', () => {
    expect(classifyResourceMutation('users_create')).toBe('create')
    expect(classifyResourceMutation('storage_update_bucket')).toBe('update')
    expect(classifyResourceMutation('tables_db_delete_row')).toBe('delete')
    expect(classifyResourceMutation('users_list')).toBeNull()
  })
})
