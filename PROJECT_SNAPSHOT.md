# 📋 Слепок проекта CRM2026

**Дата создания:** 26 января 2026 г.
**Локация:** d:\OpenServer\domains\crm2026new

---

## 📁 Структура проекта

```
crm2026new/
├── index.php                    # Главный файл входа
├── CANON.md                     # Документация канона
├── README.md                    # Информация о проекте
│
├── core/                        # Ядро приложения
│   ├── bootstrap.php            # Инициализация и хелперы
│   ├── config.php               # Конфигурация
│   ├── db.php                   # Работа с БД
│   ├── auth.php                 # Аутентификация и авторизация
│   ├── acl.php                  # Контроль доступа (ACL)
│   ├── session.php              # Управление сессией
│   ├── cookies.php              # Работа с куками
│   ├── csrf.php                 # Защита CSRF
│   ├── modules.php              # Управление модулями
│   ├── response.php             # HTTP ответы
│   ├── flash.php                # Flash-сообщения
│   └── audit.php                # Логирование аудита
│
├── adm/                         # Административная панель
│   ├── index.php                # Вход в админ-панель
│   ├── core/
│   │   └── bootstrap.php
│   ├── logs/                    # Логи
│   │
│   ├── modules/                 # Модули админ-панели
│   │   ├── auth/                # Модуль аутентификации
│   │   │   ├── auth.php
│   │   │   ├── settings.php
│   │   │   └── assets/
│   │   │       ├── css/
│   │   │       ├── js/
│   │   │       │   └── main.js
│   │   │       └── php/
│   │   │           ├── auth_login.php
│   │   │           ├── auth_logout.php
│   │   │           └── main.php
│   │   │
│   │   └── dashboard/           # Модуль панели управления
│   │       ├── dashboard.php
│   │       ├── settings.php
│   │       └── assets/
│   │           ├── css/
│   │           ├── js/
│   │           └── php/
│   │               └── main.php
│   │
│   └── view/                    # Представление
│       ├── index.php
│       ├── index.md
│       └── assets/              # Ресурсы админ-панели
│           ├── css/
│           │   ├── main.css
│           │   ├── base/
│           │   │   ├── layout.css
│           │   │   ├── reset.css
│           │   │   └── typography.css
│           │   ├── components/
│           │   │   ├── buttons.css
│           │   │   ├── cards.css
│           │   │   ├── modal.css
│           │   │   ├── sidebar.css
│           │   │   ├── table.css
│           │   │   └── topbar.css
│           │   ├── themes/
│           │   │   ├── black.css
│           │   │   ├── color.css
│           │   │   └── light.css
│           │   └── utilities/
│           │       ├── helpers.css
│           │       └── states.css
│           ├── js/
│           │   └── main.js
│           ├── libs/
│           │   └── vendor/
│           └── php/
│               └── main.php
│
└── site/                        # Публичная часть сайта
    └── index.php
```

---

## 🔧 Функции по модулям

### **Core: bootstrap.php**
- `h(string $s): string` — HTML экранирование
- `url(string $path): string` — Формирование URL
- `fs_path(string $path): string` — Формирование пути к файлу

### **Core: session.php**
- `app_config(): array` — Получение конфигурации приложения
- `session_boot(): void` — Инициализация сессии
- `session_get(string $key, $default = null)` — Получение значения из сессии
- `session_set(string $key, $value): void` — Установка значения в сессию
- `session_del(string $key): void` — Удаление значения из сессии
- `session_regenerate(): void` — Регенерация ID сессии

### **Core: auth.php**
- `auth_user_id(): ?int` — Получение ID текущего пользователя
- `auth_is_logged_in(): bool` — Проверка авторизации
- `auth_require_login(): void` — Требование авторизации
- `auth_login_by_phone(string $phone, string $password, bool $remember = false): bool` — Вход по телефону и пароль
- `auth_logout(): void` — Выход из системы
- `auth_restore(): void` — Восстановление из куки-remember
- `auth_attempt_key(string $phone, string $ip): string` — Создание ключа попытки входа
- `auth_attempt_is_locked(string $keyStr): bool` — Проверка блокировки попыток
- `auth_attempt_fail(string $keyStr): void` — Фиксирование неудачной попытки
- `auth_attempt_clear(string $keyStr): void` — Очистка попыток
- `auth_remember_create(int $userId): void` — Создание remember-me токена
- `auth_remember_revoke_from_cookie(): void` — Отзыв токена из куки
- `auth_remember_revoke_by_selector(string $selector): void` — Отзыв по селектору
- `auth_remember_revoke_by_id(int $id): void` — Отзыв по ID
- `auth_remember_split(string $packed): array` — Распаковка токена
- `auth_random_selector(int $len): string` — Генерация случайного селектора
- `auth_ip_pack(string $ip): ?string` — Упаковка IP адреса
- `auth_user_roles(int $uid): array` — Получение ролей пользователя

