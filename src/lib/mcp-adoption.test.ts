import { describe, expect, test } from 'bun:test'
import { AGENT_SETUP_URL } from '@/lib/seo/agent-setup'
import { generateAgentSetupMarkdown } from '@/lib/seo/agent-setup'
import { buildConnectMcpPrompt } from '@/lib/mcp-adoption'

describe('buildConnectMcpPrompt', () => {
  test('points the agent at the public setup page and includes the project', () => {
    const prompt = buildConnectMcpPrompt({
      projectId: 'proj_123',
      projectName: 'Acme',
      isSelfHosted: false,
      endpoint: 'https://fra.cloud.appwrite.io/v1',
    })

    expect(prompt).toContain(`Get Appwrite-ready by following ${AGENT_SETUP_URL}`)
    expect(prompt).toContain('`proj_123`')
    expect(prompt).toContain('Acme')
    expect(prompt).toContain('https://fra.cloud.appwrite.io/v1')
    expect(prompt).not.toContain('self-hosted')
  })

  test('adds self-hosted endpoint details', () => {
    const prompt = buildConnectMcpPrompt({
      projectId: 'proj_123',
      projectName: 'Acme',
      isSelfHosted: true,
      endpoint: 'https://appwrite.example.com/v1',
    })

    expect(prompt).toContain(`Get Appwrite-ready by following ${AGENT_SETUP_URL}`)
    expect(prompt).toContain('https://appwrite.example.com/v1')
    expect(prompt).toContain('self-hosted')
  })
})

describe('generateAgentSetupMarkdown', () => {
  test('is fetchable agent instructions for Appwrite MCP', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('# Set yourself up to work with Appwrite')
    expect(markdown).toContain('https://mcp.appwrite.io')
    expect(markdown).toContain(AGENT_SETUP_URL)
    expect(markdown).toContain('claude mcp add appwrite')
  })
})
