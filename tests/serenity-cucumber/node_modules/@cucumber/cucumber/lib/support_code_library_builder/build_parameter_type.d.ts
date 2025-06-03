import { ParameterType } from '@cucumber/cucumber-expressions';
import { IParameterTypeDefinition } from './types';
export declare function buildParameterType({ name, regexp, transformer, useForSnippets, preferForRegexpMatch, }: IParameterTypeDefinition<any>): ParameterType<any>;
