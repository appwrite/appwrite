import { ParameterType, ParameterTypeRegistry } from '@cucumber/cucumber-expressions';
import { ILineAndUri } from '../types';
export declare class SourcedParameterTypeRegistry extends ParameterTypeRegistry {
    private parameterTypeToSource;
    defineSourcedParameterType(parameterType: ParameterType<unknown>, source: ILineAndUri): void;
    lookupSource(parameterType: ParameterType<unknown>): ILineAndUri;
}
