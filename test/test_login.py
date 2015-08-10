
import unittest, time

from config import config
from odk_planner import PlannerDriver
from instance import driver, passwords

class TestLogin(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        driver.logout()

    def test_login_admin(self):
        assert not driver.logged_in()
        driver.login('admin', passwords['admin'])
        assert driver.logged_in()
        assert driver.title() == 'SAMPLE CONFIG'
        items = driver.menu_names()
        assert 'forms' in items
        assert 'admin' in items
        assert 'help' in items
        driver.logout()
        assert 'logged out' in driver.alerts()

    def test_login_secretary(self):
        driver.login('secretary', passwords['secretary'])
        assert 'forms' not in driver.menu_names()
        assert 'admin' not in driver.menu_names()
        assert driver.single_sms_available()
        driver.logout()
        assert 'logged out' in driver.alerts()

    def test_login_fieldofficer(self):
        driver.login('fieldofficer', passwords['fieldofficer'])
        assert driver.title() == 'SAMPLE CONFIG'
        assert 'forms' not in driver.menu_names()
        assert 'admin' not in driver.menu_names()
        assert not driver.single_sms_available()
        driver.logout()
        assert 'logged out' in driver.alerts()

    def test_login_failed(self):
        t0 = time.time()
        driver.login('admin', 'admin')
        assert 'wrong password' in driver.alerts()
        assert time.time() - t0 > 2.

if __name__ == '__main__':
    unittest.main()

