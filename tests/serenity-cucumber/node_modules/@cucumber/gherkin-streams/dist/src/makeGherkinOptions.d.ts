import { IGherkinOptions } from '@cucumber/gherkin';
import { IdGenerator } from '@cucumber/messages';
export default function gherkinOptions(options: IGherkinOptions): {
    defaultDialect?: string;
    includeSource?: boolean;
    includeGherkinDocument?: boolean;
    includePickles?: boolean;
    newId?: IdGenerator.NewId;
};
//# sourceMappingURL=makeGherkinOptions.d.ts.map