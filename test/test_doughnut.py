
import unittest, time, os.path, tempfile, shutil, re

from config import config, ExcelConfig, lo
from odk_planner import PlannerDriver
from instance import driver, passwords, default_config, upload_config


class TestDoughnut(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        driver.logout()
        driver.login('admin', passwords['admin'])

    def test_access(self):
        driver.get('')
        assert 'doughnut' in driver.menu_names()
        driver.menu_go('doughnut')
        assert len(driver.els_xpath('//canvas')) == 6
        driver.logout()
        driver.login('overview', passwords['overview'])
        assert 'doughnut' not in driver.menu_names()
        assert len(driver.els_xpath('//canvas')) == 0
        driver.logout()
        driver.login('admin', passwords['admin'])

    def test_config(self):
        ec = default_config()
        ec.add_row('doughnut', {'key': 'empty', 'value':''})
        upload_config(ec)
        driver.menu_go('doughnut')
        alerts = driver.alerts()
        assert len(alerts) == 1
        assert 'expected' in alerts[0]
        assert len(driver.els_xpath('//canvas')) == 6
        ec = default_config()
        upload_config(ec)
        ec.add_row('doughnut', {'key': 'unknown', 'value':'FORMX\\FIELDX value1'})
        upload_config(ec)
        driver.menu_go('doughnut')
        alerts = driver.alerts()
        assert len(alerts) == 1
        assert 'unknown' in alerts[0]
        assert len(driver.els_xpath('//canvas')) == 6
        ec = default_config()
        upload_config(ec)

    def test_add(self):
        ec = default_config()
        ec.add_row('doughnut', {'key': 'colony_count_2',
                                'value':'LRF1\\SPUTUM_COLONY_COUNT negative'})
        upload_config(ec)
        driver.menu_go('doughnut')
        alerts = driver.alerts()
        assert len(alerts) == 0
        assert len(driver.els_xpath('//canvas')) == 7
        ec = default_config()
        upload_config(ec)


if __name__ == '__main__':
    unittest.main()

