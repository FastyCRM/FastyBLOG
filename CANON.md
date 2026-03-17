Комменты только на русском и комментируем каждую функцию и переменные при создании
Все файлы проекта — UTF-8 без BOM (никакого BOM в начале файла)

Мини-канон по версиям (github)

  версия живёт в теге

  коммит может ссылаться на версию

  не переписываем старые коммиты ради красоты


PHP ≥ 7.4
MySQL = 8.0.24 (фиксированная версия канона для SQL-синтаксиса)
В MySQL 8.0.24 не использовать `ADD COLUMN IF NOT EXISTS` (делаем обычный `ADD COLUMN` + проверку через information_schema при необходимости)
DDL/миграции фиксируются в SQL-файлах модуля (`install.sql`, при необходимости `update.sql`) и остаются источником истины по схеме.
В runtime-коде (`core/*`, `adm/modules/*`) запрещены авто-миграции: нельзя молча менять схему БД при обычной работе модуля.
Разрешён только явный install-поток из настроек модуля: кнопка `Создать БД`, запускаемая вручную администратором.
Скрипт install-потока обязан сначала проверить существование таблиц модуля (через `information_schema`), затем создать только отсутствующие таблицы.
`DROP` и любое разрушение схемы в install-потоке запрещены.
SQL compatibility (host + local) is mandatory.
In GROUP BY queries do not use ORDER BY/HAVING by aggregate alias from the same SELECT level.
Use ORDER BY MAX(r.visit_at) DESC or sort by alias only in an outer subquery.
Do not use ORDER BY last_visit_at when last_visit_at = MAX(...) in the same SELECT.
Every new API search/list must pass a smoke test on host before release.
Процедурный стиль
Функции вместо классов
Обратная совместимость с PHP 8.x
Без фреймворков

1. ФИЛОСОФИЯ СИСТЕМЫ

CRM2026 — не playground.

Ключевые слова:

минимум

ясность

контроль

предсказуемость

Запрещено:

«потом улучшим»

скрытые зависимости

догадки

абстрактная архитектура без необходимости

2. ИСТОЧНИКИ ИСТИНЫ

БД — данные, ACL, меню, enabled

settings.php — параметры модуля

adm — shell

module — автономная бизнес-единица

3. ЕДИНЫЙ ПАТТЕРН (adm = module)

Админка и модули подчиняются одной структуре.

/<area>/
  index.php
  /assets/
    /php/
      main.php
      *.php
    /js/
      main.js
      *.js
    /css/
      main.css
      *.css


<area>:

adm

modules/<module_code>

4. adm — ИНФРАСТРУКТУРНЫЙ SHELL

/adm/index.php — единственная точка входа

Он:

подключает bootstrap

восстанавливает сессию

проверяет авторизацию

проверяет ACL

читает m и do

передаёт управление

❌ Не содержит бизнес-логики
❌ Не знает, как устроены модули

5. ЕДИНЫЙ РОУТИНГ (URL-КАНОН)
/adm/index.php?m=<module>&do=<action>


m — код модуля

do — действие

Типы do:

view (по умолчанию)

add / update / del / toggle

modal_*

api_*

install_db (ручной init таблиц модуля из настроек)

5.1 ВНУТРЕННИЙ API-КОНТУР

Точка входа:
/core/internal_api.php?m=<module>&do=api_*

Правила:
— только do=api_*
— вся логика остаётся в модуле
— internal_api.php лишь маршрутизирует через assets/php/main.php
— допускается использовать для site (внутрисистемный контур)

6. ЖЁСТКИЙ ПОТОК ВЫПОЛНЕНИЯ
adm/index.php
  ↓
adm/assets/php/main.php
  ↓
modules/<module>/assets/php/main.php
  ↓
modules/<module>/assets/php/<module>_<do>.php


❗ ВСЕГДА через main.php
❗ Прямой доступ к action-файлам запрещён

7. СТРУКТУРА МОДУЛЯ (ФИНАЛ)
/modules/<module_code>/
  <module_code>.php        // VIEW (экран модуля)
  settings.php             // паспорт модуля (НЕ логика)
  install.sql              // каноничная схема таблиц модуля
  /assets/
    /php/
      main.php             // приёмщик do
      <module_code>_<do>.php
      <module_code>_install_db.php // do=install_db (ручной init БД)
      <module_code>_api_main.php // приёмщик API (опционально)
      /api/
        <module_code>_api_*.php  // API-экшены (опционально)
    /js/
      main.js
    /css/
      main.css             // опционально



