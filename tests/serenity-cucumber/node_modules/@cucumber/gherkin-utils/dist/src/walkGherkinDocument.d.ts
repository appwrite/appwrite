import * as messages from '@cucumber/messages';
import { GherkinDocumentHandlers } from './GherkinDocumentHandlers';
/**
 * Walks a Gherkin Document, visiting each node depth first (in the order they appear in the source)
 *
 * @param gherkinDocument
 * @param initialValue the initial value of the traversal
 * @param handlers handlers for each node type, which may return a new value
 * @return result the final value
 */
export declare function walkGherkinDocument<Acc>(gherkinDocument: messages.GherkinDocument, initialValue: Acc, handlers: Partial<GherkinDocumentHandlers<Acc>>): Acc;
//# sourceMappingURL=walkGherkinDocument.d.ts.map