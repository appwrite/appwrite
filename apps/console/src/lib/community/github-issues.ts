export type CommunityGitHubIssue = {
  number: number
  url: string
  title: string
  repository: string
  tags: string[]
}

const MOCK_ISSUES: CommunityGitHubIssue[] = [
  {
    number: 12530,
    url: 'https://github.com/appwrite/appwrite/pull/12530',
    title: 'Refactor Projects permission test coverage',
    repository: 'appwrite/appwrite',
    tags: [],
  },
  {
    number: 12529,
    url: 'https://github.com/appwrite/appwrite/pull/12529',
    title: 'fix: attribute default value persistence bug',
    repository: 'appwrite/appwrite',
    tags: [],
  },
  {
    number: 12528,
    url: 'https://github.com/appwrite/appwrite/pull/12528',
    title: 'fix: handle null name in user creation',
    repository: 'appwrite/appwrite',
    tags: [],
  },
  {
    number: 12527,
    url: 'https://github.com/appwrite/appwrite/pull/12527',
    title: 'fix: account deletion flow improvements',
    repository: 'appwrite/appwrite',
    tags: [],
  },
  {
    number: 12526,
    url: 'https://github.com/appwrite/appwrite/pull/12526',
    title: 'fix: add CORS headers to GraphQL endpoint',
    repository: 'appwrite/appwrite',
    tags: [],
  },
  {
    number: 12525,
    url: 'https://github.com/appwrite/appwrite/pull/12525',
    title: 'feat: add emailCanonical to user model',
    repository: 'appwrite/appwrite',
    tags: [],
  },
]

export async function fetchCommunityGitHubIssues(): Promise<CommunityGitHubIssue[]> {
  try {
    const response = await fetch(
      'https://api.github.com/repos/appwrite/appwrite/issues?state=open&per_page=6',
    )
    const issues = await response.json()

    if (!Array.isArray(issues) || issues?.message?.includes('API rate limit exceeded')) {
      return MOCK_ISSUES
    }

    return issues
      .filter((issue: { pull_request?: unknown }) => !issue.pull_request)
      .slice(0, 6)
      .map(
        (issue: {
          number: number
          html_url: string
          title: string
          repository_url: string
          labels: Array<{ name: string }>
        }) => ({
          number: issue.number,
          url: issue.html_url,
          title: issue.title,
          repository: issue.repository_url.replace('https://api.github.com/repos/', ''),
          tags: issue.labels.map((label) => label.name).slice(0, 3),
        }),
      )
  } catch {
    return MOCK_ISSUES
  }
}
