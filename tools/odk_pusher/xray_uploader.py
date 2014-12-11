"""(Automated) upload of Xray images to ODK Aggregate

This Python3 GUI program uses the ``aggregate`` module to upload new images
from a directory to an ODK Aggregate server. See the documentation
in odk_planner:

http://odk_planner.readthedocs.org/en/latest/tools.html#xray_uploader

By default, the configuration file ``xray_uploader.json`` is loaded
from the local directory, but this configuration file can be superseeded
by a command line argument.

changelog
---------

  - version 1.0.1
    - XrayStore intermediary subdirectory created automatically
    - better config checking (server)
    - some more verbose log output
    - rescan image directory (every 'interval' minutes)
    - do NOT fallback on default configuration
  - version 1.0.2
    - create two log files ('xray_uploader.log' and 'xray_uploader_debug.log')
    - by default use file 'xray_uploader.json'
    - use guierror window if error during startup
"""

VERSION = '1.0.2'

import os.path, urllib.parse, threading, time, subprocess, re, uuid, sys, io, json, glob
import tkinter as tk, tkinter.font, tkinter.messagebox
from sre_constants import error as RegularExpressionException

from log import lo, LogFrame, init_log, log_e
from aggregate import AggregateClient, AggregateException, XForm, XFormException, VERSION as AGGREGATE_VERSION
from gui import ScrolledListbox, FieldsGui


### config {{{1

class ConfigException(Exception):
    """Risen by Config if configuration contains errors"""

class Config:
    """Parse a JSON configuration"""

    def __init__(self, filename):
        """Read JSON configuration from specified file

        Rises ConfigException if errors are encountered
        """
        try:
            data = json.load(io.open(filename))
        except ValueError as e:
            raise ConfigException('not valid JSON file : ' + str(e))

        def extract_key(key):
            if not key in data:
                raise ConfigException('missing key : ' + key)
            value = data[key]
            del data[key]
            return value

        server = extract_key('server')
        url = urllib.parse.urlparse(server)
        if not url.scheme or not url.hostname or not url.path:
            raise ConfigException('incomplete server address "%s"' % server +
                    ' : you must specify scheme (http/https), server, URI')
        self.scheme = url.scheme
        self.hostname = url.hostname
        self.port = url.port or (url.scheme == 'https' and 443 or 80)
        self.path = url.path

        self.username = extract_key('username')
        self.password = extract_key('password')

        self.xray_dir = extract_key('xray_dir')
        self.convert_executable = extract_key('convert_executable')
        try:
            subprocess.check_call([
                    self.convert_executable,
                    '--version'
                ])
        except subprocess.CalledProcessError as e:
            raise ConfigException('convert executable "%s" raised error : %s' % (
                self.convert_executable, str(e)))
        except OSError as e:
            if '[Errno 2]' in str(e):
                raise ConfigException('could not find executable "%s"' %
                        self.convert_executable)
        try:
            self.id_re = re.compile(extract_key('id_re'))
            self.manual_fields = {
                    key: re.compile(value)
                    for key, value in extract_key('manual_fields').items()
                }
        except RegularExpressionException as e:
            raise ConfigException('invalid regular expression : ' + str(e))

        self.xform = extract_key('xform')
        try:
            XForm(io.open(self.xform).read())
        except XFormException as e:
            raise ConfigException('could not load XForm "%s" : %s' % (
                    self.xform, str(e)))

        self.pixels = extract_key('pixels')

        self.auto = bool(extract_key('auto'))
        if self.auto and self.manual_fields:
            raise ConfigException('cannot perform automatic uploads ' +
                    'with non-empty manual_fields (%s)' %
                    ', '.join(self.manual_fields))
        try:
            interval = extract_key('interval')
            self.interval = float(interval)
        except ValueError:
            raise ConfigException('cannot parse interval "%s"' % interval)

        for key in data:
            lo.warning('ignoring config key : ' + key)


def load_config(candidates):
    """Returns first valid configuration (parsed using Config)"""
    for filename in candidates:
        try:
            config = Config(filename)
            lo.info('loaded config ' + os.path.abspath(filename))
            return config
        except ConfigException as e:
            lo.warning('failed to load "%s" : %s' % (filename, str(e)))

    lo.error('could not find any config file')
    return None


