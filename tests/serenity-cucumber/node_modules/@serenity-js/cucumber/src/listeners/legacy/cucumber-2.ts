import type { Dependencies } from './Dependencies';

// eslint-disable-next-line @typescript-eslint/explicit-module-boundary-types
export = function ({ serenity, notifier, resultMapper, loader, cucumber, cache }: Dependencies) {
    const adapter = require('./cucumber-0');    // eslint-disable-line  @typescript-eslint/no-var-requires

    cucumber.defineSupportCode(support =>
        adapter({ serenity, notifier, resultMapper, loader, cucumber, cache }).call(support)
    );

    return function (): void {
        // no-op
    };
};