### **Core: acl.php**
- `acl_guard(array $allowedRoles): void` — Проверка прав доступа
- `acl_roles_intersect(array $userRoles, array $allowedRoles): bool` — Пересечение ролей

### **Core: response.php**
- `redirect(string $url): void` — Редирект
- `http_401(string $msg = 'Unauthorized'): void` — Ошибка 401
- `http_403(string $msg = 'Forbidden'): void` — Ошибка 403
- `http_404(string $msg = 'Not Found'): void` — Ошибка 404
- `http_405(string $msg = 'Method Not Allowed'): void` — Ошибка 405
- `json_ok(array $data = []): void` — JSON успех
- `json_err(string $msg, int $code = 400, array $data = []): void` — JSON ошибка

### **Core: modules.php**
- `modules_all(): array` — Получение всех модулей
- `module_by_code(string $code): ?array` — Получение модуля по коду
- `module_is_enabled(string $code): bool` — Проверка включения модуля
- `module_allowed_roles(string $code): array` — Получение разрешённых ролей модуля
- `modules_menu_for_roles(array $userRoleCodes): array` — Меню для ролей
- `modules_roles_decode(mixed $raw): array` — Декодирование ролей
- `modules_roles_intersect(array $userRoles, array $allowedRoles): bool` — Пересечение ролей

### **Core: db.php**
- `db(): PDO` — Получение PDO подключения

### **Core: csrf.php**
- `csrf_token(): string` — Получение CSRF токена
- `csrf_check(string $token): void` — Проверка CSRF токена

### **Core: cookies.php**
- `cookie_secret(): string` — Получение секрета куки
- `cookie_set_signed(string $name, string $value, int $ttlSec): void` — Установка подписанной куки
- `cookie_get_signed(string $name): ?string` — Получение подписанной куки
- `cookie_del(string $name): void` — Удаление куки

### **Core: flash.php**
- `flash_set(string $key, string $value): void` — Установка flash-сообщения
- `flash_get(string $key, string $default = ''): string` — Получение flash-сообщения

### **Core: audit.php**
- `audit_log()` — Логирование действий
- `audit_fallback_file_write(array $row): void` — Резервная запись в файл

---

## 🎨 CSS Классы

### **Base CSS**

#### layout.css
```css
.app                    /* Основной контейнер */
.shell                  /* Оболочка приложения */
.main                   /* Главная содержимая область */
.pagehead               /* Заголовок страницы */
.grid                   /* Сетка (2 колонки, 980px переход на 1) */
.stack                  /* Вертикальный стек */

@media (max-width: 980px)  /* Мобильная адаптация */
  .grid { grid-template-columns: 1fr; }
```

#### reset.css
Общий reset стили

#### typography.css
```css
.h1                     /* Заголовок h1 */
.muted                  /* Приглушённый текст */
.ta-r                   /* Text-align right */
```

---

### **Components CSS**

#### buttons.css
```css
.field                  /* Контейнер для полей */
.field--stack           /* Вертикальное расположение */
.field__label           /* Подпись поля */
.select                 /* Выпадающий список */
.input                  /* Текстовое поле */
.btn                    /* Кнопка */
.btn:hover              /* Состояние hover */
.btn:focus              /* Состояние focus */
.btn--wide              /* Кнопка на всю ширину */
.btn--accent            /* Ударная кнопка */
.btn--danger            /* Опасная кнопка */
.iconbtn                /* Кнопка с иконкой */
.iconbtn:hover
.iconbtn:focus
.iconbtn__bars          /* Иконка бургер-меню */
```

