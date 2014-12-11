"""Some shared GUI classes (Python 3)

:py:mod:`xray_uploader` as well as :py:mod:`xpert_uploader`
both use some of this module's functionality in their
graphical user interface
"""

import sys
import tkinter as tk, tkinter.messagebox


class ScrolledListbox(tk.Frame):
    """access listbox through :py:attr:`listbox`"""

    def __init__(self, parent, *args, **kwargs):
        tk.Frame.__init__(self, parent, *args, **kwargs)
        yscroll = tk.Scrollbar(self, orient=tk.VERTICAL)
        yscroll.pack(side=tk.RIGHT, fill='y')
        self.listbox = tk.Listbox(self, yscrollcommand=yscroll.set,
                selectmode='multiple')
        self.listbox.pack(side=tk.RIGHT, fill=tk.BOTH, expand=1)
        yscroll['command'] = self.listbox.yview


def resize_center(root, child, dw=10, dh=10, bx=100, by=50):
    """Resize ``root`` to ``child`` with minimum borders ``bx`` and ``by``"""
    root.update()
    rootw = root.winfo_vrootwidth()
    rooth = root.winfo_vrootheight()

    w = min(child.winfo_width()  + dw, rootw - 2*bx)
    h = min(child.winfo_height() + dh, rooth - 2*by)

    root.geometry('%dx%d+%d+%d' % (
            w, h, (rootw - w)/2, (rooth - h)/2))


class FieldsGui:
    """Shows dialog with grid of text :py:class:`Tkinter.Text`
    
    The dialog is modal and the return from the constructor waits for
    the user to fill in values and either finish by pressing the cancel
    or the ok button.
    
    The data can the be recovered in the :py:attr:`data` field (which
    is set to ``None`` if the user clicked the cancel putton)"""

    #FIXME add scroll bar

    def __init__(self, root, ids, fields):
        """Show dialog and wait for answer

        :param Frame root: parent window
        :param array ids: list of ids that will be row headings of grid
        :param dict fields: key is field name and value is regular
            expression validating the field
        """
        self.win = tk.Toplevel()
        self.win.wm_title('Fill in fields')

        self.ids = ids
        self.fields = fields

        self.data = None

        # nested upper frame for horizontal & vertical scrollbars
        upper = tk.Frame(self.win)
        upper.pack(side='top', fill='both', expand=True)
        upper2 = tk.Frame(upper)
        upper2.pack(side='top', fill='both', expand=True)

        # add frame into canvas for scrollbar support
        self.gridcanvas = tk.Canvas(upper2, borderwidth=0)
        self.gridframe = tk.Frame(self.gridcanvas)
        self.gridvsb = tk.Scrollbar(upper2, orient='vertical',
                command=self.gridcanvas.yview)
        self.gridhsb = tk.Scrollbar(upper, orient='horizontal',
                command=self.gridcanvas.xview)
        self.gridcanvas.configure(yscrollcommand=self.gridvsb.set)
        self.gridcanvas.configure(xscrollcommand=self.gridhsb.set)

        self.gridvsb.pack(side='right', fill='y')
        self.gridhsb.pack(side='bottom', fill='x')
        self.gridcanvas.pack(side='left', fill='both', expand=True)
        self.gridcanvas.create_window((4,4), window=self.gridframe, anchor='nw',
                tags='self.gridframe')
        self.gridframe.bind('<Configure>', self.gridframe_configure)

        # populate grid header
        for i, name in enumerate(sorted(fields)):
            l = tk.Label(self.gridframe, text=name)
            l.grid(row=0, column=i+1, sticky='w')
            self.gridframe.columnconfigure(i+1, weight=1)

        # populate grid content
        self.entries = {}
        for i, id in enumerate(ids):
            l = tk.Label(self.gridframe, text=id)
            l.grid(row=i+1, column=0, sticky='w')

            self.entries[id] = {}
            for j, name in enumerate(sorted(fields)):
                e = tk.Entry(self.gridframe)
                e.grid(row=i+1, column=j+1, sticky='we')
                self.entries[id][name] = e

        # controls in lower frame
        controls = tk.Frame(self.win)
        #controls.grid(row=len(ids)+1, column=0, columnspan=len(fields)+1, sticky='we')
        controls.pack(side='top', fill='x')
        ok = tk.Button(controls, text='ok', command=self.ok)
        ok.pack(side='left')
        cancel = tk.Button(controls, text='cancel', command=self.cancel)
        cancel.pack(side='left')

        self.win.bind('<Return>', self.ok)
        self.win.bind('<Escape>', self.cancel)

        resize_center(self.win, self.gridframe, dw=30, dh=30)

        self.win.transient(root)
        self.win.grab_set()
        root.wait_window(self.win)

    def gridframe_configure(self, e):
        '''Reset the scroll region to encompass the inner frame'''
        self.gridcanvas.configure(scrollregion=self.gridcanvas.bbox('all'))

    def ok(self, x=None):
        data = {}
        for id in self.ids:
            data[id] = {}
            for name, validator in self.fields.items():
                value = self.entries[id][name].get()
                if not validator.match(value):
                    tkinter.messagebox.showerror('Invalid input',
                            'Row %s, column %s must match "%s"' % (
                            id, name, validator.pattern))
                    return
                data[id][name] = value

        self.win.destroy()
        self.data = data

    def cancel(self, x=None):
        self.win.destroy()


def guierror(message, title=None):
    """Creates tkinter standalone error window and exits afterwards

    :param str message: (multiline) message to display in the window's
        main widget. no word wrap will be performed
    :param str title: title of window
    :param bool fatal: whether to exit after showing the window
    """
    win = tk.Tk()
    if title is None:
        title = 'Error occurred'
    win.wm_title(title)

    msg = tk.Label(win, text=message, justify='left')
    msg.pack(side='top')
    #msg = tk.Text(win, width=40)
    #msg.tag_config('x', wrap='word', foreground='blue')
    #msg.insert('1.0', message, ('x,'))
    #msg.pack(side='top')
    #msg.config(state='disabled')

    def sysexit(e=None):
        sys.exit(0)
    ok = tk.Button(win, text='Ok', command=lambda: sys.exit(0))
    ok.pack()
    tk.mainloop()
    sys.exit(0)


if __name__ == '__main__':

    import sys, re

    if 'test-fields' in sys.argv:
        # make sure there are enough IDs to activate vertical scrollbar
        ids = ['8%04d' % i for i in range(1,100)]
        fields = {
                field: re.compile('[A-Z]*$')
                for field in 'fieldA fieldB fieldC fieldD fieldE'.split(' ')
            }
        root = tkinter.Tk()
        print('waiting for FieldsGui to return...')
        dialog = FieldsGui(root, ids, fields)
        if dialog.data is None:
            print('canceled')
        else:
            for y, row in dialog.data.items():
                for x, val in row.items():
                    if val:
                        print('(%s,%s)="%s"' % (y, x, val))

    if 'guierror' in sys.argv:
        import traceback
        st = '\n'.join([
                '%s:%s\n\t%s\n' % (x[0], x[1], x[3])
                for x in traceback.extract_stack()])
        print(st)
        guierror('This is the error message. It could even contain a '
                'stacktrace in the worst of worlds:\n\n' + st, 'Fatal Error Occurred')