Запрещено:

handler.php

switch в одном файле

бизнес-логика в VIEW

чтение файловой системы вместо БД

8. settings.php (НЕ МЕНЯЕТСЯ)

Обязателен.

Содержит:

константы

параметры

имена таблиц

разрешённые do

внутренние флаги

❌ Не ACL
❌ Не меню
❌ Не логика

9. ФУНКЦИИ (СТРОГИЙ ПРЕФИКС)

Для модуля ord:

ord_add()
ord_del()
ord_update()
ord_toggle()
ord_register_yandex()


❌ Глобальные имена
❌ process / handle / doSomething

10. JS (ЗАФИКСИРОВАНО)

main.js — точка входа

Остальные файлы подключаются только через него

Модуль регистрируется, а не живёт сам

App.modules.ord.init();

11. CSS (ЗАФИКСИРОВАНО)

Вся база — adm/assets/css

Сетка, резина, layout — только там

Модульный CSS — только при необходимости

Модуль не переопределяет дизайн-систему

12. МОДАЛКИ — ИНФРАСТРУКТУРА

Одна глобальная модалка

Живёт в adm/assets

Единый JS

Единый CSS

Модуль:

❌ не создаёт модалку

✔ подсовывает HTML

✔ инициирует открытие

12.1 НАСТРОЙКИ МОДУЛЯ (UI-КАНОН)

Если у модуля есть настройки:

✔ доступ к настройкам только через кнопку‑шестерню

✔ вся работа с настройками — в модалке (modal_settings)

✔ в каждом новом модуле в modal_settings обязательна кнопка `Создать БД`

✔ кнопка `Создать БД` вызывает do=`install_db` (POST + CSRF + ACL)

✔ `install_db` проверяет таблицы модуля в `information_schema` и создаёт только отсутствующие

✔ `install_db` возвращает отчёт: какие таблицы созданы, какие уже существовали

❌ никаких встроенных форм настроек в VIEW

13. БЕЗОПАСНОСТЬ (ЗАФИКСИРОВАНО)

