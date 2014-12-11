"""This Python3 module implements colored logging to console and tkinter.Text
"""

import sys, re, traceback, time, functools

import tkinter as tk, tkinter.font

from logging import getLogger, Formatter, FileHandler, StreamHandler, Handler
from logging.handlers import RotatingFileHandler
from logging import DEBUG, INFO, WARNING, ERROR

formatspec = '[%(asctime)s] -%(levelname)s- %(filename)s:%(lineno)d(%(funcName)s) :: %(message)s'
formatspec = '[%(asctime)s] -%(levelname)s- %(message)s'


class ColoredFormatter(Formatter):

    def format(self, record):
        ret = super(ColoredFormatter, self).format(record)

        reset = '\033[m'
        bold = '\033[1m'
        ok = '\033[92m' # fg=green
        error = '\033[97;101m' # fg=white bg=red

        m = re.match('(\\[.*?\\] )(-INFO-)( .*)', ret)
        if m:
            return m.group(1) + ok + m.group(2) + reset + m.group(3)
        m = re.match('(\\[.*?\\] )(-WARNING-|-ERROR-)( .*)', ret)
        if m:
            return m.group(1) + error + m.group(2) + reset + bold + m.group(3) + reset

        return ret


def after(f):
    """Decorator that calls function with 1ms delay from GUI thread"""
    def g(self, *args):
        self.text.after(1, f, self, *args)
    return g

class tkinterTextHandler(Handler):
    """Log handler that autoscrolls and adds colored log messages to tkinter.Text"""

    def __init__(self, text, yscroll, size):
        Handler.__init__(self)
        self.setLevel(DEBUG)
        self.display_debug = False
        self.display_filter = ''

        self.records = []
        self.text = text
        self.yscroll = yscroll
        self.size = size
        self.deleted = 0

        boldfont = tkinter.font.Font(font=text['font'])
        boldfont['weight'] = 'bold'

        text.tag_config('time', foreground='#888')

        text.tag_config('DEBUG_levelname', foreground='#888')
        text.tag_config('INFO_levelname', foreground='#080')
        text.tag_config('WARNING_levelname', background='red', foreground='white')
        text.tag_config('ERROR_levelname', background='red', foreground='white')

        text.tag_config('WARNING_message', font=boldfont)
        text.tag_config('ERROR_message', font=boldfont)

        text['state'] = 'disabled'

    def scrolldown(self):
        yy = self.yscroll.get()
        self.yscroll.set(1-yy[1]+yy[0], 1)
        self.text.yview('moveto', 1-yy[1]+yy[0])

    def emit(self, record):
        """All log messages are recorded for filtering"""
        self.records.append(record)
        self.do_emit(record)
        if len(self.records) > self.size:
            del self.records[0]
            self.deleted += 1
        if self.deleted > self.size:
            self.deleted = 0
            self.rebuild()

    def do_emit(self, record):

        try:
            self.text.insert('end', '')
        except:
            # window destroyed... handler *should* be removed in '<Destroy>' cb
            return

        if self.display_filter and self.display_filter not in record.message:
            return

        if record.levelno == DEBUG and not self.display_debug:
            return

        self.text['state'] = 'normal'
        self.text.insert('end', record.asctime[11:19] + ' ', ('time', ))
        ln = record.levelname
        self.text.insert('end', '[%s]' % (ln), (ln + '_levelname', ))
        self.text.insert('end', ' ' + record.message + '\n', (ln + '_message', ))
        self.text['state'] = 'disabled'

        self.scrolldown()

    @after
    def rebuild(self):
        self.text['state'] = 'normal'
        self.text.delete('1.0', 'end')
        for record in self.records:
            self.do_emit(record)

    def set_filter(self, filter):
        """Only show log entries containing specified text"""
        self.display_filter = filter
        self.rebuild()

    def set_debug(self, debug):
        """Whether debug messages should be shown"""
        self.display_debug = debug
        self.rebuild()