### models {{{1

class XrayStore:
    """Directory containing Xray images and preserving state"""

    done_fname = '_done.txt'
    intermediary = '_intermediary'

    def __init__(self, xray_dir, id_re=re.compile('.*')):

        self.xray_dir = xray_dir
        self.id_re = id_re

        if not os.path.isdir(xray_dir):
            lo.error('could not find xray directory "%s"' % xray_dir)
            return

        intermediary_dir = os.path.join(xray_dir, self.intermediary)
        if not os.path.isdir(intermediary_dir):
            os.mkdir(intermediary_dir)
            lo.info('created intermediary xray directory "%s"' % intermediary_dir)


        self.done = set()
        self.done_path = os.path.join(xray_dir, self.done_fname)
        if os.path.exists(self.done_path):
            self.done = set([
                    line.strip('\n\r')
                    for line in io.open(self.done_path).readlines()
                ])

        self.ignored = set()
        self.todo = set()
        self.update(initial=True)

    def update(self, initial=False):

        todo = set()
        # get list of files
        done_n = 0
        for fname in os.listdir(self.xray_dir):

            if os.path.isdir(self.path(fname)):
                continue

            if self.ignore(fname):
                continue

            if fname in self.ignored:
                continue

            if not self.image(fname):
                lo.warning('ignoring image "%s" : %s' % (fname, self.invalid(fname)))
                self.ignored.add(fname)
                continue

            if self.invalid(fname):
                lo.info('ignoring file "%s" (unknown extension)' % fname)
                self.ignored.add(fname)
                continue

            if fname in self.done:
                done_n += 1
            else:
                todo.add(fname)

        if len(todo) != len(self.todo):
            if initial:
                lo.info('scanned "%s" : %d files to upload (%d already done)' % (
                    os.path.abspath(self.xray_dir), len(todo), len(self.done)))
            else:
                lo.info('rescanned "%s" : %d new images' % (
                    os.path.abspath(self.xray_dir), len(todo) - len(self.todo)))
            self.todo = todo

    def intermediary_path(self, fname):
        return os.path.join(self.xray_dir, self.intermediary, fname)

    def path(self, fname):
        return os.path.join(self.xray_dir, fname)

    def ctime(self, fname):
        return os.path.getctime(self.path(fname))

    def ignore(self, fname):
        return fname[0] == '.' or fname[0] == '_'

    def image(self, fname):
        return os.path.splitext(fname.lower())[1] in (
                '.jpg', '.jpeg', '.tif', '.bmp', '.png'
            )

    def get_id(self, fname):
        return os.path.splitext(fname)[0]

    def invalid(self, fname):
        if self.id_re.match(self.get_id(fname)):
            return ''
        return 'invalid ID : "%s" does not match "%s"' % (
                self.get_id(fname), self.id_re.pattern)

    def mark_done(self, xray):
        assert xray in self.todo
        self.done.add(xray)
        self.todo.remove(xray)
        io.open(self.done_path, 'a').write(xray + '\n')


class XrayFormException(Exception):
    pass

class XrayForm:
    """Wraps aggregate.XForm and pre-processes images"""

    prefix = 'xray_'
    extension = '.jpg'
    mime = 'image/jpeg'

    def __init__(self, config, store, fname, field_data):

        self.config = config
        self.fields = config.manual_fields
        try:
            self.xform = XForm(io.open(config.xform).read())
        except Exception as e:
            raise XrayFormException('could not load XForm : ' + str(e))

        if store.invalid(fname):
            raise XrayFormException('invalid filename : ' + store.invalid(fname))

        self.store = store
        self.fname = fname
        self.fname2= os.path.splitext(fname)[0] + self.extension

        for name, validator in self.fields.items():
            if not name in field_data:
                raise XrayFormException('missing field : ' + name)
            if not validator.match(field_data[name]):
                raise XrayFormException('invalid field : ' +
                        '%s="%s" does not match "%s"' % (
                        name, field_data[name], validator.pattern))

        for key, value in field_data.items():
            self.xform[key] = value

        self.xform['patient_id'] = store.get_id(fname)
        self.xform['date'] = time.strftime('%Y-%m-%d',
                time.localtime())
        self.xform['xray_scan_date'] = time.strftime('%Y-%m-%d',
                time.localtime(store.ctime(fname)))
        self.xform.set_file('xray_image', self.path2())

    def path1(self):
        return self.store.path(self.fname)
    def path2(self):
        return self.store.intermediary_path(self.fname2)

    def process(self):
        src = self.path1()
        dst = self.path2()
        lo.debug('converting "%s" to "%s"' % (src, dst))
        subprocess.check_call([
                self.config.convert_executable,
                src,
                '-quality', '100',
                '-resize', '%d>' % self.config.pixels,
                dst
            ])

    def get_items(self):
        return self.xform.get_items()


