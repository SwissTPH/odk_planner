
import unittest, time, os.path, tempfile, shutil, subprocess, datetime

from config import config, ExcelConfig, lo
from odk_planner import PlannerDriver
from instance import driver, passwords, default_config, upload_config


@unittest.skipIf(not config.php_exec, 'not testing cron because no php_exec defined in test config')
class TestCron(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        root = os.path.join(os.path.dirname(__file__), os.path.pardir)
        cls.cron_php = os.path.join(root, 'cron.php')
        driver.logout()
        driver.login('admin', passwords['admin'])

    def modify_config(self, report_mdays=None, template=None):
        ''' changes config from demo/ and uploads to server
            :param report_mdays: config.xls cron.report_mdays setting
            :param template: will set template_DM to this template and
                activate sending of this template via cron '''
        ec = default_config()

        if report_mdays is not None:
            ec.set_value('cron', 'report_mdays', report_mdays)

        if template is None:
            ec.set_value('sms', 'mode', 'test')
            ec.set_value('sms', 'template_DM', '')
            ec.set_rows('colors', 'list', 'CRF1 submitted more than one week ago',
                    'more', '')
        else:
            ec.set_value('sms', 'mode', 'production')
            ec.set_value('sms', 'template_DM', template)
            ec.set_rows('colors', 'list', 'CRF1 submitted more than one week ago',
                    'more', 'sms:DM!')

        upload_config(ec)

    def run_cron(self):
        subprocess.call([config.php_exec, self.cron_php, '-i', config.planner_instance])

    @unittest.skipIf(not config.test_email, 'no email specified in test config')
    def test_send_email_without_report(self):
        self.modify_config(report_mdays=str(datetime.datetime.now().day + 1))
        self.run_cron()
        lo.info('%s should have received email without report' % config.test_email)

    @unittest.skipIf(not config.test_email, 'no email specified in test config')
    def test_send_email_with_report(self):
        self.modify_config(report_mdays=str(datetime.datetime.now().day))
        self.run_cron()
        lo.info('%s should have received email with report' % config.test_email)

    @unittest.skipIf(not (config.test_email and config.test_number),
            'no email or no number specified in test config')
    def test_send_cron_sms(self):
        template = '+%s odk_planner test_send_cron_sms' % config.test_number
        template += ' : {CRF1\\INFO_STUDY_ID} should be 80006'
        self.modify_config(template=template)
        driver.mass_sms_reset()
        self.run_cron()
        lo.info('cron sent sms to %s (and email without report to %s)' % (
            config.test_number, config.test_email))

if __name__ == '__main__':
    unittest.main()

