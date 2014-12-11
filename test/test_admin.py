
import unittest, time, os.path, tempfile

from config import config, ExcelConfig
from odk_planner import PlannerDriver
from instance import driver, passwords, default_config, upload_config


class TestAdmin(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        driver.logout()

    def test_create_user(self):
        driver.login('admin', passwords['admin'])
        ec = default_config()
        userdict = dict(name='blah', password='blah123',
                rights='overview')
        ec.add_row('users', userdict)
        upload_config(ec)

        driver.logout()
        assert not driver.logged_in()
        driver.login('blah', 'blah123')
        assert driver.logged_in()
        driver.logout()

    def test_user_log(self):
        driver.login('secretary', passwords['secretary'])
        assert driver.logged_in()
        driver.logout()
        driver.login('admin', passwords['admin'])
        userlog = driver.log_messages('user')
        assert userlog.index('user "secretary" logged in') == 2
        assert userlog.index('user "secretary" logged out') == 1
        driver.logout()


if __name__ == '__main__':
    unittest.main()


