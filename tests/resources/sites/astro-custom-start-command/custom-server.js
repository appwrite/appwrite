import express from 'express';
import { handler as ssrHandler } from './server/entry.mjs';

const app = express();
const base = '/';
app.use(base, express.static('client/'));
app.get('/ssr-custom', (_req, res) => {
  res.send('Custom SSR OK (' + Date.now() + ')');
});
app.use(ssrHandler);

app.use((_req, res) => {
  res.status(500).send('Custom error');
});

app.listen(3000);