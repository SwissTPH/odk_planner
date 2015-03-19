
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
        # after v0.9 index.php tries to parse instance name from path
        # if none is provided as an argument; which is then "not found"
        assert 'fatal error' in html and 'not found' in html

    def test_inexisting_instance(self):
        html = self.get('?instance=__nonexisting')
        assert 'fatal error' in html and 'not found' in html

    def test_test_instance(self):
        assert driver.get_title() is not None

if __name__ == '__main__':
    unittest.main()

