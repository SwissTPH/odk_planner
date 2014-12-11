"""Automated upload from a MS-SQL database to ODK Aggregate

This Python3 GUI program uses the ``aggregate`` module to upload new rows in
a MS-SQL database to an ODK Aggregate server. See the documentation in
odk_planner:

http://odk_planner.readthedocs.org/en/latest/tools.html#mssql_uploader

The default configuration is named ``mssql_uploader.json`` in the current
working directory but can be superseeded as a command line argument.

This program needs the library ``pypyodbc`` to be installed; get it from

https://code.google.com/p/pypyodbc/
"""

VERSION = '1.0.0'


import json, io, os.path, sqlite3, urllib, unittest, threading, time, datetime

import tkinter as tk, tkinter.messagebox, tkinter.ttk

import pypyodbc

from log import lo, LogFrame, init_log, log_e, tic, toc
from aggregate import XForm, XFormException
from gui import ScrolledListbox, FieldsGui, guierror
from aggregate import AggregateException, AggregateClient


## config {{{1

class ConfigException(Exception):
    """Risen by Config if configuration contains errors"""

def extract_remove(data, keys):
    """extract key, remove value or empty dictionary"""
    if isinstance(keys, str):
        keys = [keys]

    # find value
    datas = [data]
    for i, key in enumerate(keys):
        if not key in datas[-1]:
            raise ConfigException('missing key : ' + str(keys[:i+1]))
        datas.append(datas[-1][key])

    if isinstance(datas[-1], dict):
        raise ConfigException('value expected, dict found : ' + str(keys))

    # remove values
    del datas[-2][keys[-1]]
    i = len(datas) - 2
    while i > 0:
        if not datas[i]:
            del datas[i-1][keys[i-1]]
        i -= 1
    return datas[-1]


class Config:
    """Parse a JSON configuration"""

    def __init__(self, filename):
        """Read JSON configuration from specified file

        Rises ConfigException if errors are encountered
        """

        class ConfigNode:
            pass

        try:
            with io.open(filename) as fd:
                data = json.load(fd)
        except IOError as e:
            raise ConfigException('could not read from file : ' + filename)
        except ValueError as e:
            raise ConfigException('not valid JSON file : ' + str(e))

        # uploader settings
        self.title = extract_remove(data, 'title')
        self.interval = extract_remove(data, 'interval')
        self.dryrun = extract_remove(data, 'dryrun')

        # odk settings
        server = extract_remove(data, ['odk', 'server'])
        url = urllib.parse.urlparse(server)
        if not url.scheme or not url.hostname or not url.path:
            raise ConfigException('incomplete server address "%s"' % server +
                    ' : you must specify scheme (http/https), server, URI')
        self.odk = ConfigNode()
        self.odk.scheme = url.scheme
        self.odk.hostname = url.hostname
        self.odk.port = url.port or (url.scheme == 'https' and 443 or 80)
        self.odk.path = url.path

        self.odk.username = extract_remove(data, ['odk', 'username'])
        self.odk.password = extract_remove(data, ['odk', 'password'])

        # MS-SQL settings
        self.mssql = ConfigNode()
        self.mssql.database = extract_remove(data, ['mssql', 'database'])

        self.mssql.server = extract_remove(data, ['mssql', 'server'])
        if len(self.mssql.server.split('\\')) != 2:
            raise ConfigException('server address should have form ' +
                    '"SERVER\\SQLINSTANCE"')

        self.mssql.username = extract_remove(data, ['mssql', 'username'])
        self.mssql.password = extract_remove(data, ['mssql', 'password'])

        self.sqlitedb = extract_remove(data, 'sqlitedb')
        if not os.path.isfile(self.sqlitedb):
            raise ConfigException('cannot access database file "%s"' %
                    self.sqlitedb)

        # tables
        if not 'tables' in data:
            raise ConfigException('missing key : tables')

        self.sqls = {}
        self.xforms = {}
        self.rownames = {}
        self.rowids = {}

        names = [name for name in data['tables'].keys()]
        for name in names:

            xform = extract_remove(data, ['tables', name, 'xform'])
            sql = extract_remove(data, ['tables', name, 'sql'])
            self.rownames[name] = extract_remove(data, ['tables', name, 'rowname'])
            self.rowids[name] = extract_remove(data, ['tables', name, 'rowid'])

            try:
                with io.open(xform) as fd:
                    self.xforms[name] = fd.read()
                XForm(self.xforms[name])
            except (XFormException, IOError) as e:
                raise ConfigException('could not load XForm "%s" : %s' % (
                        xform, str(e)))

            try:
                with io.open(sql) as fd:
                    self.sqls[name] = fd.read()
            except IOError as e:
                raise ConfigException('could not load sql file "%s" : %s' % (
                        sql, str(e)))

        for key in data:
            lo.warning('ignoring config key : ' + key)

