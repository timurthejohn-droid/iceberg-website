#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Прогон админки на живом стенде: вход с 2FA, все страницы, правки, заявки, публичная часть.

Зачем: локально PHP не установлен, поэтому стенд поднимается на переносимой сборке PHP,
а этот скрипт ходит по админке ровно как браузер — через HTTP, с cookie и CSRF.

Запуск:
    ./tests/stand.sh          # поднять чистый стенд (нужен PHP, см. tests/README.md)
    python3 tests/smoke.py    # прогнать проверки

Переменные окружения:
    ICEBERG_STAND  — папка стенда (по умолчанию /tmp/iceberg-stand)
    ICEBERG_PHP    — путь к бинарнику php
    ICEBERG_URL    — адрес стенда (по умолчанию http://127.0.0.1:8090)
"""
import re, os, subprocess, html as _h, json, time, base64, hmac, hashlib, struct

STAND = os.environ.get('ICEBERG_STAND', '/tmp/iceberg-stand')
PHP   = os.environ.get('ICEBERG_PHP', 'php')
BASE  = os.environ.get('ICEBERG_URL', 'http://127.0.0.1:8090')
RUN   = STAND
JAR   = os.path.join(STAND, 'cookies.txt')
USER, PASS = 'marketolog', 'PareVaLnyj2026pass'
FAIL = []

def req(url, data=None, follow=True, raw=False):
    cmd=['curl','-s','-c',JAR,'-b',JAR]+(['-L'] if follow else [])
    if data:
        for k,v in data.items(): cmd += ['--data-urlencode', f'{k}={v}']
    cmd.append(BASE+url)
    out=subprocess.run(cmd,capture_output=True).stdout
    return out if raw else out.decode('utf-8','replace')

def csrf(h):
    m=re.search(r'name="_csrf" value="([^"]+)"',h); return m.group(1) if m else None

def totp(secret):
    key=base64.b32decode(secret+'='*(-len(secret)%8))
    d=hmac.new(key,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest()
    o=d[19]&15
    return str((struct.unpack('>I',d[o:o+4])[0]&0x7fffffff)%1000000).zfill(6)

def check(name, cond):
    print(f"   [{'ok ' if cond else 'ПРОВАЛ'}] {name}")
    if not cond: FAIL.append(name)

def login():
    if os.path.exists(JAR): os.remove(JAR)
    h=req('/admin/?p=login')
    h=req('/admin/?p=login',{'_csrf':csrf(h),'username':USER,'password':PASS})
    m=re.search(r"<div class='secret'>([A-Z2-7 ]+)</div>",h)
    if m:
        secret=m.group(1).replace(' ',''); step='/admin/?p=twofa_setup'
    else:
        secret=subprocess.run([PHP,'-r','require "app/bootstrap.php"; $u=Auth::findByUsername("' + USER + '"); echo Crypto::decrypt((string)$u["totp_secret"]);'],
                              cwd=RUN,capture_output=True).stdout.decode().strip()
        step='/admin/?p=twofa'
    h=req(step,{'_csrf':csrf(h),'code':totp(secret)})
    return h

def formfields(page):
    out={}
    for m in re.finditer(r"<textarea name='f\[([A-Za-z0-9_]+)\]'[^>]*>(.*?)</textarea>", page, re.S):
        out[m.group(1)]=_h.unescape(m.group(2))
    for m in re.finditer(r"<input type=text name='f\[([A-Za-z0-9_]+)\]' value='([^']*)'", page):
        out.setdefault(m.group(1), _h.unescape(m.group(2)))
    return out

def save(slug, changes=None):
    h=req('/admin/?p=page&slug='+slug)
    data=formfields(h)
    if changes:
        missing=[k for k in changes if k not in data]
        assert not missing, f'{slug}: нет полей {missing}'
        data.update(changes)
    payload={'_csrf':csrf(h),'slug':slug}
    payload.update({f'f[{k}]':v for k,v in data.items()})
    r=req('/admin/?p=page', payload)
    m=re.search(r"<div class='msg[^']*'>(.*?)</div>", r, re.S)
    return _h.unescape(re.sub('<[^>]+>','',m.group(1))).strip() if m else 'нет сообщения'

SLUGS=[('','Главная'),('tkani-optom','Ткани оптом'),('contacts','Контакты'),('vacancy','Вакансии'),
 ('shveinoe-proizvodstvo','Производство'),('izgotovlenie-kupalnikov-i-sportivnoi-odezhdi','Купальники'),
 ('izgotovlenie-startovykh-maek','Майки'),('mashinnaya-vishivka','Вышивка'),('tkani-print','Печать'),
 ('policy.html','Политика'),('cookie_agreement.html','Cookie')]

print('1. Вход в админку')
h=login(); check('вошли, видим список страниц', 'Страницы сайта' in h)

print('\n2. Все страницы открываются в редакторе, поля подтянуты из вёрстки')
for slug,label in SLUGS:
    h=req('/admin/?p=page&slug='+slug)
    f=formfields(h)
    check(f'{label:12} — полей {len(f):2}', len(f)>=8 and 'Страница не найдена' not in h)

print('\n3. Сохранение без правок ничего не меняет (правило: не трогали — не переписываем)')
for slug,label in SLUGS:
    check(f'{label:12} — {save(slug)}', save(slug)=='Изменений не было.')

print('\n4. Правки на главной ложатся на сайт')
print('  ', save('', {
    'num_note':'', 'title':'Айсберг — проверка админки',
    'hero_sub':'Ткани, одежда и <em>купальники</em> — проверка.',
    'about_facts':'<div><b>2003</b><span>год основания</span></div>',
    'co1_photo':'/uploads/test.jpg'}))
site=req('/')
check('черновая пометка «цифры уточняются» убрана', 'цифры уточняются' not in site)
check('title заменён', '<title>Айсберг — проверка админки</title>' in site)
check('подзаголовок заменён', 'купальники</em> — проверка' in site)
check('год в фактах заменён', '<b>2003</b><span>год основания</span>' in site)
check('фото подставилось через CSS', '.ph-tech{background-image:url(' in site)
check('шейдер первого экрана цел', 'paper-shaders' in site and 'hero-fx__a' in site)

print('\n5. Оффер посадочной (первый экран задаётся скриптом)')
print('  ', save('shveinoe-proizvodstvo', {
    'offer_h1':'Запустите производство за <em>3&nbsp;недели</em>',
    'offer_sub':'Проверка подстановки оффера.'}))
lp=req('/shveinoe-proizvodstvo/')
# внутри <script> слеш закрывающего тега экранируется (<\/em>) — так и должно быть
check('заголовок оффера в скрипте заменён', r"за <em>3&nbsp;недели<\/em>'" in lp)
check('подзаголовок оффера заменён', 'Проверка подстановки оффера.' in lp)
check('объект OFFERS не сломан', re.search(r"var OFFERS = \{\s*a: \{", lp) is not None)

print('\n6. Текст внутренней страницы — форма заявки остаётся нетронутой')
print('  ', save('vacancy', {'page_body': re.sub(
    r'<h2>[^<]*</h2>', '<h2>Требуются швеи — проверка</h2>',
    formfields(req('/admin/?p=page&slug=vacancy'))['page_body'], count=1)}))
vac=req('/vacancy/')
check('текст заменён', 'Требуются швеи — проверка' in vac)
check('форма заявки на месте', 'data-form="vacancy"' in vac and 'name="consent"' in vac)
check('ловушка для ботов на месте', 'name="website"' in vac)
check('согласие 152-ФЗ на месте', 'Политикой конфиденциальности' in vac)

print('\n7. Глобальные телефон и почта')
h=req('/admin/?p=settings')
payload={'_csrf':csrf(h),'s[phone]':'+7 (812) 000-11-22','s[phone_href]':'+78120001122',
         's[email]':'zayavki@iceberg.spb.ru','s[og_cover]':''}
req('/admin/?p=settings', payload)
site=req('/')
check('телефон в ссылках', 'tel:+78120001122' in site and 'tel:+78123256993' not in site)
check('телефон виден в тексте', '+7 (812) 000-11-22' in site)
check('старый номер нигде не остался', '325-69-93' not in site and '3256993' not in site)
check('почта заменена', 'zayavki@iceberg.spb.ru' in site and 'mailto:iceberg@iceberg' not in site)
check('телефон в разметке для поисковиков', '"telephone": "+78120001122"' in site)
vac=req('/vacancy/')
check('телефон отдела кадров НЕ затронут', 'tel:+79112261037' in vac)
check('почта отдела кадров НЕ затронута', 'info@iceberg.spb.ru' in vac)
check('основной телефон на странице вакансий заменён', 'tel:+78120001122' in vac)

print('\n8. Заявки с форм')
log=os.path.join(RUN,'public','leads.log')
rows=[{"Имя":"Иван Петров","Контакт":"+7 921 000-00-01","Сообщение":"Нужен бифлекс, 300 м",
       "Форма":"main-contacts","Страница":"https://iceberg.spb.ru/","Время":"05.09.2026 10:12:00",
       "IP":"1.2.3.4","utm_source":"yandex","utm_campaign":"proizvodstvo"},
      {"Имя":"Ольга","Контакт":"olga@example.com","Сообщение":"—","Форма":"vacancy",
       "Страница":"https://iceberg.spb.ru/vacancy/","Время":"05.09.2026 11:40:00","IP":"5.6.7.8"}]
with open(log,'w',encoding='utf-8') as f:
    for r in rows: f.write(json.dumps(r,ensure_ascii=False)+'\n')
h=req('/admin/?p=leads')
check('заявки подтянулись из журнала', 'Иван Петров' in h and 'Ольга' in h)
check('метки рекламы видны', 'utm_source' in h and 'yandex' in h)
check('счётчик новых в меню', re.search(r"nav__n'>2<", h) is not None)
mid=re.search(r"name=id value='(\d+)'",h).group(1)
req('/admin/?p=leads', {'_csrf':csrf(h),'action':'done','id':mid,'status':'','q':''})
h=req('/admin/?p=leads&status=done')
check('заявка помечена обработанной', 'lead--done' in h)
h=req('/admin/?p=leads')
check('повторный заход не задваивает заявки', h.count('Иван Петров')==1)
csv=req('/admin/?p=leads', {'_csrf':csrf(h),'action':'export','status':'','q':''}, raw=True)
check('выгрузка CSV работает', csv.startswith(b'\xef\xbb\xbf') and 'Иван Петров'.encode() in csv)

print('\n9. Публичная часть цела')
codes={u:subprocess.run(['curl','-s','-o','/dev/null','-w','%{http_code}',BASE+u],capture_output=True).stdout.decode()
       for u in ['/','/tkani-optom/','/contacts/','/vacancy/','/shveinoe-proizvodstvo/','/policy.html',
                 '/mashinnaya-vishivka/','/tkani-print/','/izgotovlenie-startovykh-maek/','/net-takoy/']}
check('все страницы 200, несуществующая 404',
      all(v=='200' for k,v in codes.items() if k!='/net-takoy/') and codes['/net-takoy/']=='404')

print('\n10. Отказ слоя админки не роняет сайт (важно для SEO-переезда)')
import glob, shutil
db = os.path.join(RUN, 'data', 'content.db')
moved = db + '.off'
cache = glob.glob(os.path.join(RUN, 'data', 'cache', 'page_*.html'))
try:
    for f in cache: os.remove(f)
    os.rename(db, moved)
    for extra in (db + '-wal', db + '-shm'):
        if os.path.exists(extra): os.rename(extra, extra + '.off')
    codes = {}
    for u in ['/', '/vacancy/', '/shveinoe-proizvodstvo/', '/policy.html']:
        codes[u] = subprocess.run(['curl','-s','-o','/dev/null','-w','%{http_code}',BASE+u],
                                  capture_output=True).stdout.decode()
    check('при недоступной базе страницы отдаются (200)', all(v == '200' for v in codes.values()))
    got = subprocess.run(['curl','-s',BASE+'/'],capture_output=True).stdout
    want = open(os.path.join(RUN,'app','baseline','index.html'),'rb').read()
    check('и совпадают с эталоном байт-в-байт', got == want)
finally:
    if os.path.exists(moved): os.rename(moved, db)
    for extra in (db + '-wal', db + '-shm'):
        if os.path.exists(extra + '.off'): os.rename(extra + '.off', extra)

vac = os.path.join(RUN, 'app', 'baseline', 'vacancy', 'index.html')
off = vac + '.off'
try:
    for f in glob.glob(os.path.join(RUN,'data','cache','page_*.html')): os.remove(f)
    os.rename(vac, off)
    code = subprocess.run(['curl','-s','-o','/dev/null','-w','%{http_code}',BASE+'/vacancy/'],
                          capture_output=True).stdout.decode()
    check('пропал эталон страницы — 503, а не 404/500 (адрес остаётся в индексе)', code == '503')
    other = subprocess.run(['curl','-s','-o','/dev/null','-w','%{http_code}',BASE+'/contacts/'],
                           capture_output=True).stdout.decode()
    check('остальные страницы при этом живы', other == '200')
finally:
    if os.path.exists(off): os.rename(off, vac)

print('\n' + ('ВСЁ ЧИСТО' if not FAIL else f'ПРОВАЛОВ: {len(FAIL)} → ' + '; '.join(FAIL)))
