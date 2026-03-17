# I18N_MODULES_ROADMAP

Source order: adm/modules/*.

## Statuses
- done - module migrated to dictionaries (lang/ru.php, lang/en.php) and keys t(module.*).
- in_progress - module is currently being migrated.
- todo - migration not started yet.

## Migration queue
| order | module | status | notes |
|---:|---|---|---|
| 1 | dashboard | done | Migrated earlier |
| 2 | auth | done | Migrated |
| 3 | modules | done | Migrated |
| 4 | users | done | Migrated |
| 5 | clients | done | Migrated |
| 6 | services | done | Migrated |
| 7 | requests | todo |  |
| 8 | calendar | todo |  |
| 9 | personal_file | todo |  |
| 10 | tg_system_users | todo |  |
| 11 | tg_system_clients | todo |  |
| 12 | oauth_tokens | todo |  |
| 13 | ym_link_bot | todo |  |
| 14 | bot_adv_calendar | todo |  |
| 15 | subguard | todo |  |

## Rule of pass
1. Add lang/ru.php and lang/en.php in module.
2. Move all user-facing strings to keys module.*.
3. Do not change SQL, business logic, id/class/data attributes.
4. Verify UI module in both languages.
5. Update status in this file.