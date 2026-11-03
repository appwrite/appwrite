import { describe, expect, test } from 'bun:test'
import { AGENT_SETUP_URL, API_KEY_CREATE_LINK } from '@/lib/seo/agent-setup'
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
    expect(markdown).toContain('# Set up Appwrite and build with it')
    expect(markdown).toContain('https://mcp.appwrite.io')
    expect(markdown).toContain(AGENT_SETUP_URL)
    expect(markdown).not.toContain('—')
  })

  test('shows people reading along what happens and what changes', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('## At a glance')
    expect(markdown).toContain('| Step | The agent | You |')
    expect(markdown).toContain('### What changes on your machine')
    expect(markdown).toContain('### What the agent never does')
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
      'pi',
    ]) {
      expect(markdown).toContain(
        `npx --yes skills add appwrite/skills -g -a ${agent} -s '*' -y`,
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

  test('proposes a build and checks in once before building', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('## 5. Propose the build and check in')
    expect(markdown).toContain('| The app needs | Appwrite | Set it up with |')
    expect(markdown).toContain(
      'Approve the sign-ins, then reply "go", or change anything above.',
    )
  })

  test('links or creates the project and registers its platforms', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('## 6. Link or create the project')
    expect(markdown).toContain('appwrite init project')
    expect(markdown).toContain(
      'appwrite project create-web-platform --platform-id "web-local"',
    )
    expect(markdown).toContain('general_unknown_origin')
  })

  test('keeps API keys out of client code, chat, and git', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('Do not invent an API key')
    expect(markdown).toContain('never put one in client code')
    expect(markdown).toContain('Never echo the key in chat or commit it.')
    expect(markdown).toContain('x-appwrite-key')
  })

  test('gets server keys from a prefilled Console link, not MCP or the CLI', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(API_KEY_CREATE_LINK).toBe(
      'https://appwrite.io/projects/<projectId>/api-keys?name=<App>%20server&scopes=<scope>,<scope>',
    )
    expect(markdown).toContain(API_KEY_CREATE_LINK)
    expect(markdown).toContain('it carries no `keys.write`')
    expect(markdown).not.toContain('project_create_key')
  })

  test('checks row security with --raw, which keeps $permissions', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('--table-id "<tableId>" --raw')
    expect(markdown).toContain('`--json` leaves out `$permissions`')
  })

  test('keeps user-scoped SSR requests on the session, not an API key', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain(
      'Server code that acts as the signed-in user uses a session client with the session secret from the cookie, and needs no key.',
    )
    expect(markdown).toContain(
      'SSR sign-in handlers that create sessions (`sessions.write`)',
    )
  })

  test('provisions the Appwrite user before bridging another auth provider', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('finds or creates the matching Appwrite user')
    expect(markdown).toContain(
      '`users.createToken()`, which needs an existing user',
    )
  })

  test('instructs the agent to install the matching official SDKs at latest', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('## 7. Install the matching official SDKs')
    expect(markdown).toContain('npm install appwrite@latest')
    expect(markdown).toContain('@appwrite.io/react')
    expect(markdown).toContain('npm install node-appwrite@latest')
    expect(markdown).toContain('npm install react-native-appwrite@latest')
    expect(markdown).toContain('deno add jsr:@appwrite/sdk')
    expect(markdown).toContain('flutter pub add appwrite')
    expect(markdown).toContain('dart pub add dart_appwrite')
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
  })

  test('builds the first slice from schema config', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('## 8. Build the first working slice')
    expect(markdown).toContain('appwrite push tables --all --force')
    expect(markdown).toContain(
      "Without the user's approval of the specific change, use it only on a project you created in this session.",
    )
    expect(markdown).toContain(
      'run it again with `--force` only after they approve that change.',
    )
    expect(markdown).toContain(
      'run `appwrite pull tables` and `appwrite pull buckets` first',
    )
    expect(markdown).not.toContain('or one with no tables yet')
    expect(markdown).toContain('appwrite generate')
    expect(markdown).toContain('Never grant write access to `any`.')
  })

  test('hands off with capabilities picked for the app, for senior developers', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain('### Write for a senior developer')
    expect(markdown).toContain('## 10. Hand off')
    expect(markdown).toContain('**Next with Appwrite**')
    expect(markdown).toContain('## Appwrite reference for the hand-off')
  })

  test('still installs the skills when MCP is already connected', () => {
    const markdown = generateAgentSetupMarkdown()
    expect(markdown).toContain(
      'skip the plugin and server setup, but still install the skills if they are missing',
    )
    expect(markdown).not.toContain('MCP is connected. Skip step 3.')
  })
})
