import glob, re

pat = re.compile(r'home\.css\?v=\d{8}[a-z]+')
for f in sorted(glob.glob('*.html')):
    with open(f, 'r', encoding='utf-8-sig') as fh:
        content = fh.read()
    if pat.search(content):
        new = pat.sub('home.css?v=20260921s', content)
        with open(f, 'w', encoding='utf-8', newline='') as fh2:
            fh2.write(new)
        print('updated:', f)

