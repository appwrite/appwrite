import type { Serenity } from '@serenity-js/core';
import type { ModuleLoader } from '@serenity-js/core/lib/io';
import cucumberMessagesListener = require('./CucumberMessagesListener');

/**
 * Creates a listener for Cucumber.js v7.x and above
 */
export function createListener(serenity: Serenity, moduleLoader: ModuleLoader): any {
    return cucumberMessagesListener(
        serenity,
        moduleLoader,
    );
}
