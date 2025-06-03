import { serenity } from '@serenity-js/core';
import { ModuleLoader, Path, Version } from '@serenity-js/core/lib/io';
import * as process from 'process';

const cwd = process.cwd();
const loader = new ModuleLoader(cwd);

const version = loader.hasAvailable('@cucumber/cucumber')
    ? loader.versionOf('@cucumber/cucumber')
    : loader.versionOf('cucumber');

/**
 * Registers a Cucumber reporter that emits [Serenity/JS domain events](https://serenity-js.org/api/core-events/class/DomainEvent/)
 * and informs Serenity/JS when test scenarios and Cucumber steps start, finish, and with what result.
 */
const listener: unknown = version.isAtLeast(new Version('7.0.0'))
    ? require('./listeners/messages').createListener(serenity, loader)  // eslint-disable-line @typescript-eslint/no-var-requires
    : require('./listeners/legacy').createListener(serenity, loader, Path.from(cwd))    // eslint-disable-line @typescript-eslint/no-var-requires

export = listener;
