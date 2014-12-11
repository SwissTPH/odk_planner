
import unittest, time, os.path, tempfile

from config import config, ExcelConfig
from odk_planner import PlannerDriver
from instance import driver, passwords


class TestData(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        driver.logout()
        driver.login('admin', passwords['admin'])

    def test_data_CRFX(self):
        driver.overview_go('All')
        data = driver.data_dict('80001', 'CRFX')
        assert data['xray']['cxr'] == '80001.jpg view as CXR'
        assert data['xray']['allergy'] == 'NULL'

    def test_data_CRF2(self):
        driver.overview_go('All')
        data = driver.data_dict('80001', 'CRF2')
        assert set(data.keys()) == set(['', 'symptoms', 'signs'])
        assert data['']['study_id'] == '80001' # text
        assert data['']['completion_date'] == '2014-01-01 00:00:00' # today
        assert set(data['symptoms']['symptoms'].split(' ')) == set([
            'chest_pain', 'fever', 'productive_cough']) # select_multiple
        assert data['signs']['temperature'].startswith('38.3') # decimal
        #TODO geopoint

    def test_access(self):
        driver.overview_go('All')
        data = driver.data_dict('80001', 'CRF1')
        assert data['info']['full_name'] == 'Alice Armstrong'
        driver.logout()
        driver.login('secretary', passwords['secretary'])
        data = driver.data_dict('80001', 'CRF1')
        assert data['info']['full_name'] == '(masked)'
        driver.logout()
        driver.login('admin', passwords['admin'])


if __name__ == '__main__':
    unittest.main()


