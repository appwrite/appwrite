// A plain Express app with no Appwrite code, run as a function with the
// `express` framework: it only has to listen on process.env.PORT.
const express = require('express');

const app = express();
app.use(express.json());
app.use(express.static(`${__dirname}/public`));

// Events and schedules arrive here, told apart by x-appwrite-trigger.
app.post('/', (req, res) => {
  console.log(`trigger=${req.get('x-appwrite-trigger')} event=${req.get('x-appwrite-event')} id=${req.body?.$id ?? ''}`);
  res.sendStatus(200);
});

app.get('/', (req, res) => {
  res.json({ framework: 'express', method: req.method, path: req.path });
});

app.post('/echo', (req, res) => {
  res.status(201).set('x-echo', 'yes').json({ body: req.body, header: req.get('x-test') ?? null });
});

app.get('/env', (req, res) => {
  console.log('express log line');
  res.json({ functionId: process.env.APPWRITE_FUNCTION_ID ?? null, port: process.env.PORT ?? null });
});

app.listen(process.env.PORT);
