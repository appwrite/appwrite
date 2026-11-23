import type { Models } from '@appwrite.io/console'

function stripAnsiCodes(text: string): string {
  return text.replace(/\x1b\[(\d+(?:;\d+)*)?m/g, '')
}

/**
 * Generate the agent fix prompt for a failed deployment.
 */
export function generateDeploymentAIFixPrompt(
  deployment: Models.Deployment,
  runtime?: string,
  resourceName?: string,
  isSite?: boolean,
): string {
  const resourceType = isSite ? 'Site' : 'Function'
  const buildLogs = deployment.buildLogs || ''

  const logLines = buildLogs.split('\n')
  const lastLogs = logLines.slice(-100).join('\n')
  const cleanLogs = stripAnsiCodes(lastLogs)

  let prompt = `# Fix Appwrite ${resourceType} Deployment Failure

## Context
`

  if (resourceName) {
    prompt += `- **${resourceType} Name**: ${resourceName}\n`
  }

  prompt += `- **Deployment ID**: ${deployment.$id}\n`

  if (runtime) {
    prompt += `- **Runtime**: ${runtime}\n`
  }

  prompt += `- **Status**: Failed\n`
  prompt += `- **Created**: ${new Date(deployment.$createdAt).toISOString()}\n`

  if (deployment.providerBranch) {
    prompt += `- **Branch**: ${deployment.providerBranch}\n`
  }

  if (deployment.providerCommitHash) {
    prompt += `- **Commit**: ${deployment.providerCommitHash.slice(0, 7)}\n`
  }

  if (deployment.providerCommitMessage) {
    prompt += `- **Commit Message**: ${deployment.providerCommitMessage}\n`
  }

  prompt += `
## Build Logs (Last 100 lines)

\`\`\`
${cleanLogs || 'No build logs available'}
\`\`\`

## Task

Please analyze the build logs above and help me fix the deployment failure. Identify:
1. The root cause of the failure
2. Specific code changes or configuration updates needed
3. Any missing dependencies or incorrect settings

Provide clear, actionable steps to resolve this issue.`

  return prompt
}