class LogFrame(tk.Frame):
    """tkinter.Frame showing autoscrolled, filterable and colored log output"""

    def __init__(self, parent, logger, size=100000, *args, **kwargs):
        """create new frame with log output and controls

        :param tk.Widget parent: parent window
        :param logging.logger logger: logger to attach handler to
        :param int size: how many logging messages should be stored in the
            logging handler; the logging text widget will be refreshed
            when this number of lines have been discarded; setting larger values
            will result in more log displayed in the widget but also in more
            lengthy freezed intervals when the log text content is regenerated
        """
        tk.Frame.__init__(self, parent, *args, **kwargs)

        top = tk.Frame(self)
        top.pack(fill='x')
        self.entry = tk.Entry(top)
        self.entry.pack(side='left', expand=1, fill='x')
        self.button_clear = tk.Button(top, text='clear', command=self.clear_cb)
        self.button_clear.pack(side='left')
        self.checkbutton_var = tk.IntVar(self)
        self.checkbutton = tk.Checkbutton(top, text='debug',
                command=self.debug_cb, variable=self.checkbutton_var)
        self.checkbutton.pack(side='left')

        bottom = tk.Frame(self)
        bottom.pack(expand=1, fill='both')
        self.text = tk.Text(bottom)
        self.text.pack(side=tk.LEFT, fill='both', expand=1)
        yscroll = tk.Scrollbar(bottom, orient=tk.VERTICAL,
                command=self.text.yview)
        yscroll.pack(side=tk.LEFT, fill='y')
        self.text.config(yscrollcommand=yscroll.set)

        self.handler = tkinterTextHandler(self.text, yscroll, size=size)
        logger.addHandler(self.handler)
        self.text.bind('<Destroy>', lambda x: logger.removeHandler(self.handler))

        self.entry['font'] = self.text['font']
        self.entry.bind('<Return>', self.filter_cb)
        self.entry.bind('<Escape>', self.clear_cb)

    def filter_cb(self, x=None):
        self.handler.set_filter(self.entry.get())

    def clear_cb(self, x=None):
        self.entry.delete(0, 'end')
        self.handler.set_filter(self.entry.get())

    def debug_cb(self, x=None):
        self.handler.set_debug(self.checkbutton_var.get())


def init_log(logger, filename=None, debug_filename=None, color=None, stderr=None):
    """Adds filehandler and (colored) stream handler to specified logger"""
    logger.setLevel(DEBUG)

    if color is None:
        color = sys.platform != 'win32' and \
                hasattr(sys.stderr, 'isatty') and \
                sys.stderr.isatty()

    # log to stderr by default if not executed from py2exe packaged executable
    if stderr is None:
        if hasattr(sys, 'frozen') and sys.frozen=='console_exe': # pylint: disable=E1101
            stderr = False
        else:
            stderr = True

    if filename:
        handler = FileHandler(filename)
        handler.setLevel(INFO)
        handler.setFormatter(Formatter(formatspec))
        logger.addHandler(handler)

    if debug_filename:
        handler = RotatingFileHandler(debug_filename, maxBytes=1024*1024)
        handler.setLevel(DEBUG)
        handler.setFormatter(Formatter(formatspec))
        logger.addHandler(handler)

    if stderr:
        handler = StreamHandler(sys.stderr)
        handler.setLevel(DEBUG)
        if color:
            handler.setFormatter(ColoredFormatter(formatspec))
        else:
            handler.setFormatter(Formatter(formatspec))
        logger.addHandler(handler)

def log_e(logger, level=DEBUG):
    """Output current exception traceback to specified logger"""
    tb_str = traceback.format_exc()
    for line in tb_str.split('\n'):
        logger.log(level, line)


lo = getLogger()


tictocs = {}
def tic(name):
    tictocs.setdefault(name, []).append([time.time()])
def toc(name):
    l = tictocs[name][-1]
    l.append(time.time())
    lo.debug('toc-tic %s : %.2f ms'%(name, 1e3*(l[1]-l[0])))

def tictoc(name):
    def decorator(f):
        @functools.wraps(f)
        def wrapper(*args, **kwargs):
            tic(name)
            ret = f(*args, **kwargs)
            toc(name)
            return ret
        return wrapper
    return decorator


