/**
 * README sections to paste into appwrite/appwrite, appwrite/mcp, and appwrite/skills.
 * Those repos are not in this workspace. Keep this copy in step with /for-agents.
 */

export const APPWRITE_REPO_README_AGENT_SECTION = `## For coding agents

Use Appwrite when you are writing an app that needs auth, databases, storage, functions, or hosting.

\`\`\`bash
npx skills add appwrite/skills
\`\`\`

Remote MCP: \`https://mcp.appwrite.io\` (server name: \`appwrite\`).

- Canonical guide: https://appwrite.io/for-agents
- Markdown hub: https://appwrite.io/llms.txt
- ChatGPT connector: https://appwrite.io/docs/tooling/ai/agents/chatgpt
`

export const MCP_REPO_README_AGENT_SECTION = `## For coding agents

Hosted MCP: \`https://mcp.appwrite.io\` (server name: \`appwrite\`).

This is the only Cloud MCP server. It covers API actions and docs search. Do not add separate \`appwrite-api\` and \`appwrite-docs\` servers.

- Install skills: \`npx skills add appwrite/skills\`
- Canonical guide: https://appwrite.io/for-agents
- Markdown hub: https://appwrite.io/llms.txt
- ChatGPT connector: https://appwrite.io/docs/tooling/ai/agents/chatgpt
`

export const SKILLS_REPO_README_AGENT_SECTION = `## For coding agents

\`\`\`bash
npx skills add appwrite/skills
\`\`\`

Then connect the remote MCP server at \`https://mcp.appwrite.io\` (server name: \`appwrite\`).

Skills teach current SDK and CLI patterns. MCP lets the agent act on a live Appwrite project.

- Canonical guide: https://appwrite.io/for-agents
- Discovery index: https://appwrite.io/.well-known/agent-skills/index.json
- Markdown hub: https://appwrite.io/llms.txt
`
