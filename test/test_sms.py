
import unittest, time, os.path, tempfile, shutil, re

from config import config, ExcelConfig, lo
from odk_planner import PlannerDriver
from instance import driver, passwords, default_config, upload_config


class TestSms(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        driver.logout()
        driver.login('admin', passwords['admin'])

    def modify_config(self, production, more=None, template=None):
        ''' changes config from demo/ and uploads to server
            :param production: whether to set sms 'production' mode
            :param more: what to write in 'more' cell in sms demonstration entry
            :param template: change content of template_DM '''
        ec = default_config()
        ec.set_value('sms', 'mode', production and 'production' or 'test')
        if more is not None:
            ec.set_rows('colors', 'comments', 'send sms', 'more', more)
        if template is not None:
            ec.set_value('sms', 'template_DM', template)
        upload_config(ec)

    def test_mass_sms(self):
        self.modify_config(False, '')
        driver.mass_sms_reset()
        assert not driver.has_mass_sms()
        template = 'glc={LRF1\\BLOOD_GLUCOSE}'
        template += ' weight={CRF2\\SIGNS_WEIGHT}'
        template += ' allergy={CRFX\\XRAY_ALLERGY}'
        self.modify_config(False, 'sms:DM', template)
        driver.mass_sms_reset()
        assert driver.has_mass_sms()
        driver.mass_sms_go()
        alerts = driver.alerts()
        assert len(alerts) == 1 and 'will not actually send' in alerts[0]
        assert driver.mass_sms_send() == 2
        message = driver.mass_sms_messages()[0]
        assert re.match(r'glc=[\d.]+ weight=\(masked\) allergy=NULL', message)

    def test_single(self):
        self.modify_config(False, '')
        numbers = driver.single_sms_go('80001')
        alerts = driver.alerts()
        assert len(alerts) == 1 and 'will not actually send' in alerts[0]
        assert numbers == ['255123456789', '255987654321']
        alert = driver.single_sms_send('message', 1)
        assert 'sent message' in alert and numbers[1] in alert

    @unittest.skipIf(not config.test_number, 'no number specified in test config')
    def test_field_number_real(self):
        template = '+%s odk_planner test_field_number_real' % config.test_number
        template += ' : {CRF1\\INFO_STUDY_ID} should be 8000(3,4)'
        self.modify_config(True, 'sms:DM', template)
        driver.mass_sms_reset()
        driver.mass_sms_go()
        driver.mass_sms_send()
        lo.info('sent two sms to %s' % config.test_number)


if __name__ == '__main__':
    unittest.main()

