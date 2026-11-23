import githubStarsData from '@/lib/generated/github-stars.json'
import type { GitHubStarHistoryPoint } from '@/lib/marketing/github-stars-history'

type GithubStarsFile = {
  stars: number
  fetchedAt: string
  forks?: number
  commits?: number
  history?: GitHubStarHistoryPoint[]
}

const githubStars = githubStarsData as GithubStarsFile

export const GITHUB_STARS_COUNT: number = githubStars.stars

export const GITHUB_STARS_HISTORY: GitHubStarHistoryPoint[] = Array.isArray(
  githubStars.history,
)
  ? githubStars.history
  : []

function formatStars(count: number): string {
  if (count >= 1000) {
    return `${(count / 1000).toFixed(1).replace(/\.0$/, '')}K`
  }
  return String(count)
}

/** Generated count as a compact "5.8K+" stat; `fallback` when not generated. */
function formatGeneratedStat(
  count: number | undefined,
  fallback: string,
): string {
  if (typeof count !== 'number' || !Number.isFinite(count) || count <= 0) {
    return fallback
  }
  return `${formatStars(Math.round(count))}+`
}

/** Whole thousands for compact "K+" / "k+" marketing stats. */
export const GITHUB_STARS_THOUSANDS = Math.floor(GITHUB_STARS_COUNT / 1000)

export const MARKETING_SOCIAL_STATS = {
  github: {
    stat: formatStars(GITHUB_STARS_COUNT),
    link: 'https://github.com/appwrite/appwrite',
    commits: formatGeneratedStat(githubStars.commits, '27K+'),
    pullRequests: '4.7K+',
    issues: '3K+',
    openIssues: '600+',
    closedIssues: '3.3K+',
    forks: formatGeneratedStat(githubStars.forks, '4.4K+'),
    // Curated: GitHub's REST contributor count caps far below the repo page.
    contributors: '800+',
  },
  discord: {
    stat: '23K+',
    link: '/discord',
  },
  twitter: {
    stat: '127K+',
    link: 'https://twitter.com/intent/follow?screen_name=appwrite',
  },
  youtube: {
    stat: '13K+',
    link: 'https://www.youtube.com/c/appwrite?sub_confirmation=1',
  },
} as const