#### cards.css
```css
.card                   /* Карточка */
.card__label            /* Подпись в карточке */
.card__value            /* Значение в карточке */
.card__head             /* Заголовок карточки */
.card__title            /* Название карточки */
.card__hint             /* Подсказка в карточке */
.card__body             /* Основной контент карточки */
.card__foot             /* Подвал карточки */
.skeleton               /* Скелетон (заглушка) */
.skeleton__bar          /* Линия скелетона */
```

#### modal.css
```css
.modal                  /* Модальное окно */
.modal.is-open          /* Открытое модальное окно */
.modal__backdrop        /* Фон позади модального окна */
.modal__panel           /* Панель модального окна */
.modal__head            /* Заголовок модального окна */
.modal__foot            /* Подвал модального окна */
.modal__title           /* Название модального окна */
.modal__body            /* Основной контент модального окна */
```

#### sidebar.css (Боковая панель)
```css
.sidebar                /* Боковая панель */
.sidebar__brand         /* Брендинг в сайдбаре */
.sidebar__footer        /* Подвал сайдбара */
.brand                  /* Бренд элемент */
.brand__dot             /* Точка бренда */
.brand__name            /* Название бренда */
.menu                   /* Меню */
.menu__item             /* Элемент меню */
.menu__item:hover       /* Состояние hover */
.menu__item.is-active   /* Активный пункт меню */
.menu__icon             /* Иконка меню */
.menu__label            /* Подпись меню */

/* Состояние свернутого сайдбара */
.app.is-collapsed .sidebar              /* Узкая боковая панель (72px) */
.app.is-collapsed .menu__label          /* Скрыта подпись */
.app.is-collapsed .brand__name          /* Скрыто название */
.app.is-collapsed .field__label         /* Скрыта подпись поля */
.app.is-collapsed .sidebar__footer      /* Скрыт подвал */
.app.is-collapsed .sidebar__brand       /* Узкий брендинг */
.app.is-collapsed .menu__item           /* Центрированный элемент */

/* Мобильное меню */
@media (max-width: 980px)
  .sidebar { /* Сайдбар как оверлей */ }
  .app.is-mobile-menu .sidebar          /* Сайдбар виден */
  .app.is-mobile-menu::before           /* Overlay за меню */
```

#### table.css
```css
.table-wrap             /* Контейнер таблицы (scrollable) */
.table                  /* Таблица */
.table th, .table td    /* Ячейки таблицы */
.table thead th         /* Заголовок таблицы */
.table tbody tr:hover   /* Строка при наведении */
```

#### topbar.css
```css
.topbar                 /* Верхняя панель */
.topbar__title          /* Название в topbar */
.topbar__right          /* Правая часть topbar */
```

---

### **Themes CSS**

#### black.css
Тёмная тема оформления

#### light.css
Светлая тема оформления

#### color.css
Цветовая палитра и переменные

---

### **Utilities CSS**

#### helpers.css
```css
.hidden                 /* display: none !important */
```

#### states.css
```css
.is-disabled            /* Отключённое состояние (opacity, pointer-events) */
```

---

### **CSS Переменные (Color scheme)**

```css
--border-soft           /* Мягкая граница */
--text-muted            /* Приглушённый текст */
--control-bg-hover      /* Фон контроля при hover */
--focus                 /* Цвет focus */
--radius                /* Радиус скругления */
--table-row-hover       /* Фон строки при hover */
```

---

## 📊 Статистика

| Категория | Количество |
|-----------|-----------|
| PHP Функции | 54 |
| CSS Классы | 79 |
| CSS Файлы | 15 |
| PHP Файлы | 25+ |
| Медиа-запросы | 2 |

---

## 🔐 Ключевые особенности

✅ **Аутентификация:**
- Вход по телефону и пароль
- Remember-me функция
- Защита от брутфорса (блокировка попыток)
- Регенерация сессии

✅ **Безопасность:**
- CSRF защита
- Подписанные куки
- ACL система контроля доступа
- Аудит логирование

✅ **Модульная архитектура:**
- Система модулей
- Ролевая система доступа
- Динамическое меню для ролей

✅ **UI/UX:**
- Адаптивный дизайн (980px breakpoint)
- Модальные окна
- Боковое меню с collapse функцией
- Мобильное меню overlay
- Dark/Light тема поддержка
- Flash сообщения

---

**Дата последнего обновления:** 26 января 2026 г.
