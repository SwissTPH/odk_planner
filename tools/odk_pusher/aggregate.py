"""For sending XForms and associated files to ODK Aggregate

This Python3 module provides some basic functionality to read an XForm XML
file, fill in some values and then send the form with any associated files to
an ODK Aggregate instance.

This module uses the standard logger (i.e. ``logging.getLogger()``) for output
of diagnostic and debug messages.

Synopsis
--------

>>> from aggregate import XForm, AggregateClient
>>> import io
>>> form = XForm(io.open('TBDAR_CRFX.xml').read())
>>> form['patient_id'] = '80001-31'
>>> form.set_file('xray_image', 'images/80001-31.JPG')
>>> client = AggregateClient('aggregate.example.org', port=443,
...                          uri='/ODKAggregate', scheme='https')
>>> client.connect()
>>> client.connect(user='user1', password='password1')
>>> client.post_multipart(form.get_items())


Changelog
---------

  - version 1.0.1
    - some improved log messages
  - version 1.0.2
    - added client.is_connected()
  - version 1.0.3
    - added AggregateFormNotFoundException
"""

VERSION = '1.0.3'

from log import lo

import http.client, urllib.request, urllib.parse, urllib.error, sys, time, uuid, hashlib, io, mimetypes, os.path, json, datetime, csv
from xml.dom.minidom import parseString
from xml.parsers.expat import ExpatError


### AggregateClient & DAA {{{1

class AggregateException(Exception):
    """Risen by AggregateClient"""

class AggregateFormNotFoundException(AggregateException):
    """Raised when server returns 404"""

class AuthenticationException(AggregateException):
    """Risen by DAA"""

class DAA:
    """Digest Access Authentication (RFC 2069)
    
    Implements the DAA for authentication with an ODK Aggregate server.
    """

    def __init__(self, www_authenticate, username, password, cnonce=None):
        """Initialize form an answer provided by the server

        Arguments:
            - www_authenticate -- the "www-authenticate" header value from
              the server's HTTP response
            - username -- username for authentication
            - password -- password for authentication
            - cnonce (optional) -- client nonce
        """

        if not www_authenticate.startswith('Digest '):
            raise AuthenticationException('expected www-authenticate '
                    'header to start with "Digest "')
        www_authenticate = www_authenticate[7:]

        self.www_auth = dict([
                (_[:_.index('=')], _[_.index('=') + 2:-1])
                for _ in www_authenticate.split(', ')
            ])

        self.username = username
        self.password = password

        self.nc = 1
        if cnonce is None:
            cnonce = uuid.uuid4().hex
        self.cnonce = cnonce

    def get_authentication(self, method, uri):
        """Create new authentication token

        Arguments:
            - method -- HTTP method
            - uri -- URI for which authentication will be valid

        Return value: value that can be used for the 'Authorization'
            HTTP header
        """

        realm = self.www_auth['realm']
        snonce = self.www_auth['nonce']
        qop = self.www_auth['qop']

        nc = '%08d' % self.nc
        self.nc += 1

        HA1 = hashlib.md5(':'.join([self.username, realm, self.password]).encode('utf8')).hexdigest()
        HA2 = hashlib.md5(':'.join([method, uri]).encode('utf8')).hexdigest()
        HAx = hashlib.md5(':'.join([HA1, snonce, nc, self.cnonce, qop, HA2]).encode('utf8')).hexdigest()

        lo.debug('DAA : username=%s realm=%s => HA1=%s' % (self.username, realm, HA1))
        lo.debug('DAA : method=%s uri=%s => HA2=%s' % (method, uri, HA2))
        lo.debug('DAA : snonce=%s nc=%s cnonce=%s qop=%s => response=%s' % (
                snonce, nc, self.cnonce, qop, HAx))

        auth = {
                'username': self.username,
                'realm': realm,
                'nonce': snonce,
                'uri': uri,
                'response': HAx,
                'cnonce': self.cnonce,
                'algorithm': 'MD5',
                'nc': nc,
                'qop': qop,
            }

        auth = ', '.join([
                '%s="%s"' % (key, value)
                for key, value in auth.items()
            ])

        lo.debug('DAA response : ' + auth)

        return 'Digest ' + auth


