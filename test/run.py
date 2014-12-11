'''script runs tests after checking initial configuration'''

import os, os.path, sys, logging, unittest, urllib

if 'ODK_PLANNER_CONFIG' not in os.environ:
    path = os.path.join(os.path.dirname(__file__), 'test.cfg')
    if not os.path.exists(path):
        path = os.path.join(os.path.dirname(__file__), 'sample.cfg')
    print('')
    print('environment variable ODK_PLANNER_CONFIG not found')
    print('fall back on config file "%s"' % path)
    os.environ['ODK_PLANNER_CONFIG'] = path

from config import config, lo, version

def fail(msg, print_exc=False):
    sys.stderr.write('\n[31mFATAL ERROR:[m ' + msg + '\n')
    sys.stderr.write('\npress <ENTER>...')
    if print_exc:
        import traceback
        print('')
        traceback.print_exc()
        print('')
    raw_input()
    sys.exit(-1)

try:
    v = urllib.urlopen(config.planner_url + '?ODK_PLANNER_VERSION').read()
    if version != v:
        fail('%s : got version "%s", expected "%s"' % (
            config.planner_url, v, version))
except IOError as e:
    fail('%s : could not open URL' % config.planner_url)

print('''
discovered the following testing configuration (read from {cfg})

  - odk_planner_url: {url} (version {version})
  - instance name: {instance_name}
  - password: {password}'''.format(
        cfg=os.environ['ODK_PLANNER_CONFIG'],
        url=config.planner_url,
        version=version,
        instance_name=config.planner_instance,
        password=config.planner_password))

if config.test_email:
    print('  - will send testing email to %s' % config.test_email)
else:
    print('  - will NOT test sending email (no address configured)')

if config.test_number:
    print('  - will send testing sms to %s' % config.test_number)
else:
    print('  - will NOT test sending sms (no number configured)')
print('')

import instance

print('check instance name...')
title = instance.driver.get_title()
if title is None:
    error = instance.driver.get_error()
    fail('%s : %s' % (config.planner_url, error))

try:
    print('login...')
    instance.driver.login('admin', instance.passwords['admin'])
    if not instance.driver.logged_in():
        raise Exception('failed to log in')
except Exception as e:
    fail('could not log in as admin : ' + str(e), True)
try:
    print('upload config...')
    instance.upload_config(instance.default_config())
except Exception as e:
    fail('could not upload default config : ' + str(e), True)
try:
    print('initialize forms...')
    instance.init_forms()
except Exception as e:
    fail('could not init forms : ' + str(e), True)
missing = instance.driver.form_missing()
if missing:
    fail('the following form(s) could not be found in the database : ' +
            ','.join(missing))

instance.driver.logout()

class DelayedLogHandler(logging.Handler):
    def __init__(self):
        logging.Handler.__init__(self)
        self.messages = []
        self.fmt = logging.Formatter('[%(levelname)s] %(message)s')
        self.setLevel(logging.INFO)
    def emit(self, record):
        self.messages.append(self.fmt.format(record))

dh = DelayedLogHandler()
lo.addHandler(dh)

print('')
print('ready, set go!')
raw_input('press <ENTER> to start tests...')
print('')

suite = unittest.defaultTestLoader.discover(os.path.dirname(__file__))
results = unittest.TextTestRunner(verbosity=2).run(suite)

if dh.messages:
    print('\nlog output during tests:\n')
    print('  ' + '\n  '.join(dh.messages))

raw_input('\nall done; press <ENTER> to exit...')

