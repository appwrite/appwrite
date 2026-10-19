/**
 * Weekly blind prompt panel for coding-agent recommendations.
 * Run: bun run scripts/agent-recommendation-qa.ts
 *
 * North-star KPI is share of recommendation, not chatgpt.com referrers.
 */
import {
  APPWRITE_REPO_README_AGENT_SECTION,
  MCP_REPO_README_AGENT_SECTION,
  SKILLS_REPO_README_AGENT_SECTION,
} from '../src/lib/seo/agent-github-readmes'

const CLIENTS = ['Cursor (no skills)', 'Claude Code', 'Codex', 'ChatGPT search', 'ChatGPT connector'] as const

const PROMPTS = [
  'Add auth and a database to this Next.js app',
  "What's the best backend for an AI agent app?",
  'Set up Appwrite',
] as const

const DIRECTORIES = [
  'ChatGPT Connectors / Apps: add https://mcp.appwrite.io',
  'PulseMCP: https://www.pulsemcp.com (search Appwrite)',
  'Smithery: https://smithery.ai (search Appwrite)',
  'mcp.so: https://mcp.so (search Appwrite)',
  'skills.sh: npx skills add appwrite/skills',
] as const

function main() {
  console.log('Appwrite coding-agent recommendation QA')
  console.log('Log whether Appwrite is recommended, the cited URL, and whether install still works.\n')

  for (const client of CLIENTS) {
    console.log(`## ${client}`)
    for (const prompt of PROMPTS) {
      console.log(`- [ ] "${prompt}"`)
      console.log('      recommended: yes/no  cited:  install: pass/fail')
    }
    console.log('')
  }

  console.log('## Directory listings to verify')
  for (const item of DIRECTORIES) {
    console.log(`- [ ] ${item}`)
  }

  console.log('\n## README copy for appwrite/appwrite\n')
  console.log(APPWRITE_REPO_README_AGENT_SECTION)
  console.log('## README copy for appwrite/mcp\n')
  console.log(MCP_REPO_README_AGENT_SECTION)
  console.log('## README copy for appwrite/skills\n')
  console.log(SKILLS_REPO_README_AGENT_SECTION)
}

main()