class AggregateClient:
    """Aggregate client for posting forms
    
    Provides functionality to send xml data and other files to an
    ODK Aggregate server
    """

    def __init__(self, address, port, uri, scheme='https', deviceID=None):
        """Initializes client (does not connect yet)

        Arguments:
            - address -- ODK Aggregate server address
            - port -- ODK Aggregate server port
            - uri -- URI where ODKAggregate is rooted (e.g. '/ODKAggregate')
            - scheme (optional) -- must be 'http' (no SSL, default) or 'https' (SSL)
            - deviceID (optional) -- to identify client device
        """
        self.scheme = scheme
        self.address = address
        self.port = port
        if uri[0] != '/':
            uri = '/' + uri
        self.uri = uri

        self.submission_uri = self.uri + '/submission'
        if deviceID:
            self.submission_uri += '?deviceID=' + str(deviceID)
        self.url = 'http://%s:%d%s' % (address, port, uri)
        self.submission_url = '%s://%s:%d%s' % (
                scheme, address, port, self.submission_uri)

        self.conn = None

    def create_headers(self, additional_headers=None):
        if additional_headers:
            ret = dict(additional_headers)
        else:
            ret = dict()

        datestr = time.strftime("%a, %d %b %Y %H:%M:%S %Z", time.localtime())
        tz = time.timezone
        datestr += " GMT" + (tz < 0 and "-" or "+") + "%02d:%02d" % (tz/3600, tz/60)

        ret['X-OpenRosa-Version'] = '1.0'
        ret['Date'] = datestr
        ret['Connection'] = 'Keep-Alive'

        return ret

    def encode_multipart(self, items):
        boundary = '----------------AggregateClient' + uuid.uuid4().hex
        content_type = 'multipart/form-data; boundary=' + boundary
        body = []

        for name, filename, value, file_content_type in items:
            body += ['--' + boundary]
            body += ['Content-Disposition: form-data; name="%s"; filename="%s"' % (name, filename)]
            body += ['Content-Type: %s' % file_content_type]
            body += ['Content-Transfer-Encoding: binary']
            body += ['', value]

        body += ['--' + boundary + '--', '']

        return content_type, b'\r\n'.join([
                type(element) == str and element.encode('utf8') or element or b''
                for element in body])

    def request(self, method, uri, data, additional_headers=None):
        headers = self.create_headers(additional_headers)
        if self.daa:
            headers['Authorization'] = self.daa.get_authentication(
                method, uri)
        self.conn.request(method, uri, data, headers)


    def connect(self, user=None, password=None):
        """Connect to ODK Aggregate server

        Connects to server, performing initial authentication and 
        raising AggregateException in case of error.  If the initial
        request was successful, the attribute .conn will be set to a
        value different fron None.

        Arguments:
            - user (optional) -- username to use for authentication
            - password (optional) -- password to use for authentication
        """

        if self.scheme == 'https':
            self.conn = http.client.HTTPSConnection(self.address, self.port)
            #TODO check against provided certificate
            lo.info('SSL : server certificate NOT checked')
        else:
            self.conn = http.client.HTTPConnection(self.address, self.port)

        self.daa = None
        # raises ConnectionRefusedError
        self.request('HEAD', self.submission_uri, '')
        r = self.conn.getresponse()
        r_body = r.read()

        #cookie = r.getheader('Set-Cookie')
        #if cookie:
        #    cookie = cookie[:cookie.index(';')]

        lo.debug("HEAD %s : status=%d reason=%s" % (
                self.submission_url, r.status, r.reason))

        # anonymous user has Data Collector rights -> status=204
        if r.status == 204:
            self.daa = None
            lo.info('connected to %s (no authentication)' % self.url)

        # anonymous user has no Data Collector rights -> status=401
        elif r.status == 401:
            lo.info('Aggregate replied status=401 -> digest access authentication (DAA)')

            if user is None or password is None:
                raise AggregateException('Must specify user/password for authentication')

            self.daa = DAA(r.getheader('www-authenticate'), user, password)

            #headers = create_headers(cookie)
            self.request('HEAD', self.submission_uri, '')
            r = self.conn.getresponse()
            r_body = r.read()

            lo.debug("server response DAA : status=%d reason=%s" % (r.status, r.reason))

            if r.status == 401:
                lo.error('cannot authenticate : received second 401 response')
                raise AggregateException('Cannot authenticate')

            if r.status != 204:
                lo.error('expected status=204 (got %d) after authentication' %
                        r.status)
                if r.status == 403:
                    raise AggregateException(
                            'user "%s" is not allowed to post forms' % user)
                raise AggregateException('cannot authenticate')

            lo.info('connected to %s (authenticated as "%s")' % (self.url, user))

        elif r.status == 404:
            raise AggregateException('Could not connect : path "%s" not found' %
                    self.uri)

        else:
            raise AggregateException('Could not connect : unknown status')

    def is_connected(self):
        """checks whether there is a working connection"""
        if self.conn is None or self.conn.sock is None:
            return False
        try:
            self.conn.request('HEAD', '/ODKAggregate', '')
            r = self.conn.getresponse()
            return True
        except http.client.CannotSendRequest:
            return False

    def close(self):
        """closes the server connection"""
        self.conn.close()
        self.conn = None
    
    def post_multipart(self, items):
        """Post items to server

        Sends the specified items to the server, raising an
        AggregateException in case of error.

        Arguments:
            - items -- a sequence of sequences 
              ``(name, filename, value, file_content_type)``
              such as returned by XForm.get_items()
        """
        content_type, body = self.encode_multipart(items)
        self.request('POST', self.submission_uri, body, {
                'Content-Type': content_type,
                'Content-Length': len(body)
            })
        r = self.conn.getresponse()
        r_body = r.read()

        lo.debug('POST %s -> status=%d reason=%s data="%s"' % (
            self.submission_uri, r.status, r.reason, r_body))

        if r.status == 404:
            lo.error('could not find form with specified id')
            lo.debug('response body : ' + r_body.decode('utf8'))
            raise AggregateFormNotFoundException('Form not found on server')

        if r.status != 201:
            lo.error('expected status=201 after posting, got ' + str(r.status))
            lo.debug('response body : ' + r_body.decode('utf8'))
            raise AggregateException('Could not post multipart')


