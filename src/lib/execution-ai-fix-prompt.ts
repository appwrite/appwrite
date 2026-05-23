import type { Models } from '@appwrite.io/console'

function formatExecutionLogContent(logs: Models.Execution['logs']): string {
  if (!logs) return ''
  if (typeof logs === 'string') return logs
  if (Array.isArray(logs)) return (logs as string[]).join('\n')
  return JSON.stringify(logs, null, 2)
}

function stripAnsiCodes(text: string): string {
  return text.replace(/\x1b\[(\d+(?:;\d+)*)?m/g, '')
}

function lastLines(text: string, lineCount: number): string {
  if (!text.trim()) return ''
  const lines = text.split('\n')
  return lines.slice(-lineCount).join('\n')
}

export function generateExecutionAIFixPrompt(
  execution: Models.Execution,
  options?: {
    resourceVariant?: 'function' | 'site'
    resourceName?: string | null
    runtime?: string | null
  },
): string {
  const resourceType = options?.resourceVariant === 'site' ? 'Site' : 'Function'
  const errors = stripAnsiCodes(
    lastLines(formatExecutionLogContent(execution.errors), 100),
  )
  const logs = stripAnsiCodes(
    lastLines(formatExecutionLogContent(execution.logs), 100),
  )

  let prompt = `# Fix Appwrite ${resourceType} Execution Failure

## Context
`

  if (options?.resourceName?.trim()) {
    prompt += `- **${resourceType} Name**: ${options.resourceName.trim()}\n`
  }

  prompt += `- **Execution ID**: ${execution.$id}\n`

  if (execution.deploymentId) {
    prompt += `- **Deployment ID**: ${execution.deploymentId}\n`
  }

  if (options?.runtime?.trim()) {
    prompt += `- **Runtime**: ${options.runtime.trim()}\n`
  }

  if (execution.requestMethod) {
    prompt += `- **Method**: ${execution.requestMethod.toUpperCase()}\n`
  }

  if (execution.requestPath) {
    prompt += `- **Path**: ${execution.requestPath}\n`
  }

  if (execution.responseStatusCode) {
    prompt += `- **Response Status Code**: ${execution.responseStatusCode}\n`
  }

  if (execution.trigger) {
    prompt += `- **Trigger**: ${execution.trigger}\n`
  }

  prompt += `- **Status**: ${execution.status}\n`
  prompt += `- **Created**: ${new Date(execution.$createdAt).toISOString()}\n`

  prompt += `
## Errors (Last 100 lines)

\`\`\`
${errors || 'No errors recorded'}
\`\`\`

## Execution Logs (Last 100 lines)

\`\`\`
${logs || 'No execution logs available'}
\`\`\`

## Task

Please analyze the errors and execution logs above and help me fix this failed execution. Identify:
1. The root cause of the failure
2. Specific code changes or configuration updates needed
3. Any runtime, request, or deployment issues contributing to the failure

Provide clear, actionable steps to resolve this issue.`

  return prompt
}
