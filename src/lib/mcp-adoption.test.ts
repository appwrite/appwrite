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

    expect(prompt).toContain(
      `Install Appwrite MCP, the latest CLI, and the matching official SDK by following ${AGENT_SETUP_URL}`,
    )
    expect(prompt).toContain('`proj_123`')
    expect(prompt).toContain('Acme')
    expect(prompt).toContain('https://fra.cloud.appwrite.io/v1')
    expect(prompt).not.toContain('self-hosted')
    expect(prompt).not.toContain('npm install appwrite@latest')
  })

  test('adds self-hosted endpoint details', () => {
    const prompt = buildConnectMcpPrompt({
      projectId: 'proj_123',
      projectName: 'Acme',
      isSelfHosted: true,
      endpoint: 'https://appwrite.example.com/v1',
    })

    expect(prompt).toContain(
      `Install Appwrite MCP, the latest CLI, and the matching official SDK by following ${AGENT_SETUP_URL}`,
    )
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

  test('instructs the agent to install the matching official SDK at latest', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('## 4. Install the matching official Appwrite SDK')
    expect(markdown).toContain('npm install appwrite@latest')
    expect(markdown).toContain('npm install node-appwrite@latest')
    expect(markdown).toContain('npm install react-native-appwrite@latest')
    expect(markdown).toContain('deno add jsr:@appwrite/sdk')
    expect(markdown).toContain('flutter pub add appwrite')
    expect(markdown).toContain('dart pub add appwrite')
    expect(markdown).toContain('pip install -U appwrite')
    expect(markdown).toContain('composer require appwrite/appwrite')
    expect(markdown).toContain('bundle add appwrite')
    expect(markdown).toContain('dotnet add package Appwrite')
    expect(markdown).toContain('go get github.com/appwrite/sdk-for-go@latest')
    expect(markdown).toContain('io.appwrite:sdk-for-android')
    expect(markdown).toContain('io.appwrite:sdk-for-kotlin')
    expect(markdown).toContain('sdk-for-apple')
    expect(markdown).toContain('sdk-for-swift')
    expect(markdown).toContain('cargo add appwrite')
    expect(markdown).toContain('npx skills add appwrite/skills')
    expect(markdown).toContain('Do not invent an API key')
    expect(markdown).toContain('## 5. Log in the CLI and select the project')
    expect(markdown).toContain('## 6. Use the user\'s project')
    expect(markdown).toContain('## 7. Self-hosted Appwrite')
  })
})
