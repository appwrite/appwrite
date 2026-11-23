export type HomeCaseStudy = {
  id: string
  logo: string
  logoWidth: number
  logoHeight: number
  /** K-Collect and similar logos use a foreground mask in light/dark mode. */
  logoMask?: boolean
  /** Slightly larger treatment for logos that read small at default scale. */
  logoSize?: 'lg'
  headline: string
  blurb: string
  name: string
  title: string
  company: string
  avatar: string
  storyUrl: string
}

/** All homepage case-study cards; three are chosen at random on each visit. */
export const allHomeCaseStudies: HomeCaseStudy[] = [
  {
    id: 'devkind',
    logo: '/images/logos/trusted-by/devkind.svg',
    logoWidth: 107,
    logoHeight: 32,
    headline: 'DevKind reduced development time by 60% and lowered server costs by 40%',
    blurb:
      'A special thanks to Appwrite for providing robust features and seamless functionality.',
    name: 'Hassan Ahmed',
    title: 'Software Engineer',
    company: 'DevKind',
    avatar: '/images/testimonials/hassan.avif',
    storyUrl: '/blog/post/customer-story-storealert',
  },
  {
    id: 'langx',
    logo: '/images/logos/trusted-by/langx.svg',
    logoWidth: 134,
    logoHeight: 29,
    headline: 'LangX handled millions of requests using Appwrite',
    blurb: 'With its comprehensive suite of services, Appwrite emerged as an ideal choice for my needs.',
    name: 'Xue',
    title: 'Founder',
    company: 'LangX',
    avatar: '/images/testimonials/xue.avif',
    storyUrl: '/blog/post/customer-stories-langx',
  },
  {
    id: 'k-collect',
    logo: '/images/logos/trusted-by/k-collect.svg',
    logoWidth: 120,
    logoHeight: 35,
    logoMask: true,
    headline: 'K-Collect reduced infrastructure costs by 700%',
    blurb: 'A major impact that Appwrite made was the amount of time and stress saved.',
    name: "Ryan O'Connor",
    title: 'Founder',
    company: 'K-Collect',
    avatar: '/images/testimonials/ryan.avif',
    storyUrl: '/blog/post/customer-stories-kcollect',
  },
  {
    id: 'majik-kids',
    logo: '/images/logos/trusted-by/majik-kids.svg',
    logoWidth: 88,
    logoHeight: 32,
    headline: 'Majik Kids built a Fair Pay audio platform for children on Appwrite Cloud',
    blurb: 'Just like a Swiss Army Knife, you can choose and use the tools that you need with Appwrite.',
    name: 'Phil McClusky',
    title: 'Development Lead',
    company: 'Majik Kids',
    avatar: '/images/testimonials/majik.avif',
    storyUrl: '/blog/post/customer-stories-majik-kids',
  },
  {
    id: 'myshoefitter',
    logo: '/images/logos/trusted-by/myshoefitter.svg',
    logoWidth: 197,
    logoHeight: 32,
    headline: 'mySHOEFITTER sized 12,000+ feet accurately for major EU retailers',
    blurb:
      'The integrated user authentication and the ease of creating data structures have undoubtedly saved us several weeks’ worth of time.',
    name: 'Marius Bolik',
    title: 'CTO',
    company: 'mySHOEFITTER',
    avatar: '/images/testimonials/marius-bolik2.avif',
    storyUrl: '/blog/post/customer-stories-myshoefitter',
  },
  {
    id: 'socialaize',
    logo: '/images/logos/trusted-by/socialaize.svg',
    logoWidth: 126,
    logoHeight: 32,
    headline: 'Socialaize runs hundreds of thousands of function executions per day on Cloud',
    blurb: 'It’s especially nice that Appwrite have to deal with the scaling now and not me.',
    name: 'Zach Handley',
    title: 'Founder',
    company: 'Socialaize',
    avatar: '/images/testimonials/zach-handley.avif',
    storyUrl: '/blog/post/customer-story-socialaize',
  },
  {
    id: 'undo',
    logo: '/images/logos/trusted-by/undo.svg',
    logoWidth: 125,
    logoHeight: 32,
    headline: 'UNDO went from idea to paying customers tracking 9,000+ circular assets',
    blurb:
      'Thanks to Appwrite and advances in technology, we were able to get an MVP out in 2/3 months with 1 developer.',
    name: 'Jonas Janssen',
    title: 'Co-founder',
    company: 'UNDO',
    avatar: '/images/testimonials/jonas-janssen.avif',
    storyUrl: '/blog/post/customer-stories-undo',
  },
  {
    id: 'radar',
    logo: '/images/logos/trusted-by/radar.svg',
    logoWidth: 100,
    logoHeight: 32,
    logoSize: 'lg',
    headline: 'Radar shipped a polished iOS hub for every kind of media recommendation',
    blurb: 'The barrier to entry is zero with Appwrite. And I think that’s really special.',
    name: 'Matt Martino',
    title: 'Founder',
    company: 'Paradox',
    avatar: '/images/testimonials/matt-martino.avif',
    storyUrl: '/blog/post/customer-story-radar',
  },
]

const VISIBLE_COUNT = 3

/** Fisher–Yates shuffle (copy). */
export function shuffleHomeCaseStudies<T>(studies: readonly T[]): T[] {
  const out = [...studies]
  for (let i = out.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[out[i], out[j]] = [out[j], out[i]]
  }
  return out
}

export function pickRandomHomeCaseStudies(count = VISIBLE_COUNT): HomeCaseStudy[] {
  return shuffleHomeCaseStudies(allHomeCaseStudies).slice(0, count)
}
