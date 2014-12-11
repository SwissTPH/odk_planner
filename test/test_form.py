
import unittest, time, os.path, tempfile

from config import config, ExcelConfig
from odk_planner import PlannerDriver
from instance import driver, xlsforms, passwords


def compare(path1, path2):
    if os.path.getsize(path1) != os.path.getsize(path2):
        return False
    return file(path1).read() == file(path2).read()

here = os.path.dirname(__file__)
forms = dict(
        wrong_name=os.path.join(here, 'forms', 'wrong_name', 'CRF1x.xls'),
        db_only=os.path.join(here, 'forms', 'db_only', 'CRF1.xls'),
        xls_only=os.path.join(here, 'forms', 'xls_only', 'CRF1.xls'),
    )

class TestForm(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        driver.logout()
        driver.login('admin', passwords['admin'])

    def test_form_download(self):
        path = driver.download_form('CRF1')
        assert compare(path, xlsforms['CRF1'])

    def test_form_remove_upload(self):
        assert 'CRF1' in driver.formids()
        driver.delete_form('CRF1')
        assert not 'CRF1' in driver.formids()
        driver.upload_form(xlsforms['CRF1'])
        assert 'CRF1' in driver.formids()

    def test_form_wrong_name(self):
        # the forms are matched to the Aggregate database using their
        # NAME not FORMID...
        driver.delete_form('CRF1')
        driver.upload_form(forms['wrong_name'])
        assert driver.form_missing() == ['CRF1']
        driver.delete_form('CRF1')
        driver.upload_form(xlsforms['CRF1'])

    def test_form_db_only(self):
        driver.delete_form('CRF1')
        driver.upload_form(forms['db_only'])
        d = driver.form_db_only()
        assert d == dict(CRF1=['INFO_FULL_NAME'])
        driver.delete_form('CRF1')
        driver.upload_form(xlsforms['CRF1'])

    def test_form_xls_only(self):
        driver.delete_form('CRF1')
        driver.upload_form(forms['xls_only'])
        d = driver.form_xls_only()
        assert d == dict(CRF1=['INFO_FULL_NAME2'])
        driver.delete_form('CRF1')
        driver.upload_form(xlsforms['CRF1'])

    def test_missing_alert(self):
        driver.overview_go('All')
        assert driver.alerts() == []
        driver.delete_form('CRF1')
        driver.overview_go('All')
        alerts = driver.alerts()
        assert len(alerts) == 2
        assert len([alert for alert in alerts if 'not found' in alert]) == 1
        assert len([alert for alert in alerts if '.xls not uploaded' in alert]) == 1
        driver.upload_form(xlsforms['CRF1'])


if __name__ == '__main__':
    unittest.main()