### XForm {{{1

class XFormException(Exception):
    """Risen by XForm"""

class XForm:
    """Simple XForm parser for use with AggregateClient

    Only a small part of the XForm standard is implemented that
    is sufficient to parse XForms, fill in some values and generate
    the data to be sent via AggregateClient
    """

    def __init__(self, xml):
        """Initializes from a XML string

        Arguments:
            - xml -- file content of an XForm XML file
        """
        try:
            self.document = parseString(xml)
        except ExpatError as e:
            raise XFormException('could not parse XML : ' + str(e))

        instance = self.get_path(('h:html', 'h:head', 'model', 'instance'))
        forms = [child for child in instance.childNodes
                if child.nodeType == self.document.ELEMENT_NODE]
        if len(forms) != 1:
            raise XFormException('no unique form instance found')

        self.template = forms[0]
        self.name = self.template.nodeName
        formid = self.template.attributes.get('id')
        if not formid:
            raise XFormException('instance %s has no id attribute' % self.name)
        self.formid = formid.value

        #TODO implement 'required' and 'constraint'

        self.paths = []
        self.add_paths(self.template, tuple())

        self.clear()

        lo.debug('loaded XForm %s "%s" : %d paths' % (
            self.name, self.formid, len(self.paths)))

    def add_paths(self, element, path):
        if path:
            self.paths.append('/'.join(path))
        for child in element.childNodes:
            if child.nodeType == self.document.ELEMENT_NODE:
                self.add_paths(child, path + (child.nodeName,))

    def get_path(self, path):
        node = self.document
        for element in path:
            candidates = [child for child in node.childNodes
                    if child.nodeName == element]
            if len(candidates) == 0:
                raise XFormException('path "%s" (element "%s") not found' % (
                        str(path), element))
            if len(candidates) > 1:
                raise XFormException('path "%s" (element "%s") not unique' % (
                        str(path), element))
            node = candidates[0]
        return node

    def clear(self):
        self.items = {}
        self.filenames = {}
        self.mimetypes = {}
        self['meta/instanceID'] = 'uuid:' + str(uuid.uuid4())

    def __setitem__(self, name, value):
        """Set value of a XForm field

        Raises XFormException if no field with given name can be found
        in this form.

        Arguments
            - name -- name of field, path parts separated by '/'
            - value -- value; int, float, date, time, and datetime will be
              converted to string; None will forestall the sending of
              this path
        """
        if not name in self.paths:
            raise XFormException('path "%s" not found in form "%s"' % (
                name, self.name))

        if value is None:
            return

        if isinstance(value, int):
            value = str(value)
        elif isinstance(value, float):
            value = str(value)
        elif isinstance(value, datetime.time):
            value = value.strftime('%H:%M:%S.0')
        elif isinstance(value, datetime.date):
            value = value.strftime('%Y-%m-%d')
        elif isinstance(value, datetime.datetime):
            value = value.strftime('%Y-%m-%d %H:%M:%S.0')

        if not isinstance(value, str):
            raise XFormException('cannot convert value : ' + str(value))
        self.items[name] = value
        lo.debug('setting %s[%s] = %s' % (self.formid, name, value))

    def set_file(self, name, filename, mimetype=None):
        """Set value of a XForm "file type" field

        Raises XFormException if no field with given name can be found
        in this form.

        Arguments:
            - name -- name of field, path parts separated by '/'
            - filename -- name of file to be sent as value of field
            - mimetype (optional) -- will be used as the file's
              "Content-Type"
        """
        if mimetype is None:
            mimetype = mimetypes.guess_type(filename)[0]
            if mimetypes is None:
                mimetype = 'application/binary'

        self[name] = os.path.basename(filename)
        self.filenames[name] = filename
        self.mimetypes[name] = mimetype
        lo.debug('(mimetype %s)' % mimetype)

    def fill_in(self, element, path):
        if path and element.attributes:
            # remove all attributes apart from root element (has "id")
            for name in list(element.attributes.keys()):
                element.removeAttribute(name)
        for child in element.childNodes:
            self.fill_in(child, path + (child.nodeName,))
        name = '/'.join(path) 
        if name in self.items:
            text = self.document.createTextNode(self.items[name])
            element.appendChild(text)

    def xml(self):
        """Dump form content as XML"""
        ret = self.template.cloneNode(deep=True)
        self.fill_in(ret, tuple())
        return '<?xml version="1.0" ?>' + ret.toxml()

    def get_items(self):
        """Encode form and files for use with AggregateClient.post_multipart"""
        form_content = self.xml()
        timestamp = time.strftime('%Y-%m-%d_%H-%M-%S', time.localtime())
        form_fname = self.formid + '_' + timestamp + '.xml'

        return [
                (
                    'xml_submission_file',
                    form_fname,
                    form_content,
                    'text/xml'
                )
            ] + [
                (
                    self.items[name],
                    self.items[name],
                    io.open(filename, 'rb').read(),
                    self.mimetypes[name]
                ) for name, filename in self.filenames.items()
            ]


