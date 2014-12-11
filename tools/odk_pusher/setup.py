
from distutils.core import setup
import py2exe

setup(
        windows=['mssql_uploader.py', 'xray_uploader.py'],
        console=['aggregate.py']
    )
