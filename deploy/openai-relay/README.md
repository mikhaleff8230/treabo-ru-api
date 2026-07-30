# Treabo OpenAI relay

The relay forwards OpenAI API requests from the Treabo production server while
keeping the original `Authorization` header. Public access is denied by source
IP in `Caddyfile`.

Deploy on the relay server:

```bash
docker compose up -d
docker compose logs --tail=100
```

Treabo API environment:

```dotenv
OPENAI_BASE_URL=https://ai.80-190-82-113.sslip.io/v1
```