### GUI {{{1

class UploadThread:
    """Background thread uploading XrayForm associated data"""
    
    def __init__(self, config, client, store, xrays, data):
        self.config = config
        self.client = client
        self.store = store
        self.xrays = list(xrays)
        self.data = data
        self.cancel = False

    def start(self, callback=None, fake=False):
        threading.Thread(target=fake and self.run_fake or self.run_wrapped,
                args=(callback, )).start() # not Daemon

    def run_fake(self, callback):
        import random, time
        while not self.cancel and self.xrays:
            lo.info('would start upload "%s" with data=%s' % (
                self.xrays[0], str(self.data[self.xrays[0]])))
            time.sleep(random.randint(3,5))
            lo.info('would have uploaded ' + self.xrays[0])
            self.store.mark_done(self.xrays[0])
            del self.xrays[0]
        if self.cancel:
            lo.info('upload canceled')
        callback()


    def run_wrapped(self, callback):
        try:
            self.run(callback)
        except Exception as e:
            lo.error('unexpected error : ' + str(e))
            log_e(lo)
            callback()

    def run(self, callback):

        try:
            self.client.connect(self.config.username, self.config.password)
        except AggregateException as e:
            lo.error('could not connect : ' + str(e))
            callback()
            return
        except Exception as e:
            lo.error('unexpected error while connecting : ' + str(e))
            log_e(lo)
            callback()
            return

        while not self.cancel and self.xrays:
            xray = self.xrays[0]
            data = self.data[self.xrays[0]]
            del self.xrays[0]
            try:
                form = XrayForm(self.config, self.store, xray, data)
                form.process()
                self.client.post_multipart(form.get_items())

                self.store.mark_done(xray)
                lo.info('uploaded image "%s" (%.2f kb)' % (
                    form.fname2, os.path.getsize(form.path2())/1024))

            except Exception as e:
                lo.error('could not upload image "%s" : %s' % (xray, str(e)))
                log_e(lo)

        if self.cancel:
            lo.info('upload canceled')

        callback()


def after(f):
    """Decorator that calls function with 1ms delay from GUI thread"""
    def g(self, *args):
        self.win.after(1, f, self, *args)
    return g


