import validate from 'validate-npm-package-name';

import type { ClassDescription } from '../../config';
import { ConfigurationError } from '../../errors';
import { d } from '../format';
import type { ClassDescriptor } from './ClassDescriptor';

export class ClassDescriptionParser {
    private static validClassNamePattern = /^[A-Za-z]\w+$/;

    looksLikeClassDescription(candidate: unknown): candidate is ClassDescription {
        const stringDescription = typeof candidate === 'string';
        const tupleDescription  = Array.isArray(candidate) && [ 1, 2 ].includes(candidate.length) && typeof candidate[0] === 'string';

        return stringDescription || tupleDescription;
    }

    parse(classDescription: ClassDescription): ClassDescriptor {
        if (! this.looksLikeClassDescription(classDescription)) {
            throw new ConfigurationError(d`${ classDescription } is not a valid class description. Valid class description must be a string or an Array with 1-2 items, where the first item is a string.`);
        }

        const moduleIdAndClassDescription = Array.isArray(classDescription)
            ? classDescription[0]
            : classDescription as string;

        const [ moduleId, desiredClassName, ...rest ] = moduleIdAndClassDescription.split(':');

        if (rest.length > 0) {
            throw new ConfigurationError(
                `${ classDescription } is not a valid class description. Valid class description must be:\n` +
                `- a module ID of a Node module providing a default export, e.g. "@serenity-js/serenity-bdd"\n` +
                `- a module ID followed by a class name, e.g. "@serenity-js/core:StreamReporter"`
            );
        }

        if (desiredClassName === '') {
            throw new ConfigurationError(
                `Invalid class name in "${ moduleIdAndClassDescription }". If you want to import the default export from a given module, please use the module ID.\n` +
                `For example, valid class descriptions include "@serenity-js/serenity-bdd", "@serenity-js/serenity-bdd:default", "@serenity-js/serenity-bdd:SerenityBDD".`
            );
        }

        const className = desiredClassName ?? 'default';

        if (! ClassDescriptionParser.validClassNamePattern.test(className)) {
            throw new ConfigurationError(
                `"${className}" doesn't seem like a valid JavaScript class name in "${ moduleIdAndClassDescription }"`
            );
        }

        if (! moduleId.startsWith('./')) {
            const result = validate(moduleId);
            if (! result.validForNewPackages) {
                throw new ConfigurationError(
                    `"${ moduleId }" doesn't seem like a valid module id:\n${ result.errors.map(error => `- ${error}\n`) }`
                );
            }
        }

        return {
            moduleId,
            className,
            parameter: Array.isArray(classDescription) ? classDescription[1] : undefined,
        }
    }
}
