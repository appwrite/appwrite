import type { ClassDescription } from '../../config';
import { ConfigurationError } from '../../errors';
import { d } from '../format';
import type { ClassDescriptionParser } from './ClassDescriptionParser';
import type { ModuleLoader } from './ModuleLoader';

export class ClassLoader {
    constructor(
        private readonly loader: ModuleLoader,
        private readonly parser: ClassDescriptionParser,
    ) {
    }

    looksLoadable(description: unknown): description is ClassDescription {
        return this.parser.looksLikeClassDescription(description);
    }

    instantiate<T>(description: ClassDescription): T {
        const descriptor = this.parser.parse(description);

        const requiredModule    = this.loader.require(descriptor.moduleId);
        const requiredType      = requiredModule[descriptor.className];

        if (typeof requiredType !== 'function') {
            throw new ConfigurationError(d `Module ${ descriptor.moduleId } doesn't seem to export ${ descriptor.className }. Exported members include: ${ Object.keys(requiredModule).join(', ') }`)
        }

        const needsParameter = Boolean(descriptor.parameter);

        if (needsParameter && typeof requiredType.fromJSON === 'function') {
            return this.ensureDefined(requiredType.fromJSON(descriptor.parameter), `${ requiredType }.fromJSON(${ descriptor.parameter })`);
        }

        if (needsParameter && requiredType.length > 1) {
            throw new ConfigurationError(d`${ descriptor.className } exported by ${ descriptor.moduleId } must be a class with a static fromJSON(config) method or a function that accepts a single config parameter: ${ descriptor.parameter }`);
        }

        if (! needsParameter && requiredType.length > 0) {
            throw new ConfigurationError(d`${ descriptor.className } exported by ${ descriptor.moduleId } must be a parameterless function since no config parameter is specified`);
        }

        try {
            return this.ensureDefined(
                requiredType(descriptor.parameter),
                `${ requiredType }(${ descriptor.parameter })`
            );
        }
        catch (error) {

            if (error instanceof TypeError && error.message.includes('constructor')) {
                return new requiredType(descriptor.parameter);
            }

            const errorMessage = [
                d`${ descriptor.className } exported by ${ descriptor.moduleId } must be either:`,
                descriptor.parameter && d`- a class with a static fromJSON(config) method`,
                descriptor.parameter ? '- a no-arg constructor function' : '- a constructor function accepting config',
                descriptor.parameter ? '- a no-arg function' : '- a function accepting config',
                descriptor.parameter && d`where config is: ${ descriptor.parameter }`,
            ].filter(Boolean).join('\n');

            throw new ConfigurationError(errorMessage);
        }
    }

    private ensureDefined<T>(value: T | undefined, operation: string): T {
        if (value === undefined || value === null) {
            throw new ConfigurationError(`Calling ${ operation } produced ${ value }, which might indicate a configuration or programming error`);
        }

        return value;
    }
}
