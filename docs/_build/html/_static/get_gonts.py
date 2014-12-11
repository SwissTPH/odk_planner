
import sys, re

x = re.compile(r"^(?P<before>.*local\('(?P<name>.*?)'\), url\()(?P<url>.*?)(?P<after>\).*)$")
css = ''
sh = ''
for line in sys.stdin.readlines():
    m = x.match(line)
    if m:
        gd = m.groupdict()
        css += gd['before'] + gd['name'] + '.woff' + gd['after']
        sh += 'wget -O %s.woff %s' % (gd['name'], gd['url']) + '\n'
    else:
        css += line

print(css)
print(sh)

