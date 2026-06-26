# Справочник населённых пунктов России

Основной источник — открытые выгрузки [GeoNames RU](https://download.geonames.org/export/dump/RU.zip) и [русские альтернативные названия](https://download.geonames.org/export/dump/alternatenames/RU.zip), лицензия [CC BY 4.0](https://www.geonames.org/). Вторая выгрузка нужна, чтобы сохранять именно русские официальные названия, а не латинскую транслитерацию. Поиск работает по собственной таблице `russia_locations`; DaData вызывается только тогда, когда локальная база не дала результата.

## Установка и обновление

```bash
php artisan migrate --force
php artisan locations:import-russia --truncate
```

По умолчанию импортируются населённые пункты с населением от 500 человек и все административные центры — около 5 тысяч записей. Это быстрый и компактный справочник для формы выбора города.

Другой порог:

```bash
php artisan locations:import-russia --min-population=100
```

Полная выгрузка (более 200 тысяч населённых пунктов, заметно более тяжёлый поиск и база):

```bash
php artisan locations:import-russia --all --truncate
```

Команда также принимает локальный `RU.zip` или распакованный `RU.txt` первым аргументом. Локальную выгрузку русских названий передайте отдельно:

```bash
php artisan locations:import-russia /data/RU.zip \
  --alternate-source=/data/alternate-names-RU.zip \
  --truncate
```

Это позволяет обновлять production без прямого доступа сервера в интернет.

## API

`GET /api/treabo/locations/russia/search?q=Москва&limit=12`

Также доступен нейтральный alias: `GET /api/treabo/locations/search`.

Переменная `DADATA_CITY_FALLBACK_ENABLED=false` полностью отключает резервный поиск DaData. Определение города по IP продолжает работать через существующий GeoIP/DaData/MaxMind механизм и не расходует запросы при обычном поиске по локальному справочнику.