### command line interface {{{1

if __name__ == '__main__':

    from log import lo, init_log, DEBUG, INFO
    init_log(lo)

    import argparse

    parser = argparse.ArgumentParser(description=
            'scriptable communication with ODK Aggregate server v' + VERSION)

    parser.add_argument('--debug', '-d', help='show debug output', action='store_true')
    parser.add_argument('--username', '-u', help='username for login', default=None)
    parser.add_argument('--password', '-p', help='password for login', default=None)

    parser.add_argument('--server', '-s', required=True,
            help='complete URL of server in the form ' +
            'http[s]://server.com[:port]/ODKAggregate (assumes port ' +
            '80 for http and 443 for https if not specified)')

    parsers = parser.add_subparsers(
            title='subcommands',
            description='specify type of interaction with server',
            help='specify subcommand')

    parser_post = parsers.add_parser('post', help='post form(s)')
    parser_post.set_defaults(command='post')
    parser_post.add_argument('--value', '-v', action='append', default=list(),
            nargs=2, metavar=('NAME', 'VALUE'),
            help='set field specified by NAME to VALUE before submission' +
            '(can be specified any number of times); an ODK form with a group ' +
            'called GROUP containing a field called FIELD would result in the ' +
            'NAME="GROUP/FIELD"; e.g. a date is specified like this "1980-01-1"') 
    parser_post.add_argument('--file', '-f', action='append', default=list(),
            nargs=2, metavar=('NAME', 'FILE'),
            help='set field specified by NAME to FILE before submission' +
            '(use this e.g. for image subma ission)') 

    parser_post.add_argument('--json', '-j', action='append', default=list(),
            help='read values (see -v and -f) from .json file specified; ' +
            'can be specified multiple times and combined with subsequent ' +
            '-v and -f switches (last overrides); any value is interpreted ' +
            'as filename if file exists')
    parser_post.add_argument('--csv', '-c', 
            help='read data from a .csv file; the NAME values are read from the ' +
            'first row (header) and every further row specifies the data for ' +
            'one form; see under -v for signification of NAME');

    parser_post.add_argument('--xform', '-x', required=True, help='Xform to post')

    args = parser.parse_args()
    lo.setLevel(not args.debug and INFO or DEBUG)


    url = urllib.parse.urlparse(args.server)
    port = url.port or (url.scheme == 'https' and 443 or 80)
    hostname = url.netloc
    if ':' in url.netloc:
        hostname = url.netloc[:url.netloc.index(':')]
        port = int(url.netloc[url.netloc.index(':')+1:])
    client = AggregateClient(hostname, port, url.path, scheme=url.scheme)
    client.connect(args.username, args.password)


    if args.command == 'post':

        with io.open(args.xform) as fd:
            form_xml = fd.read()
            form = XForm(form_xml)

        if args.csv:

            # post multiple forms

            with io.open(args.csv) as csvfd:

                reader = csv.reader(csvfd)
                header = next(reader)

                idxs = {}
                for i, name in enumerate(header):
                    if name in form.paths:
                        idxs[name] = i
                    else:
                        lo.error('field "%s" not found in form "%s" -> IGNORING',
                                   name, args.xform)
                        sys.exit(-1)

                for row in reader:
                    form = XForm(form_xml)
                    for name in idxs:
                        form[name] = row[idxs[name]]

                    client.post_multipart(form.get_items())
                    lo.info('successfully posted form %s, "%s"',
                            args.xform, row[0])

        else:

            # post single form

            for fname in args.json:
                with io.open(fname) as fd:
                    defaults = json.load(fd)
                for name, value in defaults.items():
                    if os.path.isfile(value):
                        form.set_file(name, value)
                    else:
                        form[name] = value

            for name, value in args.value:
                form[name] = value
            for name, filename in args.file:
                form.set_file(name, filename)

            client.post_multipart(form.get_items())
            lo.info('successfully posted form ' + args.xform)

# vim: fdm=marker