class TestConfig(unittest.TestCase):

    def test_extract_remove(self):
        d = dict(name='one', node=dict(name='two', node=dict(name='three')))
        assert extract_remove(d, 'name') == 'one'
        assert extract_remove(d, ['node', 'node', 'name']) == 'three'
        assert len(d['node']) == 1
        assert extract_remove(d, ['node', 'name']) == 'two'
        assert not d

    def test_config(self):
        c = Config('mssql_uploader-sample.json')


## model {{{1

class SqlTableException(Exception):
    """Risen when sql/table have wrong format"""

class SqlTable:
    """Fetches data piecewise from a SQL table"""

    def __init__(self, conn, sql, ids, names, xform):
        """:param conn: py[py]odbc connection
        :param str sql: sql string that embraces the columns with a
            ``--<columns>`` and ``--</columns>`` and contains a ``{where}``
            that will be replaced with an additional T-SQL ``WHERE``
            expression. the SQL statement shoul end as such that an ``ORDER
            BY`` clause can be attached
        :param array ids: (list of) column name(s) that identify a row;
            these will be used to query the tables for new rows, therefore
            they have to be incremented when new rows are added
        :param array names: (list of) column name(s) that are used to create
            the :py:methd:`rowname`
        """

        if isinstance(ids, str):
            ids = [ids]

        if isinstance(names, str):
            names = [names]

        self.ids = ids
        self.names = names
        self.sql = sql
        self.conn = conn
        self.xform = xform

        # parse SQL statement
        try:
            c1 = '--<columns>'
            c2 = '--</columns>'
            fmt = '{where}'
            sql = sql.replace(';', '').strip()
            sql_select = sql[:sql.index(c1)]
            sql_columns = sql[sql.index(c1) + len(c1):sql.index(c2)]
            sql_end = sql[sql.index(c2) + len(c2):]
            sql_end.index(fmt)
        except (ValueError, KeyError) as e:
            raise SqlTableException('malformed SQL : expected to find ' +
                    '"%s", "%s", and "%s"' % (c1, c2, fmt))

        # generate WHERE expression
        where = '('
        for idname in ids[:-1]:
            where += '%s>? OR (%s=? AND (' % (idname, idname)
        where += '%s>?' % ids[-1]
        where += ')' * (len(ids) * 2 - 1)

        # generate ORDER BY expression
        order_by = '\n  ORDER BY ' + ', '.join(ids)

        self.sql_count = (
                sql_select + ' COUNT(*) ' +
                sql_end.format(where='1=1')
            )
        self.sql = (
                sql_select + ' TOP 1 ' + sql_columns +
                sql_end.format(where=where) + order_by
            )

        # check SQL and get column description
        try:
            cur = conn.cursor()
            cur.execute(self.sql_count)

            cur = conn.cursor()
            #io.open('tmp.sql', 'w').write(self.sql)
            self.lastids = [-1] * len(ids)
            cur.execute(self.sql, self.lastids_as_params)

            self.description = cur.description
            self.colnames = [d[0].lower() for d in self.description]

        except (pypyodbc.ProgrammingError, SyntaxError) as e:
            raise SqlTableException('cannot execute T-SQL statement : ' + str(e))

        # find index of id columns
        self.ididxs = []
        self.nameidxs = []
        for i, idname in enumerate(ids + names):
            if '.' in idname:
                idname = idname[idname.rindex('.')+1:]
            if not idname.lower() in self.colnames:
                raise SqlTableException('unknown id/name column "%s"' % idname)
            if i < len(ids):
                self.ididxs.append(self.colnames.index(idname.lower()))
            else:
                self.nameidxs.append(self.colnames.index(idname.lower()))

    @property
    def lastids_as_params(self):
        """transforms :py:attr:`lastids` to be passed as parameters"""
        ret = []
        for lastid in self.lastids[:-1]:
            ret += [lastid, lastid]
        return ret + [self.lastids[-1]]

    def get_next_new(self):
        """get next row of new data from table"""
        cur = self.conn.cursor()
        cur.execute(self.sql, self.lastids_as_params)
        row = cur.fetchone()
        if row is not None:
            for i in range(len(self.ids)):
                self.lastids[i] = row[self.ididxs[i]]
            return row

    def count(self):
        cur = self.conn.cursor()
        cur.execute(self.sql_count)
        return cur.fetchone()[0]

    def rowname(self, row):
        return '/'.join([str(row[idx]) for idx in self.nameidxs])

    def rowid(self, row):
        return '/'.join([str(row[idx]) for idx in self.ididxs])

    def parse_datetime(self, value):
        try:
            value = datetime.datetime.strptime(
                    '2013/01/02 23:44:08.843',
                    '%Y/%m/%d %H:%M:%S.%f')
            return value
        except ValueError:
            lo.debug('could not convert _date/_time value %s' % value)
            return value

    def fill_xform(self, row):
        xform = XForm(self.xform)
        ignored = []
        lowerpaths = [path.lower() for path in xform.paths]
        for i, colname in enumerate(self.colnames):
            if colname in lowerpaths:
                idx = lowerpaths.index(colname)
                path = xform.paths[idx]
                # are stored as varchar(24)...
                if colname.endswith('_date') or colname.endswith('_time'):
                    value = self.parse_datetime(row[i])
                else:
                    value = row[i]
                xform[path] = value
            else:
                ignored.append(colname)
        if ignored:
            lo.debug('created XForm : ignored %d values from db : %s' % (
                    len(ignored), ignored))
        return xform


