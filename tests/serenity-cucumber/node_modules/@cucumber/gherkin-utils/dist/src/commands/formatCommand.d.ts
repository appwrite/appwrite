/// <reference types="node" />
import { Syntax } from '../pretty';
import { Readable, Writable } from 'stream';
export type FormatOptions = {
    fromSyntax?: Syntax;
    toSyntax?: Syntax;
    language?: string;
};
export declare function formatCommand(files: string[], stdin: Readable | null, stdout: Writable | null, options: FormatOptions): Promise<void>;
//# sourceMappingURL=formatCommand.d.ts.map