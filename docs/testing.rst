
.. _testing:

Testing
=======

``odk_planner`` has testing suite that tests most of its features (including
:ref:`sending sms <sending-sms>` and :ref:`automatization <automatization>`).
The testing framework is based on the Python2_ package ``unittest`` that is
part of the standard distribution.  The tests themselves use Selenium_
WebDriver to test ``odk_planner`` functionality via a web browser.

All tests assume a precise environment that is the same as used for the
tutorial.  After :ref:`initial setup <demo-init>` this environment provides
a running :ref:`instance <install-instance>`, as well as ODK forms and data
that are used to test all features.

You then need to adapt the file ``test/sample.cfg`` that describes all
site-specific configuration, such as the phone number where test messages
should be sent.  The configuration can be saved under ``test/test.cfg`` or
under a arbitrary path specified by the environment variable
``ODK_PLANNER_CONFIG``.

Since all interaction with ``odk_planner`` passes through the web browser, the
tests can be run against an instance running on a remote server or against the
local installation.

The preferred way of running all the tests is by simply executing the script
``test/run.py`` that reads the config file checks connection with the web
server, uploads all forms, and then runs every test and reports the results:

.. literalinclude:: _static/testrun.txt

Tests can also be run individually like this (the following example runs
a single test that checks that a invalid login attempt generates a timeout of
two seconds):

::

  $ ODK_PLANNER_CONFIG=test/test.cfg python -m unittest -v test.test_login.TestLogin.test_login_failed
  test_login_failed (test.test_login.TestLogin) ... ok

  ----------------------------------------------------------------------
  Ran 1 test in 2.587s


.. _Python2: https://www.python.org/downloads/release/python-278/
.. _Selenium: http://www.seleniumhq.org