В каждом assets/php/*.php:

if (!defined('ROOT_PATH')) exit;


Каждый action:

auth

ACL

CSRF (если POST)

14. BOOTSTRAP FIRST RULE (КРИТИЧЕСКОЕ)

core/bootstrap.php — единственная точка старта инфраструктуры.

До его подключения ❌ запрещено:

любые хелперы

работа с файлами

работа с URL

доступ к сессии

доступ к БД

Подключение:

только через $_SERVER['DOCUMENT_ROOT']

или абсолютный файловый путь

15. CORE vs MODULE — ПРАВИЛО ОТВЕТСТВЕННОСТИ
CORE — система

Отвечает за:

безопасность

сессии

авторизацию

ACL

аудит

доступ к данным

контроль потока

❌ CORE не знает про UI

Файлы CORE — атомарны:

bootstrap.php
config.php
db.php
session.php
csrf.php
cookies.php
auth.php
acl.php
modules.php
audit.php
response.php


❌ Никаких helpers.php, common.php, security.php

MODULE — интерфейс

Отвечает только за:

экран (VIEW)

сбор ввода

вызов CORE

redirect / вывод результата

❌ Не работает напрямую с users / sessions / roles
❌ Не управляет сессией
❌ Не проверяет CSRF
❌ Не логирует напрямую

16. АВТОРИЗАЦИЯ — ТОЛЬКО CORE

Всё, что связано с:

логином

логаутом

remember

восстановлением сессии

блокировками

ролями

➡️ живёт только в core/auth.php

auth-модуль — форма + кнопки.

17. АУДИТ (LOGGING)

Правило:

любое значимое событие → audit_log()

Поведение:

сначала БД

если БД недоступна → файл

система не падает из-за логирования

18. UI / ВИЗУАЛ — ЖЁСТКИЕ ПРАВИЛА

Разрешённые сущности:

✔ card

✔ modal__panel

Запрещены:

✖ page / main / shell / stack

ТЕНИ И BLUR

❌ Нельзя использовать box-shadow и backdrop-filter вместе.

Выбор:

стекло → blur без тени

карточка → тень без blur

Хочешь «плавающие карточки» → тень, без blur.

19. ЛАКМУС-ТЕСТ

Если возникает вопрос:

«Это логика формы или логика системы?»

система → CORE

интерфейс → MODULE

сомневаешься → CORE

20. ЧЕК-ЛИСТ ПЕРЕД КОММИТОМ

PHP ≥ 8.1

Всё через main.php

Нет прямых вызовов

Нет inline-логики

Нет дублирования UI

Все функции с префиксом

Архитектура объясняется за 1 минуту

🧠 ФИНАЛЬНАЯ ФОРМУЛА (ЗАКРЕПЛЕНИЕ)

CORE — система.
MODULE — интерфейс.
CORE решает, MODULE вызывает.

🔔 21. FLASH — системные оповещения (ЕДИНЫЙ МЕХАНИЗМ)

Flash — единственный допустимый механизм системных сообщений (ошибки, предупреждения, статусы).

21.1 Назначение

Flash используется для:

ошибок авторизации

блокировок (подписка, ACL)

системных уведомлений

подтверждений действий

Flash НЕ используется для:

inline-валидации форм

UI-подсказок

бизнес-логики

21.2 API (PHP)
flash(string $text, string $bg = 'info', int $beep = 0);


Параметры:

$text — текст сообщения

$bg — тип / цвет (danger, warn, ok, info, accent)

$beep — звуковой сигнал (0/1)

Пример:

flash('Подписка не активна', 'danger', 1);
flash('Данные сохранены', 'ok');

21.3 Жизненный цикл flash

flash() кладёт сообщение в сессию

flash_pull() забирает и очищает очередь

Flash передаётся в JS через:

window.__FLASH__ = [...]


main.js:

рендерит всплывающую строку сверху

проигрывает звук (если beep = 1)

автоудаляет сообщение

⚠️ КРИТИЧЕСКОЕ ПРАВИЛО
flash_pull() вызывается СТРОГО ОДИН РАЗ на layout
(отдельно для minimal auth layout и отдельно для full layout)

❌ Запрещено:

вызывать flash_pull() до выбора layout

вызывать flash_pull() в bootstrap / core

читать flash напрямую в модулях

21.4 Flash и layout
Auth (minimal layout)

нет меню

нет topbar

нет pagehead

есть:

<div class="flashbar" id="flashbar"></div>

Full layout

flashbar подключается внизу body

сообщения одинаково работают везде

21.5 Звуки flash

Звуки:

генерируются синтезом (WebAudio)

без mp3 / ogg

тип звука зависит от $bg

bg	Назначение
danger	ошибка / блок
warn	предупреждение
ok	успех
info	информация
accent	системный сигнал
21.6 Архитектурный принцип

CORE решает КОГДА показывать сообщение

UI решает КАК оно выглядит и звучит

Flash — инфраструктура, а не UI-элемент



МОДУЛЬ users — КАНОН (логика и функции)
1) Назначение модуля

users — модуль управления пользователями системы.

Отвечает за:

создание пользователя

редактирование данных пользователя

блокировку/разблокировку пользователя

сброс пароля пользователю (восстановление через email)

Не отвечает за:

авторизацию (логин/логаут/сессия) — это CORE auth

ACL логику системы — это CORE acl/modules

UI-инфраструктуру модалок — это adm/assets (глобальная модалка)

2) Роли и права (бизнес-правила)
2.1 Кто видит модуль

Доступ к модулю определяется таблицей modules.roles (JSON):

Пример:

users.roles = ["admin","manager"]

Тогда:

admin видит и использует модуль

manager видит и использует модуль

user не видит и не проходит acl_guard()

2.2 Что может manager

Создавать пользователей только с ролью user (принудительно)

Редактировать поля пользователя (имя/телоль/Email/Статус/Тема)

Блокировать/разблокировать пользователей

Запускать сброс пароля пользователю (email)

Ограничение:

manager не может назначать роли и менять роли

2.3 Что может admin

Всё, что manager

Плюс управление ролями пользователя (назначение/снятие)

3) Модель ролей (обоснование)

В системе предусмотрена связь многие-ко-многим:

users ↔ roles через user_roles

Причина:

роль = набор прав, пользователь может совмещать функции

избегаем взрыва количества “комбинированных ролей”

При этом для UI (меню/основной доступ) используется:

auth_user_role() — основная роль (первая по roles.sort)

auth_user_roles($uid) — полный список ролей (опционально)

4) Источник истины и хранение данных
4.1 Таблицы

users — данные пользователя (phone/email/name/status/ui_theme/pass_hash/created_at/updated_at)

roles — справочник ролей (id/code/name/sort)

user_roles — связка пользователь↔роль (user_id/role_id)

modules — включение модуля + меню + доступ по ролям (roles JSON)

4.2 Статус пользователя

users.status:

active — пользователь может входить

blocked — вход запрещён (CORE auth должен учитывать статус)

5) URL-канон и роутинг

Единый URL-канон (adm):
/adm/index.php?m=users&do=<action>

Типы do в модуле:

view — по умолчанию (отдаёт /adm/modules/users/users.php)

modal_* — выдаёт HTML в JSON для глобальной модалки

add/update/toggle/reset_password — действия (POST)

Прямой доступ к action-файлам запрещён:
в каждом файле:

if (!defined('ROOT_PATH')) exit;

6) Жёсткий поток выполнения

Всегда так:

/adm/index.php
→ adm/assets/php/main.php
→ adm/modules/users/assets/php/main.php
→ adm/modules/users/assets/php/users_<do>.php

Запрещено:

switch-логика в одном файле

прямой вызов /assets/php/users_add.php

7) Интерфейс (UI) модуля
7.1 VIEW

/adm/modules/users/users.php — UI-only.

Правила:

никаких INSERT/UPDATE/DELETE

действия — только через формы/кнопки на ?do=...

кнопки действий — только иконками (как в modules)

id пользователя не выводим текстом, если не нужно; допустимо держать в data-user-id

7.2 Модалки

Модуль не создаёт модалку.
Он отдаёт HTML для глобальной модалки:

do=modal_add
do=modal_update&id=...

Ответ:

JSON через json_ok(['title'=>..., 'html'=>...])

Открытие:

JS модуля вызывает window.App.modal.open(title, html)

8) Безопасность
8.1 ACL

В каждом action и modal:

acl_guard(module_allowed_roles('users'))

8.2 CSRF

Во всех POST-экшенах:

csrf_check($_POST['csrf'] ?? '')

8.3 Redirect/Response

редиректы только через redirect('/adm/index.php?...') (CORE response)

JSON только через json_ok()/json_err() (CORE response)
???? action ?????????? ?? ???????, ???????? ?? ??????? ??????:
- ? ????? ??????????? ??????? ???? return_url
- ?????????? ????????? ????? redirect_return(fallback)
- return_url ????????? ?? ????????-???????? (??????? tab=...)
- ???? return_url ?? ??????? ? ???????????? fallback ??????

9) Основные функции/экшены модуля
9.1 users_modal_add (GET)

Назначение:

отдать HTML формы создания пользователя

Логика:

ACL check

если actor = admin → показать выбор ролей

если actor = manager → скрыто принудить роль user

вернуть JSON {title, html} через json_ok()

9.2 users_add (POST)

Назначение:

создать пользователя

Вход:

name, phone, email, status, ui_theme

role_ids[] (только admin) или role_force=user (manager)

Логика:

ACL + CSRF

валидация минимума (name/phone)

нормализация status/ui_theme

генерация временного пароля

сохранение pass_hash = password_hash()

insert user

insert roles в user_roles

flash результат:

ok: “пользователь создан + временный пароль”

danger: ошибка

redirect обратно в m=users

9.3 users_modal_update (GET)

Назначение:

отдать HTML формы редактирования пользователя

Вход:

id (GET)

Логика:

ACL

загрузка пользователя

загрузка ролей пользователя (если admin)

вернуть JSON {title, html}

9.4 users_update (POST)

Назначение:

обновить пользователя

Вход:

id, name, phone, email, status, ui_theme

роли: role_ids[] (admin) или принудительно user (manager)

Логика:

ACL + CSRF

обновление данных в users

перезапись ролей в user_roles

flash ok/error

redirect

9.5 users_toggle (POST)

Назначение:

блок/разблок пользователя

Вход:

id

Логика:

ACL + CSRF

запрет блокировать самого себя

прочитать текущий status

переключить active ↔ blocked

flash ok/error

redirect

9.6 users_reset_password (POST)

Назначение:

сбросить пароль пользователю и отправить по email

Вход:

id

Логика:

ACL + CSRF

запрет сброса себе

если email пустой → warn + redirect

сгенерировать новый временный пароль

обновить pass_hash

отправить email через mail()

если mail() не работает → warn и показать пароль в flash (dev режим)

redirect

10) Интеграция с CORE (что обязательно должен учитывать CORE)
10.1 CORE auth при логине

Должен:

не пускать пользователя, если users.status = blocked

выдавать flash “Пользователь заблокирован” (danger, beep=1)

10.2 Меню и доступ к модулям

Опирается на:

modules.roles (JSON)

текущую роль пользователя (auth_user_role() или auth_user_roles())

10.3 Ролевая видимость UI внутри модуля

ACL (`modules.roles` + `acl_guard()`) решает только вход в модуль.
Внутренние блоки экрана (секции/линии/кнопки) допускается показывать по ролям через `auth_user_roles($uid)` и флаги (`$isAdmin`, `$isManager`, `$isUser`).

Ограничения:
- VIEW может делать только role-based render (показ/скрытие UI).
- Бизнес-операции (INSERT/UPDATE/DELETE, смена статусов, настройки) остаются только в action-файлах `assets/php/*`.
- Матрица видимости UI для каждого модуля фиксируется в `docs/MODULES_REGISTRY.md`.

Пример для dashboard:
- `user` (без admin/manager): только 1 и 3 линия.
- `admin`, `manager`: 1, 2 и 3 линия.

Telegram-интеграция: вся работа с Telegram Bot API реализуется через /core/telegram.php; файл содержит только
 инфраструктурные функции (запросы API, webhook, security) и подключается точечно в нужных модулях.
 Настройка webhook допускается на любой публичный URL проекта, обработка входящих update — только через проверку X-Telegram-Bot-Api-Secret-Token.



 Общие правила логирования (проект)

Единая точка: логируем только через audit_log() из audit.php, прямые error_log()/file_put_contents() не используем.
Уровни:
info — нормальные бизнес‑события (create/update/toggle/reset/enable/disable).
warn — подозрительные/ожидаемые отказы (валидация, not_found, duplicate, deny, protected).
error — исключения, сбои внешних сервисов, ошибки БД/SMTP.
Именование:
module — код модуля (users, clients, modules, auth, core, security).
action — snake_case, глагол + сущность (create, update, toggle, reset_password, csrf_fail).
Payload: короткий, диагностический. Не писать пароли/секреты/токены. Можно: id, status, from/to, reason, phone/email (если это допустимо), error (текст исключения).
Связка с сущностью: всегда указывать entity + entity_id, если событие относится к записи (например user/client/module).
Актор: передавать user_id и role (берём из auth_user_id()/auth_user_role()).
Двойная запись: каждый лог пишется в БД и в audit-fallback.log (JSONL).
Строгие правила для модулей (обязательный минимум)

Каждое POST‑действие обязано писать лог:
успех (info);
валидационный отказ (warn);
исключение (error).
CRUD/Toggle/Reset — логировать минимум: id, from/to или status, reason при отказе.
Deny/ACL/Protected — всегда warn с reason.
Никакой логики обхода: если действие не дошло до БД (валидация/403) — лог всё равно пишется.


22. Настройки в календаре и режимы
- новые настройки (user/manager) храним персонально в calendar_user_settings.
- calendar_mode / calendar_manager_* в requests_settings считаем legacy и не используем в новой версии.

23. I18N MODULE CANON

Mandatory localization layer:
- core/i18n.php is the single loader for dictionaries and function t().
- Language is stored in session key lang, default value is ru.
- Load order: lang/<lang>/common.php, then active module dictionary.

Dictionary structure:
- Global: /lang/ru/common.php and /lang/en/common.php.
- Module: /adm/modules/<module>/lang/ru.php and /adm/modules/<module>/lang/en.php.
- Dictionary files must return only array key-value pairs.

Keys and usage:
- Keys must be namespaced: common.* and <module>.*.
- All user-facing strings in PHP UI/action files must use t('key').
- For placeholders use templates like {name} plus strtr in helper.

Migration boundaries:
- Do not change SQL or business logic for i18n migration.
- Do not change id/class/data-* and HTML structure.
- Only minimal text replacements and dictionary additions are allowed.

Migration order and status are tracked in docs/I18N_MODULES_ROADMAP.md.
Every new module must be created with both lang/ru.php and lang/en.php.