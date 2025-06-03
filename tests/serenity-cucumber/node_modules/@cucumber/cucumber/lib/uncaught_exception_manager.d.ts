/// <reference types="node" />
import UncaughtExceptionListener = NodeJS.UncaughtExceptionListener;
declare const UncaughtExceptionManager: {
    registerHandler(handler: UncaughtExceptionListener): void;
    unregisterHandler(handler: UncaughtExceptionListener): void;
};
export default UncaughtExceptionManager;
