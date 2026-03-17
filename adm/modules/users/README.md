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