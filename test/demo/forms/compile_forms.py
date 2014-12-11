#!/usr/bin/python

mask = '*.xls' # file mask of .xls files to translate
dstd = 'out'   # where to save .xml files

try:
    import pyxform
except ImportError, e:
    print 'you must install the python package "pyxform" to use this program'
    print 'install it by typing (in a shell)'
    print '> easy_install pyxform'

    import sys
    if sys.platform == 'win32':
        print 'how to : http://www.varunpant.com/posts/how-to-setup-easy_install-on-windows'
    sys.exit(0)

import os, os.path, glob

#os.chdir(os.path.dirname(os.path.abspath(__file__)))

if not os.path.isdir(dstd):
    os.mkdir(dstd)
print 'converting ' + mask + ' to ' + dstd + '/'
for in_file in glob.glob(mask):

    name, ext = os.path.splitext(os.path.basename(in_file))
    out_file = os.path.join(dstd, name + '.xml')

    if os.path.exists(out_file) and os.path.getmtime(out_file) >= os.path.getmtime(in_file):
        print 'skipping %s because %s exists and is newer\n'%(in_file, out_file)
        continue

    warnings = []
    json_survey = pyxform.xls2json.parse_file_to_json(in_file, warnings=warnings)
    survey = pyxform.builder.create_survey_element_from_dict(json_survey)
    survey.print_xform_to_file(out_file, validate=False)

    msg = 'converted %s -> %s'%(in_file, out_file)
    print msg
    print '-' * len(msg)
    for w in warnings:
        print w
    print '\n'

