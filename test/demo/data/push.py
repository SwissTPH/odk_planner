"""Upload data contained in csv files in local directory to Aggregate server

this script uses the functionality provided by the ``aggregate`` module
that is shipped with ``odk_planner`` and asks the user interactively
for server address and login credentials.

after downloading the ``demo.zip`` and installing Python3 this script can
be double-clicked (in Windows) to populate the database as outlined in
the "Tutorial" chapter int he ``odk_planner`` documentation
"""

import sys

if not sys.version_info[0] == 3:
    print('*** this script needs python3 ***')
    raw_input('press <ENTER>...')
    sys.exit(-1)

import os.path, glob, urllib, io, csv

try:
    import aggregate
except ImportError:
    print('*** could not find aggregate.py ***')
    print('*** make sure its location is added to PYTHONPATH ***')
    input('press <ENTER>...')
    sys.exit(-1)

def yn(question):
    while True:
        x = input(question + ' (yn) ')
        if x == 'y':
            return True
        elif x == 'n':
            return False

def ask(question, default=None):
    if default is None:
        return input(question)
    else:
        ret = input(question + ' [%s] ' % default)
        if ret:
            return ret
        else:
            return default

# .xml forms & .csv data is read from within 'demo/' directory
csvdir = os.path.dirname(__file__)
filedir = os.path.join(os.path.dirname(__file__), 'files')
formdir = os.path.join(csvdir, os.path.pardir, 'forms', 'out')

# check intentions
if not yn('upload data from csv files to Aggregate server?'):
    sys.exit(0)

# ask aggregate address, username, password
server = ask('Aggregate server url', 'http://localhost:8080/ODKAggregate')
username = ask('username', '')
password = ask('password', '')

# create client instance & connect to server
url = urllib.parse.urlparse(server)
port = url.port or (url.scheme == 'https' and 443 or 80)
hostname = url.netloc
if ':' in url.netloc:
    hostname = url.netloc[:url.netloc.index(':')]
    port = int(url.netloc[url.netloc.index(':')+1:])
client = aggregate.AggregateClient(hostname, port, url.path, scheme=url.scheme)
client.connect(username or None, password or None)


def filedict_rek(header, dirpath, filedict, fieldpath=[]):
    ''' recurse through folder structure and add files to ``filedict``,
        indexed by [basename][subdir] '''

    # sub directories for form names
    for fname in os.listdir(dirpath):

        if fname.startswith('.'):
            continue

        path = os.path.join(dirpath, fname)

        if os.path.isdir(path):
            filedict_rek(header, path, filedict, fieldpath + [fname])

        else:
            fieldpathstr = '/'.join(fieldpath)
            base = os.path.splitext(fname)[0]
            filedict.setdefault(base, {})[fieldpathstr] = path


# iterate through all .csv files in local dir or the ones specified
if len(sys.argv) > 1:
    csvpaths = sys.argv[1:]
else:
    csvpaths = glob.glob(os.path.join(csvdir, '*.csv'))

for csvpath in csvpaths:

    formid = os.path.splitext(os.path.basename(csvpath))[0]
    xmlpath = os.path.join(formdir, formid + '.xml')
    formfiledir = os.path.join(filedir, formid)

    if not os.path.isfile(xmlpath):
        print('skipping file "%s" because XForm "%s" not found' % (
            csvpath, xmlpath))
        continue

    # load corresponding .xml form
    with io.open(xmlpath) as fd:
        form_xml = fd.read()
        form = aggregate.XForm(form_xml)

    with io.open(csvpath) as csvfd:

        reader = csv.reader(csvfd)
        header = next(reader)

        # compare .csv header fields with .xml form specification
        idxs = {}
        for i, name in enumerate(header):
            if name in form.paths:
                idxs[name] = i
            else:
                print('field "%s" not found in form "%s" -> IGNORING' % (
                           name, formid))
                sys.exit(-1)

        # create file dict
        filedict = {}
        if os.path.isdir(formfiledir):
            filedict_rek(header, formfiledir, filedict)

        # send form for every row
        for row in reader:
            form = aggregate.XForm(form_xml)
            # fill in values rom csv
            for name in idxs:
                form[name] = row[idxs[name]]
            # add files if found
            if row[0] in filedict:
                for name, path in filedict[row[0]].items():
                    form.set_file(name, path)

            try:
                client.post_multipart(form.get_items())
                print('successfully posted form %s, "%s"' % (formid, row[0]))
            except aggregate.AggregateFormNotFoundException:
                print('could not find form %s on server' % formid)
                break