class MainGui:
    """Main window showing XrayStore content and log messages"""

    def __init__(self):

        self.win = tk.Tk()
        self.wm_title()
        self.win.protocol('WM_DELETE_WINDOW', self.confirm_exit)
        self.store = None
        self.client = None

        left = tk.Frame(self.win)
        left.grid(row=0, column=0, sticky='nsew')

        self.auto_label = tk.Label(left, text='(automatic update)')
        self.auto_label.pack()
        self.interval_id = None
        self.auto = tk.BooleanVar()
        self.auto.trace('w', self.update_auto)
        self.interval = tk.DoubleVar()

        self.xray_list = ScrolledListbox(left)
        self.xray_list.pack(fill=tk.BOTH, expand=1)
        self.button = tk.Button(left, text='upload selected', command=self.button_cb)
        self.button['state'] = 'disabled'
        self.button.pack()

        right = tk.Frame(self.win)
        right.grid(row=0, column=1, sticky='nsew')
        self.text = LogFrame(right, lo)
        self.text.pack(fill=tk.BOTH, expand=1)

        self.win.rowconfigure(0, weight=1)
        self.win.columnconfigure(0, weight=1)
        self.win.columnconfigure(1, weight=4)

        self.uploading = False
        self.upload_thread = None

        self.win.bind('<Return>', self.button_cb)
        def select_all(x=None):
            self.xray_list.listbox.selection_set(0, 'end')
        self.win.bind('<Control-a>', select_all)
        self.win.bind('<Command-a>', select_all)
        def select_none(x=None):
            self.xray_list.listbox.selection_clear(0, 'end')
        self.win.bind('<Control-n>', select_none)
        self.win.bind('<Command-n>', select_none)

    def set_store(self, store):
        self.store = store
        self.rebuild_list()

    def wm_title(self, url=None):
        title = 'X-ray ODK Uploader v' + VERSION
        if url is not None:
            title += ' [%s]' % url
        self.win.wm_title(title)

    def set_config(self, config):
        self.config = config
        self.interval.set(config.interval) # set interval before auto
        self.auto.set(config.auto)

    def set_client(self, client):
        if self.client is None:
            self.button['state'] = 'normal'
        self.client = client
        lo.debug('using aggregate version ' + str(AGGREGATE_VERSION))
        self.wm_title(client.url)

    def confirm_exit(self):
        if self.uploading:
            tkinter.messagebox.showinfo('Upload in progress',
                    'Please stop upload before quitting program')
        else:
            sys.exit(0)

    def rebuild_list(self):
        self.xray_list.listbox.delete(0, 'end')
        for xray in sorted(self.store.todo):
            self.xray_list.listbox.insert('end', xray)

    def button_cb(self, x=None):
        if self.uploading:
            self.button['state'] = 'disabled'
            self.button['text'] = 'cancelling upload...'
            self.upload_thread.cancel = True
        else:
            listbox = self.xray_list.listbox
            xrays = [listbox.get(int(i)) for i in listbox.curselection()]
            if not xrays:
                return

            data = { xray: dict() for xray in xrays }
            if self.config.manual_fields:
                dialog = FieldsGui(self.win, xrays, self.config.manual_fields)
                if dialog.data is None:
                    return
                data = dialog.data

            self.button['text'] = 'cancel upload'
            self.xray_list.listbox['state'] = 'disabled'
            self.start_upload(xrays, data)

    def start_upload(self, xrays, data):
        self.uploading = True
        self.upload_thread = UploadThread(self.config, self.client, self.store, xrays, data)
        self.upload_thread.start(self.upload_done)

    @after
    def upload_done(self):
        self.upload_thread = None
        self.uploading = False
        self.button['state'] = 'normal'
        self.button['text'] = 'upload selected'
        self.xray_list.listbox['state'] = 'normal'
        self.rebuild_list()
        self.set_next_interval()

    def set_next_interval(self):
        interval = self.interval.get()
        elf.interval_id = self.win.after(
                int(60 * 1000 * interval), self.interval_cb)

    def update_auto(self, *args):
        if self.interval_id is not None:
            self.win.after_cancel(self.interval_id)
            self.interval_id = None

        if self.auto.get():
            interval = self.interval.get()
            self.auto_label['text'] = 'auto upload every %d min' % interval
        else:
            self.auto_label['text'] = 'manual upload only'

        self.set_next_interval()

    def interval_cb(self):
        self.store.update()
        if self.auto.get():
            lo.info('starting automatic upload')
            self.xray_list.listbox.selection_set(0, 'end')
            self.button_cb()
        else:
            self.set_next_interval()


### main program {{{1

if __name__ == '__main__':

    lo.handlers = [] # for use with iPython
    init_log(lo, filename='xray_uploader.log', debug_filename='xray_uploader_debug.log')

    try:
        if len(sys.argv) > 1:
            configfile = sys.argv[1]
        else:
            configile = 'xray_uploader.json'
        config = Config(configfile)

    except ConfigException as e:
        guierror('Could not load config file:\n' + str(e), 'Config File Error')

    win = MainGui()

    win.set_store(XrayStore(config.xray_dir, config.id_re))
    win.set_config(config)

    client = AggregateClient(
            config.hostname, config.port, config.path,
            scheme=config.scheme)
    win.set_client(client)

    tk.mainloop()

# vim: fdm=marker

