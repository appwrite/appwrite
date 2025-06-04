import * as messages from '@cucumber/messages';
export type Syntax = 'markdown' | 'gherkin';
export default function pretty(gherkinDocument: messages.GherkinDocument, syntax?: Syntax): string;
export declare function escapeCell(s: string): string;
//# sourceMappingURL=pretty.d.ts.map