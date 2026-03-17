# personal_file

Модуль «Личное дело» клиента: карточка, заметки, вложения, доступы, история услуг.

## Точки входа
- UI: `/adm/index.php?m=personal_file`
- API контур:
  - `/adm/index.php?m=personal_file&do=api_link`
  - `/adm/index.php?m=personal_file&do=api_brief`
  - `/adm/index.php?m=personal_file&do=api_notes`
  - `/adm/index.php?m=personal_file&do=api_services`
  - `/adm/index.php?m=personal_file&do=api_accesses`

## Разрешенные do (основные)
- `modal_settings`
- `client_update`, `note_add`, `notes_pdf`
- `access_add`, `access_delete`, `access_reveal`, `file_get`
- `settings_type_*`, `settings_ttl_*`
- `api_link`, `api_brief`, `api_notes`, `api_services`, `api_accesses`

## Файлы
- VIEW: `adm/modules/personal_file/personal_file.php`
- Router: `adm/modules/personal_file/assets/php/main.php`
- Lib: `adm/modules/personal_file/assets/php/personal_file_lib.php`

## Документация
Подробное описание модуля: `docs/PERSONAL_FILE.md`.

## БД
Отдельного `install.sql` у модуля нет. Используются таблицы `personal_file_*` и связанные таблицы общей схемы.
