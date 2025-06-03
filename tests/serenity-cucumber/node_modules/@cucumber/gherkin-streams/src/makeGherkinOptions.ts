import { IGherkinOptions } from '@cucumber/gherkin'
import { IdGenerator } from '@cucumber/messages'

const defaultOptions: IGherkinOptions = {
  defaultDialect: 'en',
  includeSource: true,
  includeGherkinDocument: true,
  includePickles: true,
  newId: IdGenerator.uuid(),
}

export default function gherkinOptions(options: IGherkinOptions) {
  return { ...defaultOptions, ...options }
}
