import type { ProductPageContent } from '@/lib/products/types'

/**
 * Reviews product page copy. Facts follow the Reviews module, the review
 * service, and Cloud's review credits (November 2026): providers GitHub,
 * GitLab, and Bitbucket (Gitea and Origin exist in code but are not offered yet); strengths Basic 10, Normal 50, and
 * Expert 500 credits per review; chat answers free; failed and skipped
 * reviews not billed. Confirm credit allowances against the pricing page
 * before launch.
 */
export const reviewsProductContent: ProductPageContent = {
  id: 'reviews',
  metaTitle: 'Reviews: AI code review for every pull request',
  metaDescription:
    'Appwrite Reviews reviews every pull request on your Sites, Functions, and repositories on GitHub, GitLab, and Bitbucket. A summary, findings on the lines they concern, a verdict, and a merge confidence grade, on the same Git connection, Console, and bill as your deploys.',
  hero: {
    title: 'Every pull request, reviewed',
    description:
      'Appwrite Reviews reads each pull request on your Sites, Functions, and repositories, and posts a summary, findings on the lines they concern, a verdict, and a merge confidence grade. It runs on the same Git connection, Console, and bill as your deploys.',
    stats: [
      { value: 'Every PR', label: 'On GitHub, GitLab, and Bitbucket' },
      { value: 'S to F', label: 'Merge confidence on every review' },
      { value: 'Verified', label: 'Each finding checked before it posts' },
      { value: 'No seats', label: 'Credits from your Appwrite plan' },
      { value: 'On by default', label: 'For new Sites and Functions' },
    ],
  },
  faq: [
    {
      question: 'What is Appwrite Reviews?',
      answer:
        'Appwrite Reviews is AI code review built into Appwrite. It reviews every pull request on the repositories you turn it on for, and posts a summary, findings on the lines they concern, a verdict, and a merge confidence grade from S to F on the pull request, with the full review and its trace in the Console.',
    },
    {
      question: 'Which Git providers are supported?',
      answer:
        'GitHub, GitLab, including self-managed GitLab, and Bitbucket. On GitHub the reviewer is the same Appwrite app that deploys your Sites and Functions. On GitLab and Bitbucket it comments as the account that connected the installation.',
    },
    {
      question: 'When does a review run?',
      answer:
        'When a pull request is opened, reopened, or marked ready for review, and again when it gets new commits, which are reviewed incrementally. Mention the app with “review” to run it again. Drafts and closed pull requests are skipped.',
    },
    {
      question: 'What does the reviewer read?',
      answer:
        'The pull request and the repository: the diff, the code at the pull request’s head, the title and description, linked issues, failed checks from your other CI apps, dependency changes, and guideline files such as AGENTS.md, CLAUDE.md, and CONTRIBUTING.md. It doesn’t read your Appwrite project’s databases, files, logs, or secrets.',
    },
    {
      question: 'Does it approve pull requests?',
      answer:
        'It posts a verdict with every review: approve, request changes, or comment. The verdict and the grade cap are decided by code from the findings, not by the model, and a pull request from someone without write access is not approved by default. Each review also posts a status check, so your branch rules can require it.',
    },
    {
      question: 'Can I talk to the reviewer?',
      answer:
        'Yes. Reply to any of its findings or mention the app with a question. It reads the code to answer, can resolve or dismiss a finding when the conversation settles it, and can save a learning for later reviews. Answers use no credits.',
    },
    {
      question: 'How is Reviews priced?',
      answer:
        'Reviews use credits from your Appwrite plan, with no per-seat fees. A Basic review uses 10 credits, Normal 50, and Expert 500. Free includes 100 credits a month for your organization, Pro includes 2,000 a month per project, and Scale 20,000. On paid plans, extra credits cost $1 per 100. Answers in conversations, failed reviews, and skipped reviews are free.',
      links: [{ label: 'Pricing', href: '/pricing' }],
    },
    {
      question: 'Does Reviews work with self-hosted Appwrite?',
      answer:
        'Self-hosted instances can opt in by running the review service with their own model provider. Reviews there read the code without running commands in a sandbox.',
    },
  ],
  cta: {
    title: 'Review your next pull request',
    description:
      'Turn on Reviews for a repository, open a pull request, and get a summary, findings, and a merge confidence grade where you already work.',
  },
}
