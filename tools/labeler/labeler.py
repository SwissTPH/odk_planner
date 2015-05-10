#!/usr/bin/python

"""Script to generate pages of labels based on Excel spreadsheet specification.

See http://odk-planner.readthedocs.org/en/latest/tools.html#labeler for more
documentation
"""

import sys, os.path, re

def waitdie(msg=''):
    print(msg + '\n\npress ENTER to exit...')
    sys.stdin.readline()
    sys.exit(1)

def install_usage(package):
    print('you must install the python package "{package}" to use this program'
          'install it by typing (in a shell)'
          '> easy_install {package}'.format(package=package))

    if sys.platform == 'win32':
        print('how to : http://www.varunpant.com/posts/'
              'how-to-setup-easy_install-on-windows')

    waitdie()

try:
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.units import mm
    from reportlab.pdfgen import canvas
    from reportlab.graphics.shapes import Drawing
    from reportlab.graphics.barcode.qr import QrCodeWidget
    from reportlab.graphics.barcode.code128 import Code128# as BarCode
    from reportlab.graphics import renderPDF
except ImportError:
    install_usage('reportlab')

try:
    import xlrd
except ImportError:
    install_usage('xlrd')

fbase = os.path.splitext(os.path.basename(sys.argv[1]))[0]
dname = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'output')
wb = xlrd.open_workbook(sys.argv[1])
ws = wb.sheet_by_name('page_layout')
wc = wb.sheet_by_name('label_layout')

# load config
config = {
        wc.cell(y,0).value: [
            wc.cell(y, x).value for x in range(1, wc.ncols)
        ] for y in range(wc.nrows)
    }

def convconf(config, name, n, f=lambda x: float(x)):
    """Gets config values and converts; dies if error
    """
    if not name in config:
        waitdie('missing config option: ' + name)
    for i in range(n):
        try:
            config[name][i] = f(config[name][i])
        except Exception as e:
            waitdie('%d. value of %s : cannot convert "%s" : %s' % (
                i + 1, name, config[name][i], str(e)))
    config[name] = config[name][:n]

# config is a dict of the values on sheet label_layout, accessed by the value
# of the first column; config is checked for presence of required number of
# values and type is enforced to bool/int/float/str
convconf(config, 'idrange', 3, int)
convconf(config, 'drawrect', 1, f=lambda x:bool(int(x)))
convconf(config, 'singlepage', 1, f=lambda x:bool(int(x)))

convconf(config, 'labelsz', 2, lambda x:mm*float(x))
convconf(config, 'labeldim', 2, int)

convconf(config, 'qrsz', 2, lambda x:mm*float(x))
convconf(config, 'qr0', 2, lambda x:mm*float(x))

convconf(config, 'text1', 3, lambda x:mm*float(x))
convconf(config, 'text2', 3, lambda x:mm*float(x))
convconf(config, 'text3', 3, lambda x:mm*float(x))
convconf(config, 'fonts', 3, str)
convconf(config, 'textre', 1, lambda x: re.compile(x))

# for scaling font sizes
ffact = 0.8 / mm

rY = re.compile(r'(.*?)YY+(\d*)(.*)')
def replace(template, patid):
    """Replaces e.g. YYY05 to (patid + 5)
    """
    m = rY.match(template)
    if not m:
        waitdie('invalid template string on page_layout : ' + template)
    pre, num, post = m.groups()
    return pre + str(int(patid) + int(num or '0')) + post

def draw_qr(c, text, x0, y0, w, h):
    """Draws a single QR code.
    """
    q = QrCodeWidget(text)
    b = q.getBounds()
    d = Drawing(w, h, transform=[
            w / (b[2] - b[0]), 0, 0,
            h / (b[3] - b[1]), 0, 0
        ])
    d.add(q)
    renderPDF.draw(d, c, x0, y0)


def label_rect(config, x, y):
    """Calculates the coordinates of a label rectangle in millimeters.
    """
    nx = config['labeldim'][0]
    ny = config['labeldim'][1]
    lw = config['labelsz'][0]
    lh = config['labelsz'][1]
    x0 = (A4[0] - nx * lw) / 2
    y0 = (A4[1] - ny * lh) / 2
    dx = dy = 0
    return (
            x0 + x * (lw+dx),
            y0 + y * (lh+dy),
            x0 + x * (lw+dx) + lw,
            y0 + y * (lh+dy) + lh,
            )

if not config['singlepage'][0]:
    fname = fbase + '_%d_%d' % tuple(config['idrange'][:2])
    path = os.path.join(dname, fname + '.pdf')
    c = canvas.Canvas(path, pagesize=A4)

p0, pn, pd = config['idrange']
patids = [str(p) for p in range(p0, p0 + ((pn - p0 - 1) / pd + 1) * pd, pd)]
for patid in patids:

    msg = 'GENERATING LABELS FOR PATIENT ' + patid
    if pd > 1: msg += '..' + str(int(patid) + pd)
    print('')
    print(msg)
    print('-'*len(msg))

    if config['singlepage'][0]:
        fname = fbase + '_' + patid
        if pd > 1: fname += '_' + str(int(patid) + pd)
        path = os.path.join(dname, fname + '.pdf')
        c = canvas.Canvas(path, pagesize=A4)

    for row in range(config['labeldim'][1]):
        for col in range(config['labeldim'][0]):

            code = replace(ws.cell(row, col).value, patid)
            if not code: continue

            print('generating barcode (%d, %d) "%s"' % (row, col, code))

            r = label_rect(config, col, config['labeldim'][1] - row - 1)
            if config['drawrect'][0]:
                c.rect(r[0], r[1], r[2] - r[0], r[3] - r[1])

            draw_qr(c, code,
                    r[0] + config['qr0'][0],
                    r[1] + config['qr0'][1],
                    config['qrsz'][0],
                    config['qrsz'][1])

            m = config['textre'][0].match(code)
            if not m:
                waitdie('cannot match "%s" to "%s"' % (
                    config['textre'][0], code))
            parts = m.groups()

            for i in range(3):
                if i < len(parts):
                    ts, tx, ty = config['text' + str(i + 1)]
                    c.setFont(config['fonts'][i], ts * ffact)
                    c.drawString(r[0] + tx, r[1] + ty, parts[i])

    c.showPage()
    c.save()

waitdie()

