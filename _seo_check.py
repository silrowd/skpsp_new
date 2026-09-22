# -*- coding: utf-8 -*-
import io, re, os, sys

os.chdir(os.path.dirname(os.path.abspath(__file__)))

def tag_len(s):
    s = re.sub(r'<[^>]+>', '', s)
    return len(s)

files = [
    'index.html', 'clients.html', 'public-buildings.html', 'residential.html',
    'industrial.html', 'reconstruction.html', 'shopping-malls.html',
    'news-severnaya-palitra-2015.html', 'news-oyunost-2015.html',
    'vacancy-production-manager.html', 'vacancy-site-master.html',
    'vacancy-pot-engineer.html', 'vacancy-surveyor.html', 'vacancy-electrician.html',
]

for f in files:
    try:
        html = io.open(f, encoding='utf-8').read()
    except OSError as e:
        print('==== %s  ERROR %s' % (f, e))
        continue
    print('==== ' + f)
    m = re.search(r'<title>(.*?)</title>', html, re.S)
    if m:
        print('  title   [%d] %s' % (tag_len(m.group(1)), m.group(1)))
    for name, pat in [('desc', r'name="description" content="(.*?)"'),
                      ('ogt', r'property="og:title" content="(.*?)"'),
                      ('ogd', r'property="og:description" content="(.*?)"')]:
        m = re.search(pat, html)
        if m:
            print('  %-7s [%d] %s' % (name, len(m.group(1)), m.group(1)))
    # geostroy check
    for i, line in enumerate(html.splitlines(), 1):
        if 'geostroy' in line.lower():
            print('  GEOSTROY line %d: %s' % (i, line.strip()[:140]))
