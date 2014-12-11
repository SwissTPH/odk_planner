
import unittest, time, os.path, csv

from config import config
from odk_planner import PlannerDriver
from instance import driver, passwords

class TestOverview(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        driver.logout()
        driver.login('admin', passwords['admin'])
        driver.overview_go('All')

    def test_overview_all(self):
        overview = driver.overviews()[0]
        # check we have all forms in specified order
        assert overview.cols == ['CRF1', 'CRF1C', 'CRF2', 'CRFX', 'LRF1']
        # check we have all patient IDs
        for i in range(1, 7):
            assert '8000%d' % i in overview.rows
        for i in range(1, 5):
            assert '8300%d' % i in overview.rows

    def test_overview_cases_controls(self):
        driver.overview_go('Controls')
        overview = driver.overviews()[0]
        # check we have all forms in specified order
        assert overview.cols == ['CRF1C', 'CRF2', 'CRFX', 'LRF1']
        # check we have all patient IDs
        for i in range(1, 7):
            assert not '8000%d' % i in overview.rows
        for i in range(1, 5):
            assert '8300%d' % i in overview.rows

    def test_overview_cases(self):
        driver.overview_go('Cases')
        overview = driver.overviews()[0]
        # check we have all forms in specified order
        assert overview.cols == ['CRF1', 'CRF2', 'CRFX', 'LRF1']
        # check we have all patient IDs
        for i in range(1, 7):
            assert '8000%d' % i in overview.rows
        for i in range(1, 5):
            assert not '8300%d' % i in overview.rows
        driver.overview_go('All')

    def test_highlight_static(self):
        overview = driver.overviews()[0]
        assert overview.css('80006', None, 'background-color') == 'transparent'
        # greyed-out because control
        assert overview.css('83001', None, 'background-color') != 'transparent'

    def test_highlight_timing(self):
        overview = driver.overviews()[0]
        assert overview.css('80005', 'CRF2', 'background-color') == 'transparent'
        # red because >1w delay
        assert overview.css('80006', 'CRF2', 'background-color') != 'transparent'

    def test_highlight_condition(self):
        overview = driver.overviews()[0]
        assert overview.css('80005', 'LRF1', 'border-left') == ''
        # red border because case with negative TB antigen test
        assert overview.css('80006', 'LRF1', 'border-left') != ''

    def test_download_form(self):
        path = driver.overview_download('All')
        r = csv.reader(file(path))
        data = [row for row in r]
        assert len(data) == 2
        assert data[1][0] == 'CRF2'
        assert data[1][1] == '80006'

if __name__ == '__main__':
    unittest.main()

