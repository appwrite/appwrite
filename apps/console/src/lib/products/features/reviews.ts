import type { ProductFeatureContent } from '@/lib/products/features/types'

/**
 * Reviews product page sections, in page order: the life of a pull request,
 * one stop per section, ending on the platform it ships on. The reviewer
 * reads the pull request and the repository, never deployments, logs, or
 * project data, so "all in one" is about the platform (one Git connection,
 * Console, and bill), not about review context.
 */
export const reviewsProductFeatures: ProductFeatureContent[] = [
  {
    id: 'connect',
    title: 'On where your code already ships',
    description:
      'Create a site or function from a repository and Review pull requests is already on. Turn it on for any other repository on GitHub, GitLab, or Bitbucket from the Repositories tab, one switch at a time or all at once.',
  },
  {
    id: 'review',
    title: 'A review on every pull request',
    description:
      'When a pull request opens or gets new commits, the reviewer posts one summary that it edits in place: what changed, a verdict, and a merge confidence grade from S to F, with the findings, a walkthrough, and what was fixed since the last review. New commits get an incremental review of just what changed.',
  },
  {
    id: 'findings',
    title: 'Findings on the lines they concern',
    description:
      'Each finding lands on the changed line with its severity, its category, and a plain explanation. When the fix is small, local, and certain, it comes as a suggested change, and every finding carries a prompt to fix it with AI.',
  },
  {
    id: 'verified',
    title: 'Grounded in your code, checked before it posts',
    description:
      'The reviewer reads the whole repository at the pull request’s head, your team’s guideline files, linked issues, and failed checks from your other CI apps. On Normal and Expert it can run commands in an isolated sandbox to confirm a suspected bug, and a second model checks every finding before anything is posted.',
  },
  {
    id: 'conversation',
    title: 'Talk to the reviewer',
    description:
      'Reply to a finding or mention the app with a question. It reads the code to answer, settles the thread when you are right, and holds its ground on bugs and security issues until the facts say otherwise. Answers use no credits.',
  },
  {
    id: 'learnings',
    title: 'It remembers how your team works',
    description:
      'Ask it to remember a preference in any pull request and it becomes a learning that later reviews and answers in that repository follow. See every learning in the Console, who asked for it, and remove the ones you no longer want.',
  },
  {
    id: 'strength',
    title: 'Choose how hard it looks',
    description:
      'Set a strength per repository. Basic reads the code with cheaper models, Normal runs commands in a sandbox, and Expert splits the work across subagents with stronger models. Each review costs credits from your plan, with no per-seat fees.',
  },
  {
    id: 'agents',
    title: 'Ready for your coding agents',
    description:
      'Pull requests from coding agents get reviewed like anyone else’s. The summary and every finding include a prompt to hand to your agent, and Appwrite agent skills read the review, push fixes, and ask again until the pull request reaches Tier S.',
  },
  {
    id: 'performance',
    title: 'See what it catches',
    description:
      'The Performance tab shows pull requests reviewed, how many were approved and how many on the first try, findings per pull request, how often findings were fixed rather than dismissed, and the median review time, by author, repository, and category.',
  },
  {
    id: 'platform',
    title: 'From pull request to production, on one platform',
    description:
      'Reviews is part of the project that builds, hosts, and watches your app. The pull request it reviews deploys to Sites and Functions, Monitors watch what you merged, and Beep pages your phone when something needs you, all in one Console and on one bill.',
    layout: 'stacked',
    centered: true,
    hideVisual: true,
    wideCompanion: true,
    breakRail: true,
    brandLight: 'teal',
    dottedBackground: true,
  },
]
