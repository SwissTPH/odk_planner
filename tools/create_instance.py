"""Creates a new instance with default configuration files

this script creates a new instance with the default configuration files from
the ``demo/config`` directory, asking the user for MySQL credentials, and
also creating a temporary password that has to be used for first login
"""

import sys, ConfigParser, os, os.path, string, shutil, random

if len(sys.argv) > 2 or sum([arg.startswith('-') for arg in sys.argv[1:]]):
    print('\nusage:\n    %s [template.ini]\n' % sys.argv[0])
    sys.exit(-1)

def ask(question, default=None):
    if default is None:
        return raw_input(question)
    else:
        ret = raw_input(question + ' [%s] ' % default)
        if ret:
            return ret
        else:
            return default

def askchoice(question, choices):
    while True:
        answer = raw_input(question + ' (%s) ' % ','.join(choices))
        if answer in choices: return answer

def askyn(question):
    return askchoice(question, ('y', 'n')) == 'y'

def makepass(length):
    chars = string.letters + string.digits
    return ''.join([random.choice(chars) for i in range(length)])

demodir = os.path.join(os.path.dirname(__file__), os.path.pardir, 'test', 'demo')
configdir = os.path.join(demodir, 'config')
xlssrc = os.path.join(configdir, 'config-sample.xls')
inisrc = os.path.join(configdir, 'config-sample.ini')
instancedir = os.path.join(demodir, os.path.pardir, os.path.pardir, 'instances')

# set umask to generate files with group write access
os.umask(2)

# preparse ini
cp = ConfigParser.RawConfigParser()
if len(sys.argv) > 1:
    if not os.path.exists(sys.argv[1]):
        print('\n*** cannot open "%s"\n' % sys.argv[1])
        sys.exit(-2)
    inisrc = sys.argv[1]
cp.read(inisrc)

# ask information, check instance does not exist yet
print('''
this script will create a new odk_planner instance
--------------------------------------------------
''')
instance_name = ask('instance name: ')
instance_root = os.path.join(instancedir, instance_name)
if os.path.exists(instance_root):
    print('CANNOT CREATE instance with name "%s" : path "%s" exists already!' % 
            (instance_name, instance_root))
    raw_input('press <ENTER> to continue...')
    sys.exit(-1)
os.mkdir(instance_root)

# modify config settings
for section in cp.sections():
    print(section + '\n' + '-' * len(section))
    for name, value in cp.items(section):
        value = ask('  - ' + name, cp.get(section, name))
        if ' ' in value and not (
                value.startswith('"') and value.endswith('"')):
            value = '"%s"' % value
        cp.set(section, name, value)

# create directories
for subdir in ('config', 'log', 'forms'):
    os.mkdir(os.path.join(instance_root, subdir))

# copy config files
xlsdst = os.path.join(instance_root, 'config', 'config.xls')
inidst = os.path.join(instance_root, 'config', 'config.ini')
cp.write(file(os.path.join(inidst), 'w'))
shutil.copyfile(xlssrc, xlsdst)

# try setting group id
st = os.stat(instancedir)
os.chown(instance_root, -1, st.st_gid)
for root, dirs, files in os.walk(instance_root):
    for leaf in dirs + files:
        os.chown(os.path.join(root, leaf), -1, st.st_gid)

# generate temporary password
passpath = os.path.join(instance_root, 'config', 'TMPPASS')
tmppass = makepass(8)
file(passpath, 'w').write(tmppass)

# output to user
print('''
generated new instance:

  - name: {instance_name}
  - temporary password: {tmppass}

make sure that the directory "instances/{instance_name}/" and all its contents are
writable by the apache user (this should automatically be the case if the
directory "instances/" has the right group ownership)

'''.format(
        instance_name=instance_name, tmppass=tmppass))

raw_input('press <ENTER> to continue...')

