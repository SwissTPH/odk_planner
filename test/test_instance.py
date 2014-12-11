
import unittest, urllib

from config import config, ConfigException
from instance import driver

class TestInstance(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        driver.logout()

    def get(self, uri):
        return urllib.urlopen(config.planner_url + uri).read()

    def test_no_instance(self):
        html = self.get('')
        assert 'fatal error' in html and 'no instance specified' in html

    def test_inexisting_instance(self):
        html = self.get('?instance=__nonexisting')
        assert 'fatal error' in html and 'not found' in html

    def test_test_instance(self):
        assert driver.get_title() is not None

if __name__ == '__main__':
    unittest.main()

