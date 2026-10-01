import { describe, expect, test } from 'bun:test'
import { AGENT_SETUP_URL } from '@/lib/seo/agent-setup'
import { generateAgentSetupMarkdown } from '@/lib/seo/agent-setup'
import { buildConnectMcpPrompt } from '@/lib/mcp-adoption'

describe('buildConnectMcpPrompt', () => {
  test('points the agent at the public setup page and includes the project', () => {
    const prompt = buildConnectMcpPrompt({
      projectId: 'proj_123',
      projectName: 'Acme',
      endpoint: 'https://fra.cloud.appwrite.io/v1',
      origin: 'https://appwrite.io',
    })

    expect(prompt).toContain(
      `Install Appwrite MCP, the Appwrite skills, the latest CLI, and the matching official SDK by following ${AGENT_SETUP_URL}`,
    )
    expect(prompt).toContain('`proj_123`')
    expect(prompt).toContain('Acme')
    expect(prompt).toContain('- Endpoint: `https://fra.cloud.appwrite.io/v1`')
    expect(prompt).toContain('download it with curl')
    expect(prompt).not.toContain('npm install appwrite@latest')
  })

  test('leaves out the endpoint line when there is no endpoint', () => {
    const prompt = buildConnectMcpPrompt({
      projectId: 'proj_123',
      projectName: 'Acme',
      origin: 'https://appwrite.io',
    })

    expect(prompt).not.toContain('Endpoint')
    expect(prompt).not.toContain('``')
  })
})

describe('generateAgentSetupMarkdown', () => {
  test('is fetchable agent instructions for Appwrite MCP', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('# Set yourself up to work with Appwrite')
    expect(markdown).toContain('https://mcp.appwrite.io')
    expect(markdown).toContain(AGENT_SETUP_URL)
    expect(markdown).not.toContain('—')
  })

  test('prefers the plugins for Claude Code and Codex', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain(
      'claude plugin marketplace add anthropics/claude-plugins-official',
    )
    expect(markdown).toContain(
      'claude plugin install appwrite@claude-plugins-official',
    )
    expect(markdown).toContain(
      'codex plugin add app-6aa2c33323108191b17b9ccf4233b3ce@openai-curated-remote',
    )
    expect(markdown).toContain('claude mcp add appwrite')
    expect(markdown).toContain('codex mcp add appwrite')
  })

  test('installs the skills non-interactively for agents without a plugin', () => {
    const markdown = generateAgentSetupMarkdown()
    for (const agent of [
      'cursor',
      'github-copilot',
      'windsurf',
      'antigravity',
      'zed',
      'opencode',
      'grok',
    ]) {
      expect(markdown).toContain(
        `npx skills add appwrite/skills -g -a ${agent} -s '*' -y`,
      )
    }
  })

  test('keeps sign-ins from blocking the agent', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('**Never wait on a sign-in.**')
    expect(markdown).toContain(
      'appwrite login > "${TMPDIR:-/tmp}/appwrite-login.log" 2>&1 &',
    )
  })

  test('instructs the agent to install the matching official SDK at latest', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain(
      '## 5. Install the matching official Appwrite SDK',
    )
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
    expect(markdown).toContain('Do not invent an API key')
    expect(markdown).toContain('## 6. Sign the CLI in and select the project')
    expect(markdown).toContain('## 7. Verify and hand off')
  })
})
