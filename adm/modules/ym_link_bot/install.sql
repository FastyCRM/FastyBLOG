-- ym_link_bot module registration example
-- Change code/name if you copy folder under a new module name.

INSERT INTO modules (code, name, icon, sort, enabled, menu, roles, has_settings)
VALUES ('ym_link_bot', 'YM Link Bot', 'bi bi-link-45deg', 950, 1, 1, '["admin","manager","user"]', 0)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  icon = VALUES(icon),
  sort = VALUES(sort),
  enabled = VALUES(enabled),
  menu = VALUES(menu),
  roles = VALUES(roles),
  has_settings = VALUES(has_settings);