class MsSqlModelException(Exception):
    """Risen when MsSqlModel data structures are inconsistent"""

class MsSqlModel:
    """Represents data in MS-SQL database, plus ``done`` flag"""

    SQLITE_TABLE = 'mssql_model_processed'
    SQLITE_SCHEMA = '''CREATE TABLE %s (
            tablename TEXT,
            rowid TEXT,
            whenupdated TEXT
        )
        ''' % SQLITE_TABLE

    def __init__(self, conn, sqls, rowids, rownames, sqlite_fname, xforms):
        """:param pypyodbc conn: database connection for reading out the
            table data
        :param dict sqls: with sql code to readout the ``conn`` database,
            indexed by representative name; see :py:class:`SqlTable`
        :param dict rowids: what column(s) should be used to identify
            rows internally
        :param dict rownames: what column(s) should be used to display
            rows
        :param sqlite3 sqlite: database connection to save the names of
            the rows already sent
        :param dict xforms: ``.xml`` source of forms that will be filled
            with data from table
        """
        self.sqlites = {}
        self.sqlite_fname = sqlite_fname
        self.conn = conn

        try:
            self.sqlite.cursor().execute('SELECT * FROM ' + self.SQLITE_TABLE)
        except sqlite3.OperationalError:
            raise MsSqlModelException('could not find table ' + self.SQLITE_TABLE)

        self.tables = {}
        self.done = {}
        self.done_n = {name: 0 for name in sqls}
        for name, sql in sqls.items():
            self.tables[name] = SqlTable(self.conn, sql, rowids[name],
                    rownames[name], xforms[name])

            curs = self.sqlite.cursor()
            curs.execute('SELECT rowid FROM %s WHERE tablename=?' % self.SQLITE_TABLE,
                    (name,))
            self.done[name] = set([str(row[0]) for row in curs])

    @property
    def sqlite(self):
        ident = threading.current_thread().ident
        if not ident in self.sqlites:
            self.sqlites[ident] = sqlite3.connect(self.sqlite_fname)
        return self.sqlites[ident]

    def get_next_new(self, name):
        """get next row of new data from specified table
        
        data is considered new if it has not yet been asked in current session
        (else it would be filtered out by :py:class:`SqlTable`) and if it has
        not never been marked as done (via :py:meth:`mark_done`)
        """
        row = self.tables[name].get_next_new()
        while row is not None:
            rowid = self.tables[name].rowid(row)
            if rowid not in self.done[name]:
                return row
            row = self.tables[name].get_next_new()

    def get_description(self, name):
        """get row description of table"""
        return self.tables[name].description

    def mark_done(self, name, row):
        """marks the ``rowid`` in the specified table ``name`` to be processed"""
        rowid = self.tables[name].rowid(row)
        cur = self.sqlite.cursor()
        cur.execute('INSERT INTO %s (tablename, rowid, whenupdated) '
                % self.SQLITE_TABLE
                + 'VALUES (?, ?, datetime("now"))', (name, rowid))
        self.sqlite.commit()
        self.done[name].add(rowid)
        self.done_n[name] += 1

    def count(self, name):
        return self.tables[name].count()

    def count_done_total(self, name):
        cur = self.sqlite.cursor()
        cur.execute('SELECT COUNT(*) FROM %s WHERE tablename=?' % self.SQLITE_TABLE,
                (name,))
        return cur.fetchone()[0]

    def count_done_now(self, name):
        return self.done_n[name]

    @classmethod
    def create_table(cls, sqlite):
        """creates empty database if it does not exist yet

        :param sqlite: sqlite3 database connection
        """
        cur = sqlite.cursor()
        cur.execute(cls.SQLITE_SCHEMA)
        sqlite.commit()


