import * as messages from '@cucumber/messages'

export type GherkinDocumentHandlers<Acc> = {
  feature: (feature: messages.Feature, acc: Acc) => Acc
  background: (backgrounf: messages.Background, acc: Acc) => Acc
  rule: (rule: messages.Rule, acc: Acc) => Acc
  scenario: (scenario: messages.Scenario, acc: Acc) => Acc
  step: (step: messages.Step, acc: Acc) => Acc
  examples: (examples: messages.Examples, acc: Acc) => Acc
  tag: (tag: messages.Tag, acc: Acc) => Acc
  comment: (comment: messages.Comment, acc: Acc) => Acc
  dataTable: (dataTable: messages.DataTable, acc: Acc) => Acc
  tableRow: (tableRow: messages.TableRow, acc: Acc) => Acc
  tableCell: (tableCell: messages.TableCell, acc: Acc) => Acc
  docString: (docString: messages.DocString, acc: Acc) => Acc
}
