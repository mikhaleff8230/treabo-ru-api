import http from 'node:http';
import https from 'node:https';
import dns from 'node:dns';

dns.setDefaultResultOrder('ipv4first');

const forwardedHeaders = new Set([
  'accept',
  'authorization',
  'content-type',
  'openai-organization',
  'openai-project',
]);

const server = http.createServer((request, response) => {
  const headers = {};

  for (const [name, value] of Object.entries(request.headers)) {
    if (forwardedHeaders.has(name) && value !== undefined) {
      headers[name] = value;
    }
  }

  headers.host = 'api.openai.com';
  headers['user-agent'] = 'treabo-openai-relay/1.0';

  const upstream = https.request({
    hostname: 'api.openai.com',
    port: 443,
    family: 4,
    method: request.method,
    path: request.url,
    headers,
  }, (upstreamResponse) => {
    response.writeHead(upstreamResponse.statusCode ?? 502, upstreamResponse.headers);
    upstreamResponse.pipe(response);
  });

  upstream.on('error', (error) => {
    if (!response.headersSent) {
      response.writeHead(502, { 'content-type': 'application/json' });
    }
    response.end(JSON.stringify({ error: { message: 'OpenAI upstream unavailable' } }));
    console.error(error.message);
  });

  request.pipe(upstream);
});

server.listen(8080, '0.0.0.0');
