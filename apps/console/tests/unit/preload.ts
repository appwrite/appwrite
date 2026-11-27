// React DOM keeps the queueMicrotask and setTimeout it finds when it first
// loads. Loaded here, those are the runtime's; loaded inside a test file, they
// belong to that file's happy-dom window and stop running once it is closed.
import 'react-dom/client'
