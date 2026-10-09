/**
 * Fill project placeholders in docs code samples with the reader's project.
 *
 * Runs in the browser only, after the reader picks a project. Server-rendered
 * HTML, cached pages, and the `.md` exports keep the placeholders.
 */

export type DocsCodeProject = {
  id: string
  region: string
  endpoint: string
}

/** Full endpoint forms, replaced before the bare `<REGION>` token. */
const ENDPOINT_PATTERNS: RegExp[] = [
  /https:\/\/<REGION>\.cloud\.appwrite\.io\/v1/g,
  /https:\/\/cloud\.appwrite\.io\/v1/g,
  /<PROJECT_ENDPOINT>/g,
  /<YOUR_API_ENDPOINT>/g,
  /<ENDPOINT>/g,
]

const PROJECT_ID_PATTERNS: RegExp[] = [/<PROJECT_ID>/g, /<YOUR_PROJECT_ID>/g]

const REGION_PATTERN = /<REGION>/g

const ANY_PLACEHOLDER =
  /<(?:PROJECT_ID|YOUR_PROJECT_ID|REGION|PROJECT_ENDPOINT|YOUR_API_ENDPOINT|ENDPOINT)>|https:\/\/cloud\.appwrite\.io\/v1/

export function hasDocsCodePlaceholders(code: string): boolean {
  return ANY_PLACEHOLDER.test(code)
}

export function fillDocsCodePlaceholders(
  code: string,
  project: DocsCodeProject,
): string {
  let filled = code
  for (const pattern of ENDPOINT_PATTERNS) {
    filled = filled.replace(pattern, project.endpoint)
  }
  for (const pattern of PROJECT_ID_PATTERNS) {
    filled = filled.replace(pattern, project.id)
  }
  if (project.region && project.region !== 'unknown') {
    filled = filled.replace(REGION_PATTERN, project.region)
  }
  return filled
}
