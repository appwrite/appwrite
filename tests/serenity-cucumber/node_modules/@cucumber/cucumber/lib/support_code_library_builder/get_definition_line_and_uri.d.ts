import { isFileNameInCucumber } from '../filter_stack_trace';
import { ILineAndUri } from '../types';
export declare function getDefinitionLineAndUri(cwd: string, isExcluded?: typeof isFileNameInCucumber): ILineAndUri;
