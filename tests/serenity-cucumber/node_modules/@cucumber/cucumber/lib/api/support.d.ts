import { IdGenerator } from '@cucumber/messages';
import { ISupportCodeLibrary } from '../support_code_library_builder/types';
export declare function getSupportCodeLibrary({ cwd, newId, requireModules, requirePaths, importPaths, }: {
    cwd: string;
    newId: IdGenerator.NewId;
    requireModules: string[];
    requirePaths: string[];
    importPaths: string[];
}): Promise<ISupportCodeLibrary>;
