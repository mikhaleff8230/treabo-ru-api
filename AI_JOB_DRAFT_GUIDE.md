# Treabo AI Job Draft Guide

## Назначение

AI job draft — это backend-функция, которая превращает свободный текст клиента в структурированный черновик заявки для мастеров.

Пример текста клиента:

```text
надо сделать ванну плитка кишинев срочно
```

AI не публикует заявку автоматически. Он только готовит черновик, который позже можно показать пользователю, уточнить через дополнительные вопросы и сохранить как полноценное задание.

## Где находится код

Основные файлы:

```text
config/services.php
app/Services/Ai/JobDraftAiService.php
app/Services/Ai/JobDraftAiException.php
app/Http/Controllers/Api/AiJobDraftController.php
app/Http/Requests/GenerateJobDraftRequest.php
app/Models/AiJobDraft.php
database/migrations/2026_06_08_000001_create_ai_job_drafts_table.php
routes/proffi.php
```

Endpoint:

```text
POST /api/ai/job-draft
```

Маршрут подключен в `routes/proffi.php` и получает общий префикс `/api`, потому что файл подключается из `routes/api.php`.

## Настройка OpenAI

Переменные окружения:

```env
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o-mini
```

Для Docker-окружения Treabo ключ нужно добавить в:

```text
C:\Proffi\project\pixer-api\.env.proffi-docker
```

Для обычного Laravel-окружения:

```text
C:\Proffi\project\pixer-api\.env
```

После изменения `.env.proffi-docker` нужно пересоздать app-контейнер:

```bash
docker compose -f docker-compose.proffi.yml up -d --force-recreate app
```

Если конфиг Laravel был закеширован:

```bash
docker compose -f docker-compose.proffi.yml exec app php artisan config:clear
```

## Request

```http
POST /api/ai/job-draft
Content-Type: application/json
```

Body:

```json
{
  "text": "надо ванну сделать плитка кишинев срочно",
  "city_hint": "Chișinău",
  "category_hint": null,
  "language_hint": "auto"
}
```

Поля:

```text
text          required string min:5 max:3000
city_hint     nullable string max:100
category_hint nullable string max:100
language_hint nullable auto|ru|ro
```

## Success Response

```json
{
  "success": true,
  "data": {
    "detected_language": "ru",
    "title": "Ремонт ванной комнаты",
    "category_slug": "bathroom-renovation",
    "city": "Chișinău",
    "urgency": "urgent",
    "description": "Нужно выполнить ремонт ванной комнаты. Требуется укладка плитки. Клиент хочет начать как можно скорее. Нужен осмотр и оценка стоимости.",
    "master_summary": "Ванная, плитка, срочно, Chișinău. Нужен осмотр и расчет стоимости.",
    "missing_questions": [
      "Какая площадь ванной комнаты?",
      "Нужен ли демонтаж старой плитки?",
      "Есть ли фото помещения?",
      "Материалы уже куплены?"
    ],
    "confidence": 0.82
  }
}
```

## Error Response

```json
{
  "success": false,
  "message": "Не удалось сформировать заявку"
}
```

Ошибки OpenAI и ошибки парсинга JSON логируются в Laravel log:

```text
storage/logs/laravel.log
```

## Допустимые значения

`detected_language`:

```text
ru
ro
mixed
unknown
```

`urgency`:

```text
urgent
this_week
this_month
flexible
unknown
```

`category_slug`:

```text
bathroom-renovation
tile-work
plumbing
electrical
air-conditioners
other
```

Если AI вернет неизвестное значение, сервис нормализует его в безопасное значение:

```text
detected_language -> unknown
urgency -> unknown
category_slug -> other
```

## Логирование

Каждый запрос сохраняется в таблицу:

```text
ai_job_drafts
```

Поля:

```text
id
user_id
raw_text
request_payload
response_payload
model
status
error_message
tokens_used
created_at
updated_at
```

Статусы:

```text
success
failed
```

Если пользователь авторизован через Sanctum bearer token, backend попробует сохранить `user_id`.

## Throttle

На endpoint добавлен лимит:

```text
throttle:10,1
```

Это означает максимум 10 запросов в минуту на пользователя или IP.

## Проверка через curl

```bash
curl -X POST http://127.0.0.1:8001/api/ai/job-draft \
  -H "Content-Type: application/json" \
  -d "{\"text\":\"надо сделать ванну плитка кишинев срочно\",\"city_hint\":\"Chișinău\",\"category_hint\":null,\"language_hint\":\"auto\"}"
```

## Проверка через PowerShell

```powershell
$body = @{
  text = "надо сделать ванну плитка кишинев срочно"
  city_hint = "Chișinău"
  category_hint = $null
  language_hint = "auto"
} | ConvertTo-Json

Invoke-RestMethod `
  -Uri "http://127.0.0.1:8001/api/ai/job-draft" `
  -Method Post `
  -ContentType "application/json" `
  -Body $body
```

## Команды обслуживания

Миграции:

```bash
docker compose -f docker-compose.proffi.yml run --rm app php artisan migrate
```

Проверка маршрута:

```bash
docker compose -f docker-compose.proffi.yml run --rm app php artisan route:list --path=ai/job-draft
```

Очистка конфига:

```bash
docker compose -f docker-compose.proffi.yml exec app php artisan config:clear
```

Просмотр последних логов:

```bash
docker compose -f docker-compose.proffi.yml exec app tail -n 100 storage/logs/laravel.log
```

## Как подключать фронт позже

1. Пользователь вводит свободный текст на странице создания заявки.
2. Frontend отправляет текст в `POST /api/ai/job-draft`.
3. Backend возвращает структурированный черновик.
4. Frontend показывает:
   - заголовок;
   - категорию;
   - город;
   - срочность;
   - описание;
   - вопросы из `missing_questions`.
5. Пользователь отвечает на уточняющие вопросы.
6. После подтверждения frontend вызывает отдельный endpoint создания полноценной заявки.

Важно: текущий endpoint не должен создавать задание. Он только готовит AI-черновик.
