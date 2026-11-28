import { describe, expect, test } from 'bun:test'
import {
  MCP_SERVER_URL,
  getCloudMcpTools,
  getSelfHostedMcpTools,
} from '@/lib/config/mcp'

const t = (text: string) => text

describe('MCP setup options', () => {
  test('offers a copyable remote URL and connector instructions for Claude Desktop on Cloud', () => {
    const tool = getCloudMcpTools(t).find(({ id }) => id === 'claude-desktop')!

    expect(new URL(tool.code).href).toBe('https://mcp.appwrite.io/')
    expect(tool.language).toBe('plaintext')
    expect(tool.instructions).toContain('Connectors')
    expect(tool.instructions).toContain('sign in to Appwrite')
    expect(tool.installUrl).toBeUndefined()
  })

  test('keeps a copyable stdio fallback separate from the Cloud URL', () => {
    const tool = getCloudMcpTools(t).find(({ id }) => id === 'claude-desktop')!
    const fallback = JSON.parse(tool.fallbackCode!)

    expect(fallback.mcpServers.appwrite).toEqual({
      command: 'npx',
      args: ['-y', 'mcp-remote@0.16.0', MCP_SERVER_URL],
    })
    expect(tool.code).not.toContain('mcp-remote')
  })

  test('keeps self-hosted Claude Desktop on uvx with the selected project and endpoint', () => {
    const tool = getSelfHostedMcpTools(
      'project-example',
      'http://localhost/v1',
    ).find(({ id }) => id === 'claude-desktop')!

    expect(JSON.parse(tool.code).mcpServers.appwrite).toEqual({
      command: 'uvx',
      args: ['mcp-server-appwrite'],
      env: {
        APPWRITE_PROJECT_ID: 'project-example',
        APPWRITE_API_KEY: 'YOUR_API_KEY',
        APPWRITE_ENDPOINT: 'http://localhost/v1',
      },
    })
    expect(tool.instructions).toBeUndefined()
    expect(tool.fallbackCode).toBeUndefined()
    expect(tool.installUrl).toBeUndefined()
  })

  test.each(['cursor', 'vscode'])(
    'preserves the %s native install link',
    (id) => {
      const tool = getCloudMcpTools(t).find((tool) => tool.id === id)!
      const link = new URL(tool.installUrl!)
      const config =
        id === 'cursor'
          ? JSON.parse(atob(link.searchParams.get('config')!))
          : JSON.parse(decodeURIComponent(link.search.slice(1)))

      expect(config.url).toBe(MCP_SERVER_URL)
      expect(config.command).toBeUndefined()
      expect(tool.fallbackCode).toBeUndefined()
    },
  )

  test('passes connector instructions through the translator', () => {
    const tool = getCloudMcpTools((text) => `translated:${text}`).find(
      ({ id }) => id === 'claude-desktop',
    )!

    expect(tool.instructions).toStartWith('translated:')
  })
})