## gui {{{1

def after(f):
    """Decorator that calls function with 1ms delay from GUI thread"""
    def g(self, *args):
        self.win.after(1, f, self, *args)
    return g

class MainGui:
    """Main window showing number of rows already transmitted and log messages"""

    def __init__(self, model, config, uploader):

        self.model = model
        self.config = config
        self.uploader = uploader

        self.win = tk.Tk()
        self.wm_title('MS-SQL ODK Uploader')
        self.win.protocol('WM_DELETE_WINDOW', self.initiate_join_exit)

        ## label grid

        grid = tk.Frame(self.win)
        grid.pack()
        self.labels = {}
        rowlabels = (
                ('mssql', 'in database'),
                ('done_now', 'uploaded since startup'),
                ('done_total', 'uploaded in total')
            )

        for column, name in enumerate(model.tables.keys()):
            label = tk.Label(grid, text=name)
            label.grid(row=0, column=column + 1)
        row = 0
        for rowname, rowlabel in rowlabels:
            row += 1
            self.labels[rowname] = {}
            label = tk.Label(grid, text=rowlabel)
            label.grid(row=row + 1, column=0, sticky='w')

            for column, name in enumerate(sorted(model.tables.keys())):
                self.labels[rowname][name] = label = tk.Label(grid)
                label.grid(row=row + 1, column=column + 1)

        ## log widget

        self.text = LogFrame(self.win, lo)
        self.text.pack(fill='both', expand=True, pady=5)

        ## statusbar

        #status = tk.Frame(self.win)
        #status.pack(fill='x', padx=5)
        #self.status_text = tk.Label(status, width=20)
        #self.status_text.pack(side='left')
        #self.status_progress = tkinter.ttk.Progressbar(status, orient='horizontal')
        #self.status_progress.pack(side='right', fill='x', expand=True)

        self.uploader.add_callback(self.update)
        self.update()

    def init(self):
        lo.info('gathering initial data from MS-SQL database...')

    @after
    def update_labels(self, labeldata):
        # update GUI in tkinter thread
        for rowname, row in labeldata.items():
            for name, label in row.items():
                self.labels[rowname][name].config(text=label)

    def update(self):
        # get data in calling thread
        labeldata = {}
        for rowname, row in self.labels.items():
            rowdata = labeldata[rowname] = {}
            for name, label in row.items():
                if rowname == 'mssql':
                    rowdata[name] = text=self.model.count(name)
                if rowname == 'done_total':
                    rowdata[name] = text=self.model.count_done_total(name)
                if rowname == 'done_now':
                    rowdata[name] = text=self.model.count_done_now(name)
        self.update_labels(labeldata)

    def wm_title(self, title, url=None):
        title += ' v' + VERSION
        if url is not None:
            title += ' [%s]' % url
        self.win.wm_title(title)

    def join_exit(self):
        if self.uploader.is_alive():
            lo.info('still waiting for uploader thread to shut down')
            self.win.after(1000, self.join_exit)
        else:
            lo.info('uploader thread finished, exiting')
            sys.exit(0)

    def initiate_join_exit(self):
        if self.uploader.is_alive():
            self.uploader.should_stop = True
            lo.info('waiting for uploader thread to shut down')
            self.win.after(1000, self.join_exit)
        else:
            sys.exit(0)


class FakeTable:
    pass

import random
class FakeModel:

    def __init__(self):
        self.tables = {
                'test results': FakeTable(),
                'analyte data': FakeTable()
            }

    def count(self, name):
        return random.randint(100,10000)
    def count_done_total(self, name):
        return random.randint(100,10000)
    def count_done_now(self, name):
        return random.randint(100,10000)


## upload {{{1

