import './styles.scss';
import * as messages from '@cucumber/messages';
declare global {
    interface Window {
        CUCUMBER_MESSAGES: messages.Envelope[];
    }
}
//# sourceMappingURL=main.d.ts.map