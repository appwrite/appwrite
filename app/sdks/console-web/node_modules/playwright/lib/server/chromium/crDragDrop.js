"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.DragManager = void 0;
const utils_1 = require("../../utils/utils");
const crProtocolHelper_1 = require("./crProtocolHelper");
class DragManager {
    constructor(page) {
        this._dragState = null;
        this._lastPosition = { x: 0, y: 0 };
        this._crPage = page;
    }
    async cancelDrag() {
        if (!this._dragState)
            return false;
        await this._crPage._mainFrameSession._client.send('Input.dispatchDragEvent', {
            type: 'dragCancel',
            x: this._lastPosition.x,
            y: this._lastPosition.y,
            data: {
                items: [],
                dragOperationsMask: 0xFFFF,
            }
        });
        this._dragState = null;
        return true;
    }
    async interceptDragCausedByMove(x, y, button, buttons, modifiers, moveCallback) {
        this._lastPosition = { x, y };
        if (this._dragState) {
            await this._crPage._mainFrameSession._client.send('Input.dispatchDragEvent', {
                type: 'dragOver',
                x,
                y,
                data: this._dragState,
                modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
            });
            return;
        }
        if (button !== 'left')
            return moveCallback();
        const client = this._crPage._mainFrameSession._client;
        let onDragIntercepted;
        const dragInterceptedPromise = new Promise(x => onDragIntercepted = x);
        await Promise.all(this._crPage._page.frames().map(async (frame) => {
            await frame.nonStallingEvaluateInExistingContext((function () {
                let didStartDrag = Promise.resolve(false);
                let dragEvent = null;
                const dragListener = (event) => dragEvent = event;
                const mouseListener = () => {
                    didStartDrag = new Promise(callback => {
                        window.addEventListener('dragstart', dragListener, { once: true, capture: true });
                        setTimeout(() => callback(dragEvent ? !dragEvent.defaultPrevented : false), 0);
                    });
                };
                window.addEventListener('mousemove', mouseListener, { once: true, capture: true });
                window.__cleanupDrag = async () => {
                    const val = await didStartDrag;
                    window.removeEventListener('mousemove', mouseListener, { capture: true });
                    window.removeEventListener('dragstart', dragListener, { capture: true });
                    return val;
                };
            }).toString(), true, 'utility').catch(() => { });
        }));
        client.on('Input.dragIntercepted', onDragIntercepted);
        try {
            await client.send('Input.setInterceptDrags', { enabled: true });
        }
        catch {
            // If Input.setInterceptDrags is not supported, just do a regular move.
            // This can be removed once we stop supporting old Electron.
            client.off('Input.dragIntercepted', onDragIntercepted);
            return moveCallback();
        }
        await moveCallback();
        const expectingDrag = (await Promise.all(this._crPage._page.frames().map(async (frame) => {
            return frame.nonStallingEvaluateInExistingContext('window.__cleanupDrag && window.__cleanupDrag()', false, 'utility').catch(() => false);
        }))).some(x => x);
        this._dragState = expectingDrag ? (await dragInterceptedPromise).data : null;
        client.off('Input.dragIntercepted', onDragIntercepted);
        await client.send('Input.setInterceptDrags', { enabled: false });
        if (this._dragState) {
            await this._crPage._mainFrameSession._client.send('Input.dispatchDragEvent', {
                type: 'dragEnter',
                x,
                y,
                data: this._dragState,
                modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
            });
        }
    }
    isDragging() {
        return !!this._dragState;
    }
    async drop(x, y, modifiers) {
        utils_1.assert(this._dragState, 'missing drag state');
        await this._crPage._mainFrameSession._client.send('Input.dispatchDragEvent', {
            type: 'drop',
            x,
            y,
            data: this._dragState,
            modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
        });
        this._dragState = null;
    }
}
exports.DragManager = DragManager;
//# sourceMappingURL=crDragDrop.js.map