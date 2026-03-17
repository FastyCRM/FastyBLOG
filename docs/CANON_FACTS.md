# CANON_FACTS

Короткий список правил. Полная версия — в `CANON.md`.

## 1) Где разрешена логика
- Логика — только в `assets/php/*.php` и `*_lib.php`.
- VIEW (`/adm/modules/<module>/<module>.php`) — только UI.
- `settings.php` — только константы/параметры, без логики.

## 2) Роутинг
- Только через `/adm/index.php?m=<module>&do=<action>`.
- Внутренний API: `/core/internal_api.php?m=<module>&do=api_*`.
- Прямой доступ к action‑файлам запрещён.

## 3) Настройки модулей
- Настройки открываются только кнопкой‑шестернёй.
- Вся работа с настройками — через `do=modal_settings`.

## 4) Кодировка
- Все файлы UTF‑8 без BOM.
- CP1251 запрещена во всех файлах проекта.
- Обязательная проверка перед сдачей: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/check-encoding.ps1`.
- Любые ошибки BOM/CP1251/mojibake нужно исправить до релиза.
- Комментарии — на русском.

## 5) Безопасность
- Каждый action: `acl_guard()` + `csrf_check()`.
- Никаких прямых SQL без подготовленных запросов.

## 6) Логирование
- Все события через `audit_log()`.
- Двойная запись: БД + `logs/audit-fallback.log`.

## 7) UI
- Использовать существующие компоненты/классы `adm/view/assets`.
- По умолчанию использовать только глобальные стили; модульный CSS — только в крайнем случае и по согласованию.
- Новых дизайн‑паттернов не вводить.
- Все новые модули верстать на 12-колоночной сетке `main_grid.css` (`.l-row`, `.l-col--*`).
- Локальные классы модуля допустимы только вместе с `.l-row/.l-col--*`, а не вместо них.
- Через 12-колоночную сетку раскладывать и формы в модалках.
- В desktop/tablet таблица должна растягиваться на ширину контейнера и сужаться без обязательного горизонтального скролла.
- Для адаптивных таблиц использовать `table-wrap table-wrap--compact-grid` + `table table--compact-grid` (без `table--modules`).
- Иконки действий в таблицах — `icon-only`, без декоративной рамки/плашки вокруг.
- В compact режиме таблиц (`<=600px`) не допускается горизонтальный скролл.
- В compact режиме таблица раскладывается в grid: 2 колонки (и 1 колонка на очень узких экранах).
- В таблицах `ID` — техническая колонка (скрывать в compact режиме).
- Если в таблице дата-время, в compact режиме показывать только дату (`dd.mm.yyyy`).
- Кнопка добавления (`+`) в шапке модуля всегда справа.
- Dropdown/select/suggest в формах должны открываться поверх формы (не проваливаться под поля).
- Для форм в модалках/скролл-контейнерах использовать scope `data-ui-select-scope="1"`.
- По умолчанию в формах использовать нативный `.select`; `data-ui-select="1"` только в крайнем случае и по согласованию.
- Системная модалка `App.modal` запрещена без отдельного согласования.
- Эталон: `adm/modules/dashboard/dashboard.php`.

## 8) Календарь
- Режим календаря — персональный (`calendar_user_settings`).
- Глобальные `calendar_*` в `requests_settings` — legacy.

## 9) SQL миграции
- Любые `CREATE/ALTER/DROP` выполняются только вручную SQL-скриптами.
- Runtime-код модулей и core не должен автосоздавать/автоизменять схему БД.
- Для новых таблиц добавлять отдельный SQL-файл в модуль или `docs/sql/`.

## 10) I18N
- Required dictionary layout: lang/common plus adm/modules/<module>/lang/{ru,en}.php.
- All user-facing module strings must use t('...').
- Keys must be namespaced: common.* and <module>.*.
- A new module without ru/en dictionaries is non-canonical.
- Migration order and status: docs/I18N_MODULES_ROADMAP.md.