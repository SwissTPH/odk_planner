
import os, os.path, shutil, ConfigParser, tempfile

from config import config, lo, ExcelConfig
from odk_planner import PlannerDriver

driver = PlannerDriver(config.planner_url, config.planner_instance)

root = os.path.join(os.path.dirname(__file__), os.path.pardir)

configdir = os.path.join(os.path.dirname(__file__), 'demo', 'config')
configxls = os.path.join(configdir, 'config-sample.xls')
configini = os.path.join(configdir, 'config-sample.ini')

users = ('admin', 'secretary', 'fieldofficer')
passwords = dict([(user, config.planner_password) for user in users])

xlsforms = dict()
formdir = os.path.join(os.path.dirname(__file__), 'demo', 'forms')
for fname in os.listdir(formdir):
    path = os.path.join(formdir, fname)
    if os.path.isfile(path) and path.endswith('.xls'):
        xlsforms[os.path.splitext(fname)[0]] = path

def default_config():
    ''' loads default ExcelConfig from ``demo/`` and modifies according
        to test config parameters '''
    ec = ExcelConfig(configxls)
    for user in users:
        ec.set_rows('users', 'name', user, 'password', config.planner_password)
    for name, value in config.sms_settings.items():
        ec.set_value('sms', name, value)
    ec.set_value('cron', 'notify_email', config.test_email)
    return ec

def upload_config(config):
    ''' stores ExcelConfig to temporary file and uploads to OdkDriver '''
    dst = os.path.join(tempfile.gettempdir(), 'config.xls')
    config.store(dst, True)
    driver.upload_config(dst)
    os.unlink(dst)

def init_forms():
    ''' deletes all forms, then uploads from ``demo/`` '''
    for formid in driver.formids():
        driver.delete_form(formid)
    for path in xlsforms.values():
        driver.upload_form(path)

