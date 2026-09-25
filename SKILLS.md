# SKILLS — процедуры и уроки по проекту skpsp_work

База знаний для будущих сессий. Состояние сайта → `AUDIT.md`, правила деплоя/пуша → раздел Workflow там же.

## 1. Массовые замены в HTML (ВАЖНО)
- Regex вида `class="([^"]+)"[^>]*?style="..."` **съедает промежуточные атрибуты** тега (`role`, `aria-label`, `data-full`). Инцидент: при переносе 25 inline-фонов потерялись a11y-атрибуты и data-full лайтбокса; восстановлено из git.
- **Правило:** при замене атрибута внутри тега либо перестраивать тег целиком, либо после замены обязательно `git diff HEAD~1 -- <file>` и сверить, что изменилось только задуманное.

## 2. Cache-busting (`?v=`)
- Конвенция: `YYYYMMDD` + буква (a, b, c…). Бампить **только изменённые** файлы (css/js), на всех 38 страницах разом Python-скриптом:
  ```python
  for f in pathlib.Path('.').glob('*.html'):
      s = f.read_text(encoding='utf-8')
      new = s.replace('style.css?v=OLD', 'style.css?v=NEW')
      if new != s: f.write_text(new, encoding='utf-8')
  ```

## 3. Смоук-тест локально
```bash
python -m http.server 8765 --bind 127.0.0.1 &   # из корня проекта
curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8765/<path>
```
- Проверять все `url()` в CSS на существование файлов **с URL-decode** (`urllib.parse.unquote`) — PDF с кириллицей в именах.
- Ссылки без расширения (`href="about"`) локально «не существуют» — это нормально, их резолвит серверный роутер; проверять `name.html`.
- Python на этой машине: `python` (3.11), НЕ `python3` (Store-stub). Для вывода кириллицы в консоль — `PYTHONIOENCODING=utf-8`.

## 4. Палитра (palette lock, зафиксирован в :root style.css)
- **red #a22036** — единственный цвет CTA/действий.
- **blue #3082a8** — только семантика: ссылки, hover, focus, низкие opacity-тенты. Не для крупных заливок и кнопок.
- **orange #ff9f43** (`--orange-accent`) — исключение: active-nav + подсветка слова в hero-заголовке (`.hero__title--accent`).
- Тени — только navy `rgba(8,15,26,x)`; чистый чёрный не использовать (кроме text-shadow над фото).
- Нейтральная семья холодная: `--bg #222a33`, поверхности `#12233a`/`#173049`.

## 5. Шрифты
- Self-host в `assets/fonts/*.woff2` + `assets/css/fonts.css` (unicode-range — браузер грузит только cyrillic+latin). Google Fonts на сайте быть не должно.
- **Перед скачиванием сверить веса:** все `font-weight`, используемые с Unbounded/Manrope в CSS, должны присутствовать в наборе. Инцидент: `.hero__title` использовал 800, а грузились только 400–600 → браузер молча подставлял 600.

## 6. Motion
- `window.addEventListener('scroll')` не использовать (в современных браузерах). Схема:
  - дискретные состояния (тень шапки, scrollspy) → **IntersectionObserver** (+ сентинел для порогов);
  - непрерывный scrub (marquee) → **CSS scroll-driven animations** в `@supports`, JS-fallback гейтится `CSS.supports('animation-timeline: view()')`.

### Scroll-driven animations — три ловушки (проверено на marquee, инцидент 2026-09-25)
1. **Анонимный `view()` на потомке не работает**, если между ним и вьюпортом есть предок с `overflow: hidden`/`auto` — этот предок становится scroll-box'ом таймлайна, а внутри него ничего не скроллится → прогресс заморожен, анимация молча стоит. Решение: **именованный таймлайн** на самом элементе-контейнере (его ближайший scroll-container — вьюпорт):
   ```css
   .marquee { view-timeline: --band block; }
   .marquee__track { animation: marquee-scrub linear both; animation-timeline: --band; }
   ```
2. **Имя таймлайна нельзя писать в shorthand `animation`** (`animation: name linear both var(--band)` — невалидно, вся декларация молча отбрасывается). Только отдельным свойством `animation-timeline: --band;`. Длительность при scroll-таймлайне не указывается (keyframes мапятся на весь диапазон таймлайна).
3. **Проверять существование целевых классов до написания CSS/JS**: `.hero__bg` в разметке нигде нет — hero-параллакс никогда не работал ни в JS, ни в CSS (оба целились в пустоту). Не добавлять «фичи» по памяти о классах.

### Верификация scroll-анимаций без браузера под рукой
Headless Chrome + CDP через встроенный WebSocket Node 22 (шаблон `$TEMP/cdp_test.mjs`):
1. `chrome.exe --headless=new --remote-debugging-port=9335 ... about:blank`
2. `GET /json/list` → webSocketDebuggerUrl вкладки;
3. `Page.navigate` на локальный сервер, ждать `document.readyState === 'complete'`;
4. Циклом `window.scrollTo(0, y)` + пауза 400 мс + `Runtime.evaluate` чтения `getComputedStyle(el).transform/.opacity` при нескольких y — значения должны меняться с прокруткой.
Если `transform: none` на всех позициях → анимация не применена (невалидная декларация), а не «просто стоит».

## 7. Контент
- Фейк-перфект в статистике запрещён («100%», «99.9%») — либо реальная метрика, либо текстовая формулировка (пример: «в срок / сдача объектов и контроль качества»).
- Факты компании должны совсодать везде: **20+ лет** (не 25!), 120+ объектов. Проверять meta description, hero, lead__stats на всех страницах.

## 8. Eyebrow-дисциплина
- Максимум ceil(кол-во секций / 3) eyebrow на страницу; hero считается за один (ротационные подписи слайдера — не отдельные).
