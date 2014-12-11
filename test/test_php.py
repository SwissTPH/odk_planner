
import unittest, urllib

from config import config

class TestInstance(unittest.TestCase):

    def test_conditions(self):
        html = urllib.urlopen(config.planner_url + 'test_conditions.php').read()
        assert 'FAILED' not in html

if __name__ == '__main__':
    unittest.main()


