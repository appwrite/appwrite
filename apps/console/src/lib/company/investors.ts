export type VentureInvestor = {
  name: string
  href: string
  logoSrc: string
}

export type AngelInvestor = {
  name: string
  role: string
  organization?: string
  github?: string
  twitter?: string
}

export const ventureInvestors: readonly VentureInvestor[] = [
  {
    name: 'Ibex Investors',
    href: 'https://www.ibexinvestors.com/',
    logoSrc: '/images/investors/light/ibex.svg',
  },
  {
    name: 'Tiger Global',
    href: 'https://www.tigerglobal.com/',
    logoSrc: '/images/investors/light/tiger-global.svg',
  },
  {
    name: 'Bessemer Venture Partners',
    href: 'https://www.bvp.com/',
    logoSrc: '/images/investors/light/bessemer.svg',
  },
  {
    name: 'Flybridge',
    href: 'https://www.flybridge.com/',
    logoSrc: '/images/investors/light/flybridge.svg',
  },
  {
    name: 'Seedcamp',
    href: 'https://seedcamp.com/',
    logoSrc: '/images/investors/light/seedcamp.svg',
  },
]

export const angelInvestors: readonly AngelInvestor[] = [
  {
    name: 'Aaron Applebaum',
    role: 'Partner',
    organization: 'MizMaa',
    github: 'https://github.com/aapplbaum',
    twitter: 'https://twitter.com/aapplbaum',
  },
  {
    name: 'Ariel Maislos',
    role: 'Angel Investor',
    organization: 'Former Apple IL CEO',
    github: 'https://github.com/arielmaislos',
    twitter: 'https://twitter.com/arielmaislos',
  },
  {
    name: 'Gilad Engel',
    role: 'Angel Investor',
  },
  {
    name: 'Krishna Visvanathan',
    role: 'Co-founder & Partner',
    organization: 'Crane Venture Partners',
    github: 'https://github.com/KVCVP',
  },
  {
    name: 'Ameet Patel',
    role: 'Angel Investor',
    github: 'https://github.com/ameet-patel',
  },
  {
    name: 'Benno Jering',
    role: 'Partner',
    organization: 'Redline Capital',
    github: 'https://github.com/bennojering/',
  },
  {
    name: 'James Lindenbaum',
    role: 'Co-founder',
    organization: 'Heroku',
    github: 'https://github.com/jnl',
  },
  {
    name: 'Uri Boness',
    role: 'Co-Founder',
    organization: 'Elastic',
    twitter: 'https://twitter.com/uboness',
  },
]
