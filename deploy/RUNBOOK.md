# RUNBOOK — переезд iceberg.spb.ru на новый статический сайт (sprinthost)

> Пошаговый безопасный переезд: смена движка WordPress → статика **без смены URL**
> (склейка). Ничего не удаляем без бэкапа; переключаемся только после сверки.
> Контекст и решения — [../../../seo.md](../../../seo.md). Сборка — [../../web_development.md](../../web_development.md).

---

## 0. Предусловия (собрать до старта)

- [ ] **Доступ к sprinthost** — SSH (лучше) или FTP + доступ к БД. SSH-ключ уже сгенерён
      (`~/.ssh/sprinthost_iceberg`), публичную часть добавить в панель.
- [ ] **OAuth-токен Яндекса** (Метрика 61680454 + Вебмастер iceberg.spb.ru) — для baseline.
- [ ] Понять веб-сервер: shared sprinthost — это **Apache**, редиректы берём из `.htaccess`
      (не `nginx-iceberg.conf`; nginx-версия — если дадут VPS/полный конфиг).

---

## 1. Baseline (ОБЯЗАТЕЛЬНО до любых изменений)

Снять точку отсчёта, чтобы после переезда сравнить «до/после»:
- Метрика: визиты/источники по каждому URL за 90–365 дней.
- Вебмастер: показы, клики, средняя позиция по топ-запросам и по URL.
- **Отдельно — трафик на `/trikotazhnie-polotna-pricelist1/`**: от него зависит решение
  301-на-biflex vs оставить снимок. Записать в `01-iceberg/tochka-otscheta-seo-v1.html`.

## 2. Полный бэкап WordPress (страховка отката)

По SSH:
```
mysqldump -u USER -p БАЗА | gzip > ~/wp-backup-$(date +%F).sql.gz
tar czf ~/wp-files-$(date +%F).tar.gz -C ~/ public_html
```
Скачать обе копии на Мак. **До этого шага сайт не трогаем.**

## 3. Сборка релиза

```
# в build_v3.py, build_pages.py, build_seo.py выставить PREVIEW=False
python3 website/tools/build_v3.py
python3 website/tools/build_pages.py
python3 website/tools/build_seo.py       # боевые robots.txt (Allow) + sitemap.xml
python3 website/tools/build_release.py   # соберёт готовый web-root → website/01-iceberg/dist/
```
`dist/` = точная раскладка корня домена (см. манифест внизу).

## 4. Staging под паролем (проверка до переключения)

- Залить `dist/` во временную папку/поддомен (например `new.iceberg.spb.ru` или `~/staging`).
- Прогнать **сверку** по каждому URL — статус / `<title>` / `<link canonical>` / редирект
  должны совпадать с живым сайтом (склейка). Живые страницы — 200 и тот же title;
  каталог трикотажа — 301 на biflex; мусор/WP — 410.

## 5. Переключение (cutover)

- Забэкапленный WordPress убрать из `public_html` (переименовать, не удалять).
- Залить содержимое `dist/` в `public_html` (rsync/FTP). Положить `.htaccess` в корень.
- Проверить, что открывается главная и внутренние; редиректы работают.

## 6. После переключения

- [ ] В Вебмастере залить новый `sitemap.xml`, старые `page-/post-sitemap.xml` уже 301-ятся.
- [ ] Проверить `robots.txt` (Allow, без Disallow: /), `noindex` снят (PREVIEW=False).
- [ ] Формы шлют заявки в CRM (не заглушку).
- [ ] Прогнать сверку редиректов по `redirect-map-trikotazh.csv` (выборочно).

## 7. Мониторинг 2–4 недели

Сравнивать с baseline: позиции, показы, органика, ошибки в Вебмастере, конверсии заявок.
При заметной просадке — **откат по бэкапу** (вернуть WP из шага 2).

---

## Манифест web-root (что в `dist/`)

```
index.html                     — главная
hero-assets/seq-wide/          — кадры первого экрана
hero-assets/seq-full-fix/      — кадры блока «яхта»
tkani-optom/  contacts/  vacancy/  shveinoe-proizvodstvo/
izgotovlenie-kupalnikov-i-sportivnoi-odezhdi/  izgotovlenie-startovykh-maek/
mashinnaya-vishivka/  tkani-print/           — новый дизайн
emdi/  technicheskie-tkani/  trikotazhnie-polotna-pricelist1/  — снимки WP 1-в-1
_wp/                           — ассеты снимков (снимки ссылаются на ../_wp/ → /_wp/)
robots.txt  sitemap.xml  og-cover.jpg  .htaccess
```

## Проверка склейки (пример)

```
for u in "" tkani-optom/ contacts/ shveinoe-proizvodstvo/ ; do
  curl -s -o /dev/null -w "%{http_code} %{url_effective}\n" "https://iceberg.spb.ru/$u"
done
# каталог должен давать 301 на biflex:
curl -sI "https://iceberg.spb.ru/trikotazhnie-polotna/123/" | grep -i location
```

## Открытые решения перед боем
- **Прайс** `/trikotazhnie-polotna-pricelist1/`: оставить снимок или 301 на biflex — по baseline-трафику.
- **Снимки** emdi/technicheskie-tkani: пока 1-в-1 (безопасно для склейки), редизайн — в задачах EMDI/Tech.
- **Формы → CRM**: сейчас заглушка + `lead_submit` в dataLayer.
