import githubStarsData from '@/lib/generated/github-stars.json'

function formatStars(count: number): string {
  if (count >= 1000) {
    return `${(count / 1000).toFixed(1).replace(/\.0$/, '')}K`
  }
  return String(count)
}

export const MARKETING_SOCIAL_STATS = {
  github: {
    stat: formatStars(githubStarsData.stars),
    link: 'https://github.com/appwrite/appwrite',
    commits: '27K+',
    pullRequests: '4.7K+',
    issues: '3K+',
    openIssues: '600+',
    closedIssues: '3.3K+',
    forks: '4.4K+',
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
