import { afterAll, afterEach, describe, expect, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { createElement } from 'react'
import type { CompactFilterKey, FilterColumn } from '@/lib/table-filters'

GlobalRegistrator.register({ url: 'http://localhost/' })
const { cleanup, render } = await import('@testing-library/react')
const { ActiveFilterChips } = await import(
  '../../src/components/global/shared/ActiveFilterChips.tsx'
)

const columns: FilterColumn[] = [
  { id: 'botCategory', title: 'Bot category', type: 'string' },
  { id: 'path', title: 'Path', type: 'string' },
]

function renderChips(key: CompactFilterKey, fixedTag?: string) {
  return render(
    createElement(ActiveFilterChips, {
      filterMap: new Map([[key, 'query']]),
      columns,
      onApplyFilter: () => {},
      onRemoveFilter: () => {},
      onClearAllFilters: () => {},
      fixedTag: fixedTag ? () => fixedTag : undefined,
    }),
  )
}

afterEach(() => cleanup())
afterAll(() => GlobalRegistrator.unregister())

describe('ActiveFilterChips', () => {
  test('a fixed tag keeps its own wording and cannot be edited', () => {
    const view = renderChips(
      { c: 'botCategory', o: 'equal', v: '' },
      '**Humans** only',
    )

    expect(view.container.textContent).toContain('Humans')
    expect(view.container.textContent).toContain('only')
    expect(view.container.textContent).not.toContain('equal')
    expect(view.queryByLabelText('Change operator')).toBeNull()
  })

  test('a filter shows its field, an operator menu and its value', () => {
    const view = renderChips({ c: 'path', o: 'equal', v: '/v1/users' })

    expect(view.getByTitle('Path').textContent).toBe('Path')
    expect(view.getByLabelText('Change operator').textContent).toContain(
      'equal',
    )
    expect(view.getByTitle('/v1/users')).toBeTruthy()
  })
})