class UploadThread(threading.Thread):
    """Background thread uploading data form the database"""

    def __init__(self, client, model, interval, dryrun, username, password):
        threading.Thread.__init__(self)
        self.daemon = False

        self.client = client
        self.username = username
        self.password = password
        self.model = model
        self.interval = interval
        self.dryrun = dryrun
        self.callbacks = []

        self.should_stop = False

    def add_callback(self, callback):
        if not callback in self.callbacks:
            self.callbacks.append(callback)

    def notify(self):
        for callback in self.callbacks:
            callback()

    def try_connect(self):
        if self.client.is_connected():
            return True

        try:
            client.connect(self.username, self.password)
            return True
        except AggregateException as e:
            lo.error('could not connect : ' + str(e))
            return False
        except Exception as e:
            lo.error('unexpected error while connecting : ' + str(e))
            log_e(lo)
            return False

    def try_send(self, table, row):
        xform = table.fill_xform(row)
        try:
            self.client.post_multipart(xform.get_items())
            return True
        except AggregateException as e:
            lo.error('could not connect : ' + str(e))
            return False
        except Exception as e:
            lo.error('unexpected error while connecting : ' + str(e))
            log_e(lo)
            return False

    def run(self):
        n = 0
        while not self.should_stop:

            lo.debug('uploader running n=%d' % n)

            if not self.dryrun and not self.try_connect():
                lo.info('cannot connect; wait for 1 minute')
                for sec in range(60):
                    time.sleep(1)
                    if self.should_stop:
                        break
                continue

            for name in sorted(self.model.tables):

                if self.should_stop:
                    break

                table = self.model.tables[name]

                # be quiet when polling data apart from first time
                if n == 0:
                    lo.info('sync data with MS-SQL table "%s"' % name)

                row = self.model.get_next_new(name)
                while row is not None:

                    if self.should_stop:
                        break

                    rowname = table.rowname(row)
                    if self.dryrun:
                        lo.info('would upload form %s from table %s' % (
                                rowname, name))
                        time.sleep(1)
                    else:
                        if self.try_send(table, row):
                            lo.info('uploaded form %s from table %s' % (
                                    rowname, name))
                            self.model.mark_done(name, row)
                            self.notify()

                    row = self.model.get_next_new(name)

            seconds = self.interval
            while seconds > 0 and not self.should_stop:
                time.sleep(1)
                seconds -= 1

            n += 1


## main {{{1

if __name__ == '__main__':

    import sys, traceback

    if len(sys.argv) == 3 and sys.argv[1] == 'create_db':
        if os.path.exists(sys.argv[2]):
            print("refusing to overwrite file '%s'" % sys.argv[2])
        else:
            print("creating empty database '%s'" % sys.argv[2])
            MsSqlModel.create_table(sqlite3.connect(sys.argv[2]))
        sys.exit(0)

    lo.handlers = [] # for use with iPython
    init_log(lo, filename='mssql_uploader.log', debug_filename='mssql_uploader_debug.log')

    try:
        if len(sys.argv) > 1:
            configfile = sys.argv[1]
        else:
            configfile = 'mssql_uploader.json'
        config = Config(configfile)

    except ConfigException as e:
        guierror('Could not load config file:\n' + str(e), 'Config File Error')

    try:
        conn = pypyodbc.connect(
                driver='{SQL Server}',
                server=config.mssql.server,
                database=config.mssql.database,
                uid=config.mssql.username,
                pwd=config.mssql.password
            )
    except pypyodbc.DatabaseError as e:
        guierror('Could not connect to SQL database:\n' + str(e),
                'Database Error')

    try:
        model = MsSqlModel(conn=conn,
                sqls=config.sqls, rowids=config.rowids, rownames=config.rownames,
                sqlite_fname=config.sqlitedb, xforms=config.xforms)
    except (MsSqlModelException, SqlTableException) as e:
        guierror('Could not load MS-SQL model:\n' + str(e),
                'SQL Error')


    client = AggregateClient(
            config.odk.hostname, config.odk.port, config.odk.path,
            scheme=config.odk.scheme)

    uploader = UploadThread(
            client=client,
            model=model,
            interval=config.interval, dryrun=config.dryrun,
            username=config.odk.username, password=config.odk.password
        )
    gui = MainGui(model, config, uploader)
    gui.wm_title(config.title, url=config.odk.hostname)
    uploader.start()

    tk.mainloop()

# vim: fdm=marker

