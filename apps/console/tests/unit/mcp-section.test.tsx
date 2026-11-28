import { afterAll, afterEach, describe, expect, spyOn, test } from 'bun:test'
import { GlobalRegistrator } from '@happy-dom/global-registrator'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createElement } from 'react'
import * as docs from '@/components/pages/docs/DocsRouteLink'
import { MCP_CLAUDE_DESKTOP_BRIDGE_CONFIG_SNIPPET } from '@/lib/config/mcp'
import { setDebugProfileOverride } from '@/lib/console-profiles'
import { MCPSection } from '@/components/pages/projects/$projectId/shared/MCPSection'

GlobalRegistrator.register({
  url: 'http://localhost/',
  settings: { disableJavaScriptFileLoading: true },
})
const { act, cleanup, fireEvent, isInaccessible, render, within } =
  await import('@testing-library/react')

setDebugProfileOverride('cloud')
// Docs navigation is outside the install section's behavior under test.
const docsLink = spyOn(docs, 'DocsRouteLink').mockImplementation(
  ({ href, children }) => createElement('a', { href }, children),
)

const client = new QueryClient()

afterEach(() => {
  cleanup()
  client.clear()
})
afterAll(async () => {
  setDebugProfileOverride(null)
  docsLink.mockRestore()
  await GlobalRegistrator.unregister()
})

function renderClaudeDesktop() {
  client.setQueryData(['project', 'project1'], {
    $id: 'project1',
    name: 'Example',
    region: 'fra',
  })
  const view = render(
    createElement(
      QueryClientProvider,
      { client },
      createElement(MCPSection, {
        projectId: 'project1',
        projectName: 'Example',
        parts: 'install',
      }),
    ),
  )
  fireEvent.click(view.getByRole('tab', { name: 'Claude Desktop' }))
  return view
}

const fallbackLabel = 'Local server fallback'

describe('MCPSection on Cloud', () => {
  test('shows the Claude Desktop fallback collapsed', () => {
    const view = renderClaudeDesktop()
    const summary = view.getByText(fallbackLabel, {
      selector: 'details > summary',
    })

    expect(isInaccessible(summary)).toBe(false)
    expect(summary.closest('details')!.open).toBe(false)
  })

  test('removes the fallback when switching to Cursor', () => {
    const view = renderClaudeDesktop()
    fireEvent.click(view.getByRole('tab', { name: 'Cursor' }))

    expect(
      view.getByRole('tab', { name: 'Cursor', selected: true }),
    ).toBeTruthy()
    expect(view.queryAllByText(fallbackLabel).length).toBe(0)
  })

  test('copies the local server configuration from the expanded fallback', async () => {
    const view = renderClaudeDesktop()
    const summary = view.getByText(fallbackLabel, {
      selector: 'details > summary',
    })
    const details = summary.closest('details')!
    if (!details.open) fireEvent.click(summary)
    expect(details.open).toBe(true)

    await act(async () => {
      fireEvent.click(within(details).getByRole('button', { name: 'Copy' }))
    })
    expect(await navigator.clipboard.readText()).toBe(
      JSON.stringify(MCP_CLAUDE_DESKTOP_BRIDGE_CONFIG_SNIPPET, null, 2),
    )
  })
})
