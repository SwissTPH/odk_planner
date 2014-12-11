'''
classes to read / manipulation configuration files during testing
of odk_planner
'''

import xlrd, xlwt

import os, os.path, tempfile, ConfigParser, urllib, logging


class ConfigException(Exception):
    pass

class ExcelConfig:

    ''' read / modify odk_planner excel configuration '''

    def __init__(self, fname):
        wb = xlrd.open_workbook(fname)
        self.sheets = {}
        for sheet in wb.sheets():
            dst = self.sheets[sheet.name] = [
                    [sheet.cell(row, col).value for col in range(sheet.ncols)]
                    for row in range(sheet.nrows)
                ]


    def store_tmp(self):
        tfn = tempfile.NamedTemporaryFile(suffix='.xls', delete=False)
        self.store(tfn.name, True)
        return tfn.name

    def store(self, fname, overwrite=False):
        assert not os.path.exists(fname) or overwrite
        wb = xlwt.Workbook()
        for name, sheet in self.sheets.items():
            dst = wb.add_sheet(name)
            for row in range(len(sheet)):
                for col in range(len(sheet[row])):
                    dst.write(row, col, sheet[row][col])
        wb.save(fname)


    def add_row(self, sheet, data):
        ''' adds a new row to the specified sheet

            :param sheet: sheet name
            :param data: dictionary mapping column headers to row data
        '''
        sheet = self.sheets[sheet]
        # transform data to list
        header = sheet[0]
        newrow = [''] * len(header)
        for key, value in data.items():
            newrow[header.index(key)] = value
        # add empty row at end
        sheet.append([''] * len(header))
        # find first empty row
        row = 0
        while sheet[row][0]:
            row += 1
        # update values
        sheet[row][:len(newrow)] = newrow


    def get_rows(self, sheet, where, condition):
        ''' returns a list of dictionaries of all rows where the
            ``where`` column is equal to ``condition`` '''
        rows = []
        sheet = self.sheets[sheet]
        header = [x.lower() for x in sheet[0]]
        idx = header.index(where.lower())
        for row in range(1, len(sheet)):
            if sheet[row][idx] == condition:
                rows.append(dict(zip(header, sheet[row])))
        return rows

    def set_rows(self, sheet, where, condition, name, value):
        ''' updates one field in one or multiple rows in a sheet where
            values are stored in tabular form

            :param sheet: worksheet name
            :param where: name of column on which is selected
            :param condition: value to match (in column specified by 'where')
            :param name: name of the column to update
            :param value: new value
        '''

        sheet = self.sheets[sheet]
        header = [x.lower() for x in sheet[0]]
        idx = header.index(where.lower())
        idx2 = header.index(name.lower())
        for row in range(1, len(sheet)):
            if sheet[row][idx] == condition:
                sheet[row][idx2] = value


    def set_value(self, sheet, key, value):
        self.set_rows(sheet, 'key', key, 'value', value)


def addslash(s):
    if s.endswith('/'):
        return s
    else:
        return s + '/'


class TestConfig:

    ''' load testing configuration '''

    def __init__(self, fname):
        self.cp = ConfigParser.RawConfigParser()
        self.cp.read(fname)
        here = os.path.dirname(__file__)
        self.log_file = os.path.join(here, self.cp.get('test', 'log_file'))
        self.planner_url = addslash(self.cp.get('test', 'planner_url'))
        self.planner_password = self.cp.get('test', 'planner_password')
        self.planner_instance = self.cp.get('test', 'planner_instance')
        self.php_exec = self.cp.get('test', 'php_exec')
        self.test_number = self.cp.get('test', 'number')
        self.test_email = self.cp.get('test', 'email')
        self.sms_settings = dict([
                (param, self.cp.get('sms', param)) for param in
                ('url', 'response_regexp', 'param_message', 'param_number', 'params')
            ])


root = os.path.join(os.path.dirname(__file__), os.path.pardir)

version = file(os.path.join(root, 'VERSION')).read().strip()

config = TestConfig(os.environ['ODK_PLANNER_CONFIG'])

lo = logging.getLogger('odk_planner')
lo.setLevel(logging.DEBUG)
lo.handlers = []
fh = logging.FileHandler(config.log_file)
fh.setLevel(logging.DEBUG)
ft = logging.Formatter('[%(asctime)s] -%(levelname)s- %(filename)s:%(lineno)d(%(funcName)s) :: %(message)s')
fh.setFormatter(ft)
lo.addHandler(fh)
lo.debug('loaded config')


