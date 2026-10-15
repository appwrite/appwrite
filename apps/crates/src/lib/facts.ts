import facts from '../../content/facts.json'

export type Fact = { value: string; label: string; note: string }

export type Facts = {
  updated: string
  summary: string
  sections: { title: string; facts: Fact[] }[]
  timeline: { date: string; title: string; note: string }[]
  lessons: string[]
}

/** The project's facts and figures (content/facts.json), kept by hand as work lands; the source for a write-up of the process. */
export const FACTS = facts as Facts
