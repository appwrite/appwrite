"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.PluginManager = void 0;
class PluginManager {
    constructor(pluginFns) {
        this.pluginFns = pluginFns;
        this.handlers = { message: [] };
        this.cleanupFns = [];
    }
    async register(event, handler) {
        this.handlers[event].push(handler);
    }
    async init(logger, configuration, environment) {
        for (const pluginFn of this.pluginFns) {
            const cleanupFn = await pluginFn({
                on: this.register.bind(this),
                logger,
                configuration,
                environment,
            });
            if (cleanupFn) {
                this.cleanupFns.push(cleanupFn);
            }
        }
    }
    emit(event, value) {
        this.handlers[event].forEach((handler) => handler(value));
    }
    async cleanup() {
        for (const cleanupFn of this.cleanupFns) {
            await cleanupFn();
        }
    }
}
exports.PluginManager = PluginManager;
//# sourceMappingURL=plugin_manager.js.map