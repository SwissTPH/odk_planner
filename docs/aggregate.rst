
.. _FAQ:

Questions & Answers
===================

This chapter contains varios bits of information that did not fit anywhere but
seem worthwile to be retained somewhere.


.. _aggregate-db:

ODK Aggregate database settings
-------------------------------

The ODK Aggregate database settings are stored in the file
``ODKAggregate-settings.jar`` that can be found in the subdirectory
``WEB-INF/lib`` of the ``webapps/ODKAggregate`` directory (e.g. under debian
this directory itself is located at ``/bar/lib/tomcat6``).  The ``.jar`` file
(which has the same `file format`_ as a ``.zip`` file) contains a file called
``jdbc.properties`` that stores the MySQL connection settings.

You can open this file to look up the databasse connection parameters (in case
you have lost the original ``create_db_and_user.sql`` that was created during
the ODK Aggregate installation), or modify it to use the same ODK Aggregate
instance to access a different database (e.g. for :ref:`testing <testing>`).
The following example is for debian:

.. code-block:: sh

  $ cd /var/lib/tomcat6/webapps/ODKAggregate/WEB-INF/lib/
  $ unzip -e ODKAggregate-settings.jar jdbc.properties
  Archive:  ODKAggregate-settings.jar
    inflating: jdbc.properties
  $ vim jdbc.properties
  $ zip -u ODKAggregate-settings.jar jdbc.properties
  updating: jdbc.properties (deflated 31%)
  $ /etc/init.d/tomcat6 restart
  Stopping Tomcat servlet engine: tomcat6.
  Starting Tomcat servlet engine: tomcat6.

.. _file format: https://en.wikipedia.org/wiki/JAR_%28file_format%29

